<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\PingwinSupplier;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — OCR de faturas de fornecedor (Fase A): a IA é chamada com o prompt
 * B2B (mock da resposta), sanitização, gravação (cêntimos, linhas+sumário, status
 * por_validar, model+prompt_version), NÃO escreve no PingWin, teto de custo, gate
 * + tenancy. O limite OpenAI é substituído por um fake (override rawExtract).
 */
class InvoiceOcrTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500013101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    private function sampleJson(): string
    {
        return json_encode([
            'fornecedor'   => ['nome' => 'Recheio Cash & Carry SA', 'nif' => '500829993'],
            'numeroFatura' => 'FT 2024A/12345',
            'dataEmissao'  => '2024-03-15',
            'linhas'       => [
                ['item' => 'Arroz Agulha 5kg', 'quantidade' => 10, 'unidade' => 'un', 'precoUnitario' => 4.20, 'descontoPct' => 0, 'totalLinha' => 42.00, 'taxaIva' => 6],
                ['item' => 'Detergente 5L', 'quantidade' => 2, 'unidade' => 'un', 'precoUnitario' => 6.50, 'descontoPct' => 10, 'totalLinha' => 11.70, 'taxaIva' => 23],
                ['item' => '', 'quantidade' => null, 'precoUnitario' => null, 'totalLinha' => null, 'taxaIva' => 99], // lixo → descartada
            ],
            'sumario'      => [
                'totalMercadorias' => 53.70, 'descontoComercial' => 0, 'baseTributavel' => 53.70,
                'ivaPorTaxa' => [['taxa' => 6, 'base' => 42.00, 'iva' => 2.52], ['taxa' => 23, 'base' => 11.70, 'iva' => 2.69]],
                'valorIvaTotal' => 5.21, 'retencaoFonte' => 0, 'descontoFinanceiro' => 0, 'total' => 58.91,
            ],
        ]);
    }

    /** Serviço com a IA substituída por um fake (não chama a OpenAI de verdade). */
    private function bindFakeOcr(string $json): void
    {
        $this->app->instance(InvoiceOcrService::class, new class($json) extends InvoiceOcrService {
            public function __construct(private string $json) {}
            protected function rawExtract(string $dataUri): string
            {
                return $this->json;
            }
        });
    }

    private function makeProcessingInvoice(): OcrInvoice
    {
        $invoice = OcrInvoice::create([
            'company_id' => $this->resto->id, 'image_path' => "ocr-invoices/{$this->resto->id}/x.jpg",
            'image_mime' => 'image/jpeg', 'status' => 'processing',
        ]);
        Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');
        return $invoice;
    }

    public function test_sanitize_converts_to_cents_and_drops_junk(): void
    {
        $clean = app(InvoiceOcrService::class)->sanitize(json_decode($this->sampleJson(), true));

        $this->assertSame('500829993', $clean['supplier_nif']);
        $this->assertSame('2024-03-15', $clean['issue_date']);
        $this->assertCount(2, $clean['lines']); // a 3.ª (lixo) foi descartada
        $this->assertSame(420, $clean['lines'][0]['unit_price_cents']); // 4.20 € → 420
        $this->assertSame(1170, $clean['lines'][1]['line_total_cents']);
        $this->assertSame(23, $clean['lines'][1]['vat_rate']);
        $this->assertSame(5891, $clean['summary']['total_cents']); // 58.91 € → 5891
    }

    public function test_job_full_chain_persists_invoice_lines_summary_without_pingwin(): void
    {
        // Fornecedor sincronizado com o mesmo NIF → deve religar automaticamente.
        $supplier = PingwinSupplier::create(['company_id' => $this->resto->id, 'pingwin_id' => 'S1', 'name' => 'Recheio', 'tax_number' => '500829993']);

        $this->bindFakeOcr($this->sampleJson());
        $invoice = $this->makeProcessingInvoice();

        ProcessInvoiceOcrJob::dispatchSync($this->resto->id, $invoice->id);

        $invoice->refresh();
        $this->assertSame('por_validar', $invoice->status);
        $this->assertSame(InvoiceOcrService::MODEL, $invoice->model);
        $this->assertSame(InvoiceOcrService::PROMPT_VERSION, $invoice->prompt_version);
        $this->assertFalse((bool) $invoice->synced_to_pingwin); // ⚠️ NÃO escreve no PingWin
        $this->assertSame($supplier->id, $invoice->supplier_id); // religado por NIF
        $this->assertGreaterThan(0, $invoice->confidence);

        $this->assertDatabaseCount('ocr_invoice_lines', 2);
        $this->assertDatabaseHas('ocr_invoice_summary', ['ocr_invoice_id' => $invoice->id, 'total_cents' => 5891]);
    }

    public function test_upload_stores_file_and_queues_job(): void
    {
        Bus::fake();
        $res = $this->actingAs($this->restoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->resto->id}/ocr/invoices",
            ['file' => UploadedFile::fake()->image('fatura.jpg', 800, 1000)]
        );
        $res->assertStatus(200)->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseCount('ocr_invoices', 1);
        Bus::assertDispatched(ProcessInvoiceOcrJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_upload_respects_monthly_cost_cap(): void
    {
        config(['services.openai.ocr_monthly_cap' => 2]);
        OcrInvoice::create(['company_id' => $this->resto->id, 'image_path' => 'a', 'status' => 'validada']);
        OcrInvoice::create(['company_id' => $this->resto->id, 'image_path' => 'b', 'status' => 'validada']);

        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->resto->id}/ocr/invoices",
            ['file' => UploadedFile::fake()->image('fatura.jpg')]
        )->assertStatus(429);
        Bus::assertNotDispatched(ProcessInvoiceOcrJob::class);
    }

    public function test_update_saves_edits_in_cents_and_marks_validada(): void
    {
        $this->bindFakeOcr($this->sampleJson());
        $invoice = $this->makeProcessingInvoice();
        ProcessInvoiceOcrJob::dispatchSync($this->resto->id, $invoice->id);

        $payload = [
            'supplier_name' => 'Recheio SA', 'supplier_nif' => '500829993', 'number' => 'FT 1', 'issue_date' => '2024-03-15',
            'lines' => [
                ['item' => 'Arroz', 'quantity' => 10, 'unit' => 'un', 'unit_price' => 4.20, 'discount_pct' => 0, 'line_total' => 42.00, 'vat_rate' => 6],
            ],
            'summary' => [
                'goods_total' => 42.00, 'commercial_discount' => 0, 'taxable_base' => 42.00,
                'vat_total' => 2.52, 'withholding' => 0, 'financial_discount' => 0, 'total' => 44.52,
                'vat_breakdown' => [['rate' => 6, 'base' => 42.00, 'vat' => 2.52]],
            ],
        ];

        $this->actingAs($this->restoUser, 'sanctum')
            ->putJson("/api/v1/companies/{$this->resto->id}/ocr/invoices/{$invoice->id}", $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.status', 'validada')
            ->assertJsonPath('data.invoice.summary.total', 44.52); // devolvido em euros

        $invoice->refresh();
        $this->assertSame('validada', $invoice->status);
        $this->assertFalse((bool) $invoice->synced_to_pingwin);
        $this->assertDatabaseCount('ocr_invoice_lines', 1);
        $this->assertDatabaseHas('ocr_invoice_summary', ['ocr_invoice_id' => $invoice->id, 'total_cents' => 4452]);
    }

    public function test_show_returns_euros_and_suppliers(): void
    {
        $this->bindFakeOcr($this->sampleJson());
        $invoice = $this->makeProcessingInvoice();
        ProcessInvoiceOcrJob::dispatchSync($this->resto->id, $invoice->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/ocr/invoices/{$invoice->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.summary.total', 58.91)
            ->assertJsonPath('data.invoice.lines.0.unit_price', 4.2);
    }

    public function test_prompt_asks_for_emissor_nif_and_version_bumped(): void
    {
        $prompt = app(InvoiceOcrService::class)->prompt();
        $this->assertStringContainsStringIgnoringCase('EMISSOR', $prompt);
        $this->assertStringContainsStringIgnoringCase('adquirente', $prompt); // distingue do cliente
        $this->assertSame('b2b-v2', InvoiceOcrService::PROMPT_VERSION);        // bump da versão
    }

    public function test_own_company_nif_is_dropped_it_is_the_client_not_the_supplier(): void
    {
        // A IA trouxe o NIF (e nome) da PRÓPRIA empresa (o cliente) por engano.
        $json = json_encode([
            'fornecedor'   => ['nome' => 'Resto', 'nif' => $this->resto->nipc], // = NIF/nome da própria empresa
            'numeroFatura' => 'FT 1', 'dataEmissao' => '2024-03-15',
            'linhas'       => [['item' => 'X', 'quantidade' => 1, 'precoUnitario' => 10, 'totalLinha' => 10, 'taxaIva' => 23]],
            'sumario'      => ['total' => 12.30],
        ]);
        // Fornecedor sincronizado com o NIF da empresa NÃO deve ser ligado (é o cliente).
        PingwinSupplier::create(['company_id' => $this->resto->id, 'pingwin_id' => 'S9', 'name' => 'Resto', 'tax_number' => $this->resto->nipc]);

        $this->bindFakeOcr($json);
        $invoice = $this->makeProcessingInvoice();
        ProcessInvoiceOcrJob::dispatchSync($this->resto->id, $invoice->id);

        $invoice->refresh();
        $this->assertNull($invoice->supplier_nif);  // NIF da própria empresa → descartado
        $this->assertNull($invoice->supplier_name); // nome = designação fiscal → descartado
        $this->assertNull($invoice->supplier_id);   // não liga ao fornecedor errado
    }

    public function test_show_returns_calculated_lines_total(): void
    {
        $this->bindFakeOcr($this->sampleJson());
        $invoice = $this->makeProcessingInvoice();
        ProcessInvoiceOcrJob::dispatchSync($this->resto->id, $invoice->id);

        // As linhas somam 42.00 + 11.70 = 53.70 (calculado pela Xplendor).
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/ocr/invoices/{$invoice->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.lines_total', 53.7);
    }

    public function test_module_gate_and_tenancy(): void
    {
        // Empresa sem módulo pingwin → 403.
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/ocr/invoices")
            ->assertStatus(403);
    }
}
