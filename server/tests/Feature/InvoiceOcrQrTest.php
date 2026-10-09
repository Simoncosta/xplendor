<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\PingwinSupplier;
use App\Models\User;
use App\Services\AtInvoiceQr;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — OCR de faturas F2a: QR PRIMEIRO + texto do PDF + modelo por via + conferência
 * pelo QR. QRs REAIS das faturas do spike (Carnes, Makro, Forno, Julho, a de outra empresa).
 * A OpenAI e o scraper são fakes (override callModel/analyzeFile) — sem rede.
 */
class InvoiceOcrQrTest extends TestCase
{
    use RefreshDatabase;

    // QRs reais lidos dos PDFs do spike (B = NIF da Yuko, 514148497).
    private const QR_CARNES = 'A:502811331*B:514148497*C:PT*D:FT*E:N*F:20260504*G:FD M501/1681*H:J6TBYMGN-1681*I1:PT*I3:1786.85*I4:107.21*I7:153.48*I8:35.30*N:142.51*O:2082.84*Q:YlRC*R:0001';
    private const QR_MAKRO = 'A:502030712*B:514148497*C:PT*D:FT*E:N*F:20260512*G:FAC 02002202601/024366*H:J6FCWGPS-024366*I1:PT*I3:1729.25*I4:103.76*I5:236.84*I6:30.79*I7:1041.62*I8:239.57*N:374.12*O:3381.83*Q:h0gr*R:1708';
    private const QR_FORNO = 'A:516182609*B:514148497*C:PT*D:FT*E:N*F:20260531*G:FT FAR.2026/379*H:J6TPXYDR-379*I1:PT*I3:1214.92*I4:72.90*N:72.90*O:1287.82*Q:gXHR*R:0030';
    private const QR_JULHO = 'A:517343355*B:514148497*C:PT*D:FT*E:N*F:20260706*G:1 2026/3*H:J6XYBSN7-3*I1:PT*I7:1504.07*I8:345.94*N:345.94*O:1850.01*Q:byI6*R:1137';
    private const QR_OTHER_COMPANY = 'A:517343355*B:515532436*C:PT*D:FT*E:N*F:20260915*G:1 2026/4*H:J6XYBSN7-4*I1:PT*I7:50.00*I8:11.50*N:11.50*O:61.50*Q:LpvF*R:1137';

