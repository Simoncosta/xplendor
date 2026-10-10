<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Services\InvoiceOcrService;
use App\Services\Ocr\OcrComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ocr:compare: a simulação não envia nada (mostra as faturas, as chamadas, o custo e as
 * chaves sem as mostrar); com --execute (aqui com respostas simuladas) mede cada modelo contra
 * a revisão gravada, sem o QR, sem alterar a fatura, e escreve o relatório fora do git.
 */
class OcrCompareTest extends TestCase
{
    use RefreshDatabase;

    private OcrInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['ai.providers.anthropic.key' => 'sk-ant-SEGREDO', 'services.openai.key' => 'sk-SEGREDO', 'ai.retry_backoff_ms' => [1, 1]]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Yuko', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->invoice = OcrInvoice::create(['company_id' => $company->id, 'image_path' => "ocr-invoices/{$company->id}/v.pdf", 'image_mime' => 'application/pdf',
            'status' => 'validada', 'supplier_nif' => '500829993', 'number' => 'FT 1', 'issue_date' => '2026-10-01']);
        Storage::disk('local')->put($this->invoice->image_path, '%PDF-1.7 /Type /Page fatura');
        OcrInvoiceLine::create(['ocr_invoice_id' => $this->invoice->id, 'company_id' => $company->id, 'position' => 0, 'item' => 'Arroz Agulha 5kg',
            'quantity' => 10, 'unit_price' => '4.200000', 'vat_rate' => 6, 'line_total_cents' => 4200]);
        OcrInvoiceSummary::create(['ocr_invoice_id' => $this->invoice->id, 'company_id' => $company->id, 'total_cents' => 4452, 'taxable_base_cents' => 4200,
            'vat_total_cents' => 252, 'vat_breakdown' => [['rate' => 6, 'base_cents' => 4200, 'vat_cents' => 252]]]);
        $this->app->instance(InvoiceOcrService::class, new class extends InvoiceOcrService {
            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                return ['ok' => true, 'kind' => 'pdf', 'pages' => 1, 'qr' => null, 'text' => '', 'text_chars' => 0,
                    'images' => $images === 'always' ? [['page' => 1, 'mime' => 'image/jpeg', 'base64' => 'eA==']] : []];
            }
        });
    }

    private function read(array $over = []): array
    {
        return array_replace_recursive([
            'fornecedor' => ['nome' => 'Recheio', 'nif' => '500829993'], 'numeroFatura' => 'FT 1', 'dataEmissao' => '2026-10-01',
            'linhas' => [['codigo' => null, 'item' => 'Arroz Agulha 5kg', 'quantidade' => 10, 'unidade' => 'UN', 'precoUnitario' => 4.2, 'descontoPct' => 0, 'totalLinha' => 42, 'taxaIva' => 6]],
            'sumario' => ['totalMercadorias' => 42, 'descontoComercial' => 0, 'baseTributavel' => 42, 'ivaPorTaxa' => [['taxa' => 6, 'base' => 42, 'iva' => 2.52]],
                'valorIvaTotal' => 2.52, 'retencaoFonte' => 0, 'descontoFinanceiro' => 0, 'total' => 44.52],
        ], $over);
    }

    public function test_the_simulation_sends_nothing_and_never_shows_the_keys(): void
    {
        Http::fake();
        $code = Artisan::call('ocr:compare', ['--folder' => '/nao/existe']);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertStringContainsString('Chamadas: 4 (1 faturas × 4 modelos)', $out);
        $this->assertStringContainsString('ANTHROPIC_API_KEY: configurada', $out);
        $this->assertStringContainsString('SIMULAÇÃO: nada foi enviado', $out);
        $this->assertStringNotContainsString('SEGREDO', $out);
        $this->assertStringContainsString('preço por confirmar', $out, 'o Fable sem preço');
        $this->assertSame([], Storage::disk('local')->allFiles('ocr-compare'));
    }

    public function test_execute_measures_each_model_without_the_qr_and_writes_the_report(): void
    {
        $text = fn (array $json) => ['type' => 'message', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($json)]], 'usage' => ['input_tokens' => 4000, 'output_tokens' => 900]];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($text($this->read()))
                ->push($text($this->read(['dataEmissao' => '2026-10-02'])))
                ->push(['type' => 'message', 'stop_reason' => 'refusal', 'content' => [], 'usage' => ['input_tokens' => 4000, 'output_tokens' => 3]]),
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->read(['linhas' => [['totalLinha' => 40]]]))]]], 'usage' => ['prompt_tokens' => 2000, 'completion_tokens' => 500]]),
        ]);
        $before = $this->invoice->fresh()->toArray();

        $this->assertSame(0, Artisan::call('ocr:compare', ['--execute' => true, '--folder' => '/nao/existe']));

        Http::assertSentCount(4);
        Http::assertSent(fn ($req) => ! str_contains(json_encode($req->data()), 'AUTO-CONFERÊNCIA'), 'sem o QR');
        $this->assertSame($before, $this->invoice->fresh()->toArray(), 'a fatura gravada não muda');
        $report = Storage::disk('local')->get('ocr-compare/COMPARACAO-MODELOS.md');
        $this->assertStringContainsString('| claude-fable-5-1 (esforço do modelo) | 1 | 0 | 100,0% |', $report);
        $this->assertStringContainsString('| claude-opus-5-5 (médio) | 1 | 0 | 85,7% |', $report, 'a data errada');
        $this->assertStringContainsString('| claude-opus-5-5 (alto) | 0 | 1 |', $report, 'a recusa conta como paragem');
        $this->assertStringContainsString('## Recomendação', $report);
        $this->assertCount(1, Storage::disk('local')->files('ocr-compare', false) ? array_filter(Storage::disk('local')->files('ocr-compare'), fn ($f) => str_ends_with($f, '.json')) : []);
    }

    public function test_the_measures_field_by_field_line_by_line_and_the_disagreements(): void
    {
        $svc = app(InvoiceOcrService::class);
        $truth = OcrComparison::truthFrom($this->invoice);
        $good = $svc->sanitize($this->read());
        $bad = $svc->sanitize($this->read(['fornecedor' => ['nif' => '999999990'], 'linhas' => [['quantidade' => 9, 'totalLinha' => 37.8]]]));

        $this->assertSame(7, count(array_filter(OcrComparison::fields($truth, $good))));
        $this->assertFalse(OcrComparison::fields($truth, $bad)['nif']);
        $lines = OcrComparison::lines($truth['lines'], $bad['lines']);
        $this->assertSame([1, 1, 0, 0], [$lines['emparelhadas'], $lines['campos']['descricao'], $lines['campos']['quantidade'], $lines['campos']['total']]);
        $diff = OcrComparison::disagreements(['A' => $good, 'B' => $bad]);
        $this->assertSame(['A' => '500829993', 'B' => '999999990'], $diff['NIF']);
        $this->assertArrayNotHasKey('Data', $diff);
    }
}
