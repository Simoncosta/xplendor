<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\User;
use App\Services\Ai\AiFunctionSettings;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OCR das faturas na Anthropic: o QR primeiro (sem IA), o ficheiro diretamente ao modelo (PDF
 * como documento, fotografia como imagem, sem citações), saídas estruturadas com o esquema de
 * hoje, a recusa e a resposta cortada para revisão manual (sem repetir), e o registo do
 * fornecedor, do modelo, do esforço, dos tokens, do tempo e do custo. Respostas simuladas.
 */
class InvoiceOcrAnthropicTest extends TestCase
{
    use RefreshDatabase;

    // QR de uma fatura da Yuko (adquirente 514148497): base 6% 60,40 € + IVA 3,62 €.
    private const QR = 'A:509442013*B:514148497*C:PT*D:FT*E:N*F:20260502*G:FT 2026/1234*H:JJ37M8RS-1234*I1:PT*I3:60.40*I4:3.62*N:3.62*O:64.02*Q:abcd*R:1234';

    private Company $yuko;
    private User $root;
    private ?array $analysis = null;
    public array $analyzeModes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'ai.providers.anthropic.key' => 'sk-ant-test', 'ai.retry_backoff_ms' => [1, 1],
            'services.openai.ocr.model_text' => 'gpt-4o-mini', 'services.openai.ocr.model_image' => 'gpt-6.1-sol',
            'services.openai.ocr.check_tolerance_cents' => 2, 'services.openai.ocr.text_min_chars' => 200,
        ]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Ferreira & Cambas, Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->yuko->id, 'restaurant');
        $xplendor = Company::create(['nipc' => '500100200', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
        $test = $this;
        $this->app->instance(InvoiceOcrService::class, new class($test) extends InvoiceOcrService {
            public function __construct(private $test) {}

            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                $this->test->analyzeModes[] = $images;

                return $this->test->analysis();
            }
        });
    }

    public function analysis(): array
    {
        return $this->analysis ?? [];
    }

    private function choose(string $function, string $model, string $effort): void
    {
        AiFunctionSettings::set($function, $model, $effort, $this->root);
    }

    private function invoice(string $mime, string $bytes = '%PDF-1.7 fatura'): OcrInvoice
    {
        $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
        $inv = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => "ocr-invoices/{$this->yuko->id}/f.{$ext}", 'image_mime' => $mime, 'status' => 'processing']);
        Storage::disk('local')->put($inv->image_path, $bytes);

        return $inv;
    }

    private function anthropic(array $json, string $stop = 'end_turn', int $in = 3000, int $out = 400): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'stop_reason' => $stop,
            'content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => json_encode($json)]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }

    public function test_a_pdf_with_qr_goes_as_a_document_with_the_lines_schema_and_the_reading_is_recorded(): void
    {
        $this->choose('ocr_text', 'claude-opus-5-5', 'medium');
        $this->choose('ocr_image', 'claude-opus-5-5', 'medium'); // as duas na Anthropic: nunca é preciso rasterizar
        $this->analysis = ['ok' => true, 'kind' => 'pdf', 'pages' => 1, 'qr' => ['raw' => self::QR, 'page' => 1], 'text' => str_repeat('Forma Redonda 60,40 ', 30), 'text_chars' => 600, 'images' => []];
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic(['fornecedorNome' => 'Padaria', 'guias' => [], 'linhas' => [
            ['codigo' => '101031', 'item' => 'Forma Redonda', 'quantidade' => 40, 'unidade' => 'UN', 'precoUnitario' => 1.51, 'descontoPct' => 0, 'totalLinha' => 60.40, 'taxaIva' => 6],
        ]]))]);
        $inv = $this->invoice('application/pdf');

        app(InvoiceOcrService::class)->process($inv->id);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $req) {
            $content = $req['messages'][0]['content'];

            return $req->hasHeader('x-api-key', 'sk-ant-test') && $req['model'] === 'claude-opus-5-5'
                && $content[0]['type'] === 'document' && $content[0]['source']['media_type'] === 'application/pdf'
                && ! isset($content[0]['citations']) && $content[1]['type'] === 'text' && str_contains($content[1]['text'], 'AUTO-CONFERÊNCIA')
                && $req['output_config']['effort'] === 'medium' && $req['output_config']['format']['type'] === 'json_schema'
                && array_keys($req['output_config']['format']['schema']['properties']) === ['fornecedorNome', 'linhas', 'guias'];
        });
        $this->assertSame(['never'], $this->analyzeModes, 'a Anthropic lê o PDF: o scraper não rasteriza');
        $inv->refresh();
        $this->assertSame(['por_validar', 'confere', 'qr+texto'], [$inv->status, $inv->check_status, $inv->source]);
        $this->assertSame(['anthropic', 'claude-opus-5-5', 'medium', 3000, 400], [$inv->ai_provider, $inv->model, $inv->ai_effort, $inv->tokens_in, $inv->tokens_out]);
        $this->assertEqualsWithDelta(3000 * 4 / 1e6 + 400 * 20 / 1e6, (float) $inv->cost_usd, 1e-6, 'custo pelos preços do catálogo');
        $this->assertNotNull($inv->duration_ms);
        $this->assertSame(['anthropic', 'pdf'], [$inv->attempts_log[0]['provider'], $inv->attempts_log[0]['input']]);
        $this->assertSame('6040', (string) $inv->lines()->value('line_total_cents'));
        $this->assertSame('514148497', $inv->buyer_nif, 'o cabeçalho vem do QR');
    }

    public function test_a_photo_without_qr_goes_as_an_image_with_the_full_schema(): void
    {
        $this->choose('ocr_image', 'claude-fable-5-1', 'default');
        $this->analysis = ['ok' => true, 'kind' => 'image', 'pages' => 1, 'qr' => null, 'text' => '', 'text_chars' => 0, 'images' => []];
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic([
            'fornecedor' => ['nome' => 'Recheio', 'nif' => '500829993'], 'numeroFatura' => 'FT 1', 'dataEmissao' => '2026-10-01',
            'linhas' => [['codigo' => null, 'item' => 'Arroz', 'quantidade' => 1, 'unidade' => 'UN', 'precoUnitario' => 10, 'descontoPct' => null, 'totalLinha' => 10, 'taxaIva' => 6]],
            'sumario' => ['totalMercadorias' => 10, 'descontoComercial' => null, 'baseTributavel' => 10, 'ivaPorTaxa' => [['taxa' => 6, 'base' => 10, 'iva' => 0.6]],
                'valorIvaTotal' => 0.6, 'retencaoFonte' => null, 'descontoFinanceiro' => null, 'total' => 10.6],
        ]))]);
        $inv = $this->invoice('image/jpeg', 'jpeg-bytes');

        app(InvoiceOcrService::class)->process($inv->id);

        Http::assertSent(fn (Request $req) => $req['messages'][0]['content'][0]['type'] === 'image'
            && $req['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg'
            && ! isset($req['output_config']['effort']) // "default": o esforço do modelo
            && in_array('sumario', $req['output_config']['format']['schema']['required'], true));
        $inv->refresh();
        $this->assertSame(['por_validar', 'sem_qr', '500829993', 1060], [$inv->status, $inv->check_status, $inv->supplier_nif, (int) $inv->summary->total_cents]);
        $this->assertSame(['anthropic', 'claude-fable-5-1', 'default'], [$inv->ai_provider, $inv->model, $inv->ai_effort]);
        $this->assertEqualsWithDelta(3000 * 10 / 1e6 + 400 * 50 / 1e6, (float) $inv->cost_usd, 1e-6, 'preços do Fable 5.1');
    }

    public function test_a_refusal_leaves_the_invoice_for_manual_review_without_retrying(): void
    {
        $this->choose('ocr_text', 'claude-opus-5-5', 'low');
        $this->choose('ocr_image', 'claude-opus-5-5', 'low');
        $this->analysis = ['ok' => true, 'kind' => 'pdf', 'pages' => 1, 'qr' => ['raw' => self::QR, 'page' => 1], 'text' => str_repeat('x', 600), 'text_chars' => 600, 'images' => []];
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'message', 'stop_reason' => 'refusal', 'content' => [], 'usage' => ['input_tokens' => 2500, 'output_tokens' => 5]])]);
        $inv = $this->invoice('application/pdf');

        app(InvoiceOcrService::class)->process($inv->id);

        Http::assertSentCount(1);
        $inv->refresh();
        $this->assertSame(['por_validar', 0], [$inv->status, $inv->lines()->count()]);
        $this->assertStringContainsString('revisão manual: a IA recusou ler esta fatura', (string) $inv->error_message);
        $this->assertSame(['FT 2026/1234', 2500, 5], [$inv->number, $inv->tokens_in, $inv->tokens_out], 'o cabeçalho do QR e os tokens gastos ficam');
        $this->assertSame('refusal', $inv->attempts_log[0]['error']);
    }

    public function test_a_cut_answer_is_also_for_manual_review_and_the_second_attempt_never_loops(): void
    {
        // 1.ª leitura com a OpenAI (reserva), 2.ª (não confere) na Anthropic, que fica cortada: fica a 1.ª.
        $this->choose('ocr_image', 'claude-opus-5-5', 'high');
        $this->analysis = ['ok' => true, 'kind' => 'pdf', 'pages' => 1, 'qr' => ['raw' => self::QR, 'page' => 1], 'text' => str_repeat('x', 600), 'text_chars' => 600, 'images' => []];
        config(['services.openai.key' => 'sk-test']);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['fornecedorNome' => 'X', 'linhas' => [
                ['item' => 'Forma', 'quantidade' => 1, 'precoUnitario' => 50, 'totalLinha' => 50, 'taxaIva' => 6]]])]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]]),
            'api.anthropic.com/*' => Http::response(['type' => 'message', 'stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"linhas": [']], 'usage' => ['input_tokens' => 3000, 'output_tokens' => 44000]]),
        ]);
        $inv = $this->invoice('application/pdf');

        app(InvoiceOcrService::class)->process($inv->id);

        Http::assertSentCount(2);
        $inv->refresh();
        $this->assertSame(['por_validar', 'nao_confere', 1], [$inv->status, $inv->check_status, $inv->lines()->count()]);
        $this->assertSame([2, 'gpt-4o-mini'], [$inv->attempts, $inv->model]);
        $this->assertStringContainsString('cortada', (string) $inv->attempts_log[1]['error']);
        $this->assertSame(44000, $inv->attempts_log[1]['tokens_out'], 'os tokens da tentativa cortada contam');
    }

    public function test_a_429_is_retried_a_few_times_then_the_invoice_is_an_error(): void
    {
        $this->choose('ocr_image', 'claude-opus-5-5', 'low');
        $this->analysis = ['ok' => true, 'kind' => 'image', 'pages' => 1, 'qr' => null, 'text' => '', 'text_chars' => 0, 'images' => []];
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limited']], 429)]);
        $inv = $this->invoice('image/jpeg', 'jpeg');

        try {
            app(InvoiceOcrService::class)->process($inv->id);
            $this->fail('devia falhar');
        } catch (\Throwable) {
        }
        Http::assertSentCount(3);
        $this->assertSame('erro', $inv->fresh()->status);
    }
}