    private Company $yuko;
    private User $user;
    /** @var object fake do serviço (regista as chamadas) */
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.openai.ocr.model_text'  => 'gpt-4o-mini',
            'services.openai.ocr.model_image' => 'gpt-6.1-sol',
            'services.openai.ocr.reasoning_effort' => 'low',
            'services.openai.ocr.retry_reasoning_effort' => 'medium',
            'services.openai.ocr.reasoning_model_prefixes' => 'o1,o3,o4,gpt-5,gpt-6',
            'services.openai.ocr.text_min_chars' => 200,
            'services.openai.ocr.check_tolerance_cents' => 2,
            'services.openai.ocr.prices' => 'gpt-4o-mini=0.15/0.60;gpt-6.1-sol=2.00/10.00',
        ]);
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Ferreira & Cambas, Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->yuko->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->yuko->id, 'role' => 'admin']);
    }

    // ───────────────────────────────────────────────────────────── helpers

    /**
     * Fake: $analysis é o que o scraper devolve; $responses é a fila de respostas da IA
     * (uma por chamada). Regista cada chamada (modelo, esforço, tipo de conteúdo).
     */
    private function bindFake(array $analysis, array $responses, ?array $alwaysAnalysis = null): void
    {
        $this->fake = new class($analysis, $responses, $alwaysAnalysis) extends InvoiceOcrService {
            public array $calls = [];
            public array $analyzeCalls = [];

            public function __construct(private array $analysis, private array $responses, private ?array $always) {}

            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                $this->analyzeCalls[] = $images;

                return $images === 'always' && $this->always ? $this->always : $this->analysis;
            }

            protected function callModel(string $model, string $system, array $userContent, ?string $effort): array
            {
                $this->calls[] = [
                    'model' => $model, 'effort' => $effort, 'system' => $system,
                    'kinds' => array_column($userContent, 'type'), 'text' => $userContent[0]['text'] ?? '',
                ];
                $r = array_shift($this->responses);

                return ['content' => json_encode($r['json']), 'tokens_in' => $r['in'] ?? 1000, 'tokens_out' => $r['out'] ?? 100];
            }
        };
        $this->app->instance(InvoiceOcrService::class, $this->fake);
    }

    private function pdfAnalysis(string $qr, int $textChars = 2600, array $images = []): array
    {
        return [
            'ok' => true, 'kind' => 'pdf', 'pages' => 2, 'qr' => ['raw' => $qr, 'page' => 2, 'dpi' => 200],
            'text' => "Fatura\n101031 Forma Redonda 40,00 UN 1,510 6,00 60,40\fpágina 2", 'text_chars' => $textChars,
            'copies_dropped' => [], 'kept_pages' => [1, 2], 'images' => $images, 'truncated' => false,
        ];
    }

    private function scanImages(int $n = 1): array
    {
        return array_map(fn ($i) => ['page' => $i, 'mime' => 'image/jpeg', 'base64' => base64_encode("img{$i}")], range(1, $n));
    }

    /** Linhas cujas somas por taxa são exatamente as dadas (euros). */
    private function linesJson(array $byRate, string $name = 'Fornecedor X'): array
    {
        $lines = [];
        foreach ($byRate as $rate => $total) {
            $lines[] = ['codigo' => "C{$rate}", 'item' => "Artigo {$rate}%", 'quantidade' => 1, 'unidade' => 'UN', 'precoUnitario' => $total, 'descontoPct' => 0, 'taxaIva' => $rate, 'totalLinha' => $total];
        }

        return ['fornecedorNome' => $name, 'linhas' => $lines];
    }

    private function invoice(string $mime = 'application/pdf'): OcrInvoice
    {
        $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
        $inv = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => "ocr-invoices/{$this->yuko->id}/f.{$ext}", 'image_mime' => $mime, 'status' => 'processing']);
        Storage::disk('local')->put($inv->image_path, 'bytes');

        return $inv;
    }

    private function runOcr(OcrInvoice $inv): OcrInvoice
    {
        ProcessInvoiceOcrJob::dispatchSync($this->yuko->id, $inv->id);

        return $inv->fresh(['lines', 'summary']);
    }

    // ───────────────────────────────────────────────────────────── parser do QR

    public function test_qr_parser_reads_all_header_fields_from_real_qr(): void
    {
        $qr = AtInvoiceQr::parse(self::QR_CARNES);

        $this->assertTrue($qr['valid']);
        $this->assertSame('502811331', $qr['issuer_nif']);
        $this->assertSame('514148497', $qr['buyer_nif']);
        $this->assertSame('PT', $qr['buyer_country']);
        $this->assertSame('FT', $qr['doc_type']);
        $this->assertSame('N', $qr['doc_status']);
        $this->assertSame('2026-05-04', $qr['issue_date']);
        $this->assertSame('FD M501/1681', $qr['number']);
        $this->assertSame('J6TBYMGN-1681', $qr['atcud']);
        $this->assertSame(14251, $qr['vat_total_cents']);
        $this->assertSame(208284, $qr['total_cents']);
        $this->assertSame('YlRC', $qr['hash']);
        $this->assertSame('0001', $qr['certificate']);
        $this->assertSame([
            ['rate' => 6, 'base_cents' => 178685, 'vat_cents' => 10721],
            ['rate' => 23, 'base_cents' => 15348, 'vat_cents' => 3530],
        ], $qr['by_rate']);
        $this->assertSame(194033, AtInvoiceQr::taxableBaseCents($qr));
    }

    public function test_qr_parser_three_rates_and_regions(): void
    {
        $makro = AtInvoiceQr::parse(self::QR_MAKRO);
        $this->assertSame([6, 13, 23], array_column($makro['by_rate'], 'rate'));
        $this->assertSame([172925, 23684, 104162], array_column($makro['by_rate'], 'base_cents'));
        $this->assertSame('FAC 02002202601/024366', $makro['number']);

        // Açores (I1:PT-AC) com isenta (I2) + retenção (P) + 2.º espaço fiscal (J = PT).
        $qr = AtInvoiceQr::parse('A:500000000*B:514148497*C:PT*D:FT*E:N*F:20260101*G:FT A/1*H:ABCD-1*I1:PT-AC*I2:10.00*I7:100.00*I8:16.00*J1:PT*J7:50.00*J8:11.50*N:27.50*O:187.50*P:5.00*Q:abcd*R:9999');
        $this->assertTrue($qr['valid']);
        $this->assertSame([
            ['rate' => 0, 'base_cents' => 1000, 'vat_cents' => 0],
            ['rate' => 16, 'base_cents' => 10000, 'vat_cents' => 1600],
            ['rate' => 23, 'base_cents' => 5000, 'vat_cents' => 1150],
        ], $qr['by_rate']);
        $this->assertSame(500, $qr['withholding_cents']);
    }

    public function test_qr_parser_rejects_non_at_text_and_flags_bad_fields(): void
    {
        $this->assertNull(AtInvoiceQr::parse('https://example.com'));
        $this->assertNull(AtInvoiceQr::parse(''));

        $bad = AtInvoiceQr::parse('A:500000000*B:514148497*D:FT*F:20261332*G:1*O:abc');
        $this->assertFalse($bad['valid']);
        $this->assertContains('campo H em falta', $bad['errors']);
        $this->assertContains('data (F) inválida', $bad['errors']);
        $this->assertContains('valor inválido em O', $bad['errors']);
    }

    // ───────────────────────────────────────────────────────────── validações A/B

    public function test_buyer_nif_of_another_company_stops_before_ai(): void
    {
        $this->bindFake($this->pdfAnalysis(self::QR_OTHER_COMPANY), []);
        $inv = $this->runOcr($this->invoice());

        $this->assertSame(InvoiceOcrService::STATUS_NOT_OURS, $inv->status);
        $this->assertStringContainsString('515532436', $inv->error_message);
        $this->assertSame([], $this->fake->calls); // ⚠️ a IA NÃO foi chamada
        $this->assertSame('517343355', $inv->supplier_nif);
        $this->assertSame('515532436', $inv->buyer_nif);
        $this->assertSame('1 2026/4', $inv->number);
        $this->assertSame(0, (int) $inv->tokens_in);
        $this->assertEquals(0, $inv->cost_usd);
        $this->assertCount(0, $inv->lines);
    }

    public function test_issuer_equal_to_company_nif_stops_before_ai(): void
    {
        $qr = str_replace(['A:517343355', 'B:515532436'], ['A:514148497', 'B:515532436'], self::QR_OTHER_COMPANY);
        $qr = str_replace('B:515532436', 'B:514148497', $qr); // B até é a empresa — A nunca pode ser
        $this->bindFake($this->pdfAnalysis($qr), []);
        $inv = $this->runOcr($this->invoice());

        $this->assertSame(InvoiceOcrService::STATUS_NOT_OURS, $inv->status);
        $this->assertStringContainsString('emitida pela própria empresa', $inv->error_message);
        $this->assertSame([], $this->fake->calls);
    }

    // ───────────────────────────────────────────────────────────── texto vs imagem

    public function test_pdf_with_text_uses_text_model_and_lines_prompt_with_qr_totals(): void
    {
        $this->bindFake($this->pdfAnalysis(self::QR_FORNO), [['json' => $this->linesJson([6 => 1214.92], 'FORNO TRADICIONAL, Lda.')]]);
        $inv = $this->runOcr($this->invoice());

        $call = $this->fake->calls[0];
        $this->assertSame('gpt-4o-mini', $call['model']);
        $this->assertSame(['text'], $call['kinds']);                     // só texto, sem imagens
        $this->assertStringContainsString('TEXTO DA FATURA', $call['text']);
        $this->assertStringContainsString('IVA 6%: base 1214.92 €', $call['text']); // auto-conferência
        $this->assertStringContainsString('LINHAS', $call['system']);
        $this->assertSame('qr+texto', $inv->source);
        $this->assertSame('texto', $inv->lines_source);
        $this->assertSame(InvoiceOcrService::LINES_PROMPT_VERSION, $inv->prompt_version);
    }

    public function test_guides_from_pdf_text_and_from_ai_are_saved_for_pingwin_link(): void
    {
        $an = $this->pdfAnalysis(self::QR_FORNO);
        $an['text'] = "GT 3105/2026 de 02/05/2026\n101031 Forma Redonda 60,40\nGT 3159/2026 de 04/05/2026";
        $json = $this->linesJson([6 => 1214.92]) + ['guias' => [['numero' => 'GT 3159/2026', 'data' => '2026-05-04'], ['numero' => 'GT 9999/2026', 'data' => null]]];
        $this->bindFake($an, [['json' => $json]]);
        $inv = $this->runOcr($this->invoice());

        $this->assertSame([
            ['ref' => 'GT 3105/2026', 'date' => '2026-05-02'],
            ['ref' => 'GT 3159/2026', 'date' => '2026-05-04'],
            ['ref' => 'GT 9999/2026', 'date' => null],
        ], $inv->guide_refs);
    }

    public function test_scanned_pdf_uses_image_model_with_all_pages(): void
    {
        $this->bindFake($this->pdfAnalysis(self::QR_JULHO, 0, $this->scanImages(3)), [['json' => $this->linesJson([23 => 1504.07])]]);
        $inv = $this->runOcr($this->invoice());

        $call = $this->fake->calls[0];
        $this->assertSame('gpt-6.1-sol', $call['model']);
        $this->assertSame('low', $call['effort']);
        $this->assertSame(['text', 'image_url', 'image_url', 'image_url'], $call['kinds']); // 3 páginas
        $this->assertSame('qr+imagem', $inv->source);
    }

    public function test_photo_goes_directly_to_image_model(): void
    {
        $analysis = ['ok' => true, 'kind' => 'image', 'pages' => 1, 'qr' => ['raw' => self::QR_JULHO, 'page' => 1, 'dpi' => null], 'text' => '', 'text_chars' => 0, 'images' => []];
        $this->bindFake($analysis, [['json' => $this->linesJson([23 => 1504.07])]]);
        $inv = $this->runOcr($this->invoice('image/jpeg'));

        $this->assertSame(['text', 'image_url'], $this->fake->calls[0]['kinds']);
        $this->assertSame('qr+imagem', $inv->source);
        $this->assertSame(InvoiceOcrService::CHECK_OK, $inv->check_status);
    }

    // ───────────────────────────────────────────────────────────── conferência

    public function test_lines_match_qr_per_rate_header_and_summary_from_qr(): void
    {
        PingwinSupplier::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'S1', 'name' => 'Carnes Sá da Bandeira', 'tax_number' => '502811331']);
        // 1786.86 em vez de 1786.85 → 1 cêntimo de diferença: dentro da tolerância (2).
        $this->bindFake($this->pdfAnalysis(self::QR_CARNES), [['json' => $this->linesJson([6 => 1786.86, 23 => 153.48], 'CARNES SÁ DA BANDEIRA')]]);
        $inv = $this->runOcr($this->invoice());

        $this->assertSame('por_validar', $inv->status);
        $this->assertSame(InvoiceOcrService::CHECK_OK, $inv->check_status);
        $this->assertSame(1, $inv->attempts);
        $this->assertCount(1, $this->fake->calls);
        // Cabeçalho do QR (sem IA)
        $this->assertSame('502811331', $inv->supplier_nif);
        $this->assertSame('514148497', $inv->buyer_nif);
        $this->assertSame('FD M501/1681', $inv->number);
        $this->assertSame('J6TBYMGN-1681', $inv->atcud);
        $this->assertSame('FT', $inv->doc_type);
        $this->assertSame('2026-05-04', $inv->issue_date->toDateString());
        $this->assertTrue($inv->qr_ok);
        $this->assertSame(self::QR_CARNES, $inv->qr_raw);
        $this->assertSame('CARNES SÁ DA BANDEIRA', $inv->supplier_name);
        $this->assertNotNull($inv->supplier_id);                        // ligado pelo NIF do QR
        $this->assertSame(208284, $inv->summary->total_cents);         // O
        $this->assertSame(14251, $inv->summary->vat_total_cents);      // N
        $this->assertSame(194033, $inv->summary->taxable_base_cents);
        // Código do fornecedor guardado
        $this->assertSame('C6', $inv->lines[0]->supplier_code);
        $this->assertSame([1, 0], array_column($inv->check_diff, 'diff_cents'));
    }

    public function test_text_mismatch_retries_with_image_model_and_keeps_the_good_one(): void
    {
        $this->bindFake(
            $this->pdfAnalysis(self::QR_FORNO),
            [
                ['json' => $this->linesJson([6 => 1154.52]), 'in' => 3000, 'out' => 900],      // falta uma linha (60,40)
                ['json' => $this->linesJson([6 => 1214.92]), 'in' => 8000, 'out' => 2000],     // imagem: confere
            ],
            $this->pdfAnalysis(self::QR_FORNO, 2600, $this->scanImages(2)),
        );
        $inv = $this->runOcr($this->invoice());

        $this->assertSame(['gpt-4o-mini', 'gpt-6.1-sol'], array_column($this->fake->calls, 'model'));
        $this->assertSame(['auto', 'always'], $this->fake->analyzeCalls); // imagens só pedidas para a 2.ª
        $this->assertSame(InvoiceOcrService::CHECK_OK, $inv->check_status);
        $this->assertSame('gpt-6.1-sol', $inv->model);
        $this->assertSame('qr+imagem', $inv->source);
        $this->assertSame(2, $inv->attempts);
        $this->assertSame(11000, $inv->tokens_in);   // soma das 2 tentativas
        $this->assertSame(2900, $inv->tokens_out);
        $this->assertEqualsWithDelta(3000 * 0.15 / 1e6 + 900 * 0.60 / 1e6 + 8000 * 2 / 1e6 + 2000 * 10 / 1e6, $inv->cost_usd, 1e-6);
        $this->assertSame([false, true], array_column($inv->attempts_log, 'chosen'));
    }

    public function test_both_attempts_mismatch_keeps_best_with_diff_per_rate(): void
    {
        $this->bindFake(
            $this->pdfAnalysis(self::QR_CARNES, 0, $this->scanImages(1)),
            [
                ['json' => $this->linesJson([6 => 1700.00, 23 => 153.48])],  // dif −86,85
                ['json' => $this->linesJson([6 => 1780.00, 23 => 153.48])],  // dif −6,85 (melhor)
            ],
        );
        $inv = $this->runOcr($this->invoice());

        // 1.ª já foi imagem → a 2.ª é imagem com MAIS esforço.
        $this->assertSame(['gpt-6.1-sol', 'gpt-6.1-sol'], array_column($this->fake->calls, 'model'));
        $this->assertSame(['low', 'medium'], array_column($this->fake->calls, 'effort'));
        $this->assertSame('por_validar', $inv->status);                     // nunca se descarta
        $this->assertSame(InvoiceOcrService::CHECK_MISMATCH, $inv->check_status);
        $this->assertSame([
            ['rate' => 6, 'qr_cents' => 178685, 'lines_cents' => 178000, 'diff_cents' => -685, 'ok' => false],
            ['rate' => 23, 'qr_cents' => 15348, 'lines_cents' => 15348, 'diff_cents' => 0, 'ok' => true],
        ], $inv->check_diff);
        $this->assertSame(178000, $inv->lines[0]->line_total_cents);
    }

    public function test_conference_flags_rates_missing_on_either_side_and_lines_without_rate(): void
    {
        $qr = AtInvoiceQr::parse(self::QR_MAKRO);
        $res = app(InvoiceOcrService::class)->conference([
            ['vat_rate' => 6, 'line_total_cents' => 172925],
            ['vat_rate' => 23, 'line_total_cents' => 104162],
            ['vat_rate' => null, 'line_total_cents' => 23684], // a taxa de 13% não foi lida
        ], $qr);

        $this->assertFalse($res['ok']);
        $this->assertSame([null, 6, 13, 23], array_column($res['rows'], 'rate'));
        $this->assertSame([23684, 0, -23684, 0], array_column($res['rows'], 'diff_cents'));
    }

    // ───────────────────────────────────────────────────────────── custo e payload

    public function test_cost_uses_configured_prices_and_unknown_model_is_null(): void
    {
        $svc = app(InvoiceOcrService::class);
        $this->assertEqualsWithDelta(0.00021, $svc->costUsd('gpt-4o-mini', 1000, 100), 1e-9);
        $this->assertEqualsWithDelta(0.00021, $svc->costUsd('gpt-4o-mini-2024-07-18', 1000, 100), 1e-9);
        $this->assertEqualsWithDelta(0.003, $svc->costUsd('gpt-6.1-sol', 1000, 100), 1e-9);
        $this->assertNull($svc->costUsd('outro-modelo', 1000, 100));
    }

    public function test_openai_payload_per_model_kind(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($this->linesJson([6 => 1214.92]))]]],
            'usage'   => ['prompt_tokens' => 1234, 'completion_tokens' => 56],
        ])]);
        config(['services.openai.key' => 'sk-test']);
        $svc = new class extends InvoiceOcrService {
            public function call(string $model, ?string $effort): array
            {
                return $this->callModel($model, 'sys', [['type' => 'text', 'text' => 'x']], $effort);
            }
        };

        $r = $svc->call('gpt-6.1-sol', 'low');
        $this->assertSame(1234, $r['tokens_in']);
        $svc->call('gpt-4o-mini', 'low');

        $sent = Http::recorded()->map(fn ($p) => $p[0]->data())->all();
        $this->assertSame('low', $sent[0]['reasoning_effort']);      // raciocínio: esforço, sem temperature
        $this->assertArrayNotHasKey('temperature', $sent[0]);
        $this->assertArrayHasKey('max_completion_tokens', $sent[0]);
        $this->assertSame(0, $sent[1]['temperature']);               // 4o-mini: temperature, sem esforço
        $this->assertArrayNotHasKey('reasoning_effort', $sent[1]);
    }

    // ───────────────────────────────────────────────────────────── reprocessar + UI

    public function test_reprocess_endpoint_queues_job_and_guards_states(): void
    {
        Bus::fake();
        $inv = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => 'x.pdf', 'status' => 'por_validar']);
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/reprocess";

        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(202)->assertJsonPath('data.status', 'processing');
        Bus::assertDispatched(ProcessInvoiceOcrJob::class, fn ($j) => $j->invoiceId === $inv->id);

        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(409); // já está a ler

        $inv->update(['status' => 'validada']);
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(422); // não perde a validação

        // Tenancy: fatura de outra empresa → 404.
        $planId = DB::table('plans')->value('id');
        $other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $foreign = OcrInvoice::create(['company_id' => $other->id, 'image_path' => 'y.pdf', 'status' => 'por_validar']);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$foreign->id}/reprocess")->assertStatus(404);
    }

    public function test_show_exposes_origin_check_and_supplier_code(): void
    {
        $this->bindFake($this->pdfAnalysis(self::QR_CARNES), [['json' => $this->linesJson([6 => 1786.85, 23 => 153.48])]]);
        $inv = $this->runOcr($this->invoice());

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.qr_ok', true)
            ->assertJsonPath('data.invoice.source', 'qr+texto')
            ->assertJsonPath('data.invoice.check_status', 'confere')
            ->assertJsonPath('data.invoice.buyer_nif', '514148497')
            ->assertJsonPath('data.invoice.atcud', 'J6TBYMGN-1681')
            ->assertJsonPath('data.invoice.check_diff.0.qr', 1786.85)
            ->assertJsonPath('data.invoice.lines.0.supplier_code', 'C6');
    }

    public function test_command_reprocesses_sync_and_prints_metrics(): void
    {
        $this->bindFake($this->pdfAnalysis(self::QR_FORNO), [['json' => $this->linesJson([6 => 1214.92])]]);
        $inv = $this->invoice();
        $inv->update(['status' => 'por_validar']);

        $this->artisan('ocr:reprocess', ['invoice' => $inv->id, '--sync' => true])
            ->expectsOutputToContain('qr+texto')
            ->assertExitCode(0);
        $this->assertSame('confere', $inv->fresh()->check_status);

        $inv->update(['status' => 'validada']);
        $this->artisan('ocr:reprocess', ['invoice' => $inv->id])->assertExitCode(1);
    }
}
