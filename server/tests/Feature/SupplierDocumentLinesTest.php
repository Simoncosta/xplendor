<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DispatchSupplierDocumentLinesSyncJob;
use App\Jobs\SyncPingwinSupplierDocumentLinesJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentSyncRun;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use App\Services\PingwinService;
use App\Services\SupplierDocumentLinesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor do PingWin (F4, SÓ LEITURA). O Python é
 * simulado (fetchSupplierDocumentLines). Cobre: upsert e substituição atómica, falha que
 * mantém as linhas antigas, incremental (só novos/mudados/falhados; âmbito fechados dos 4
 * tipos), conferência ok/diff, precisão do preço, artigo do catálogo, comando e jobs.
 */
class SupplierDocumentLinesTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    /** @var array<int, array> pedidos feitos ao "Python" */
    public array $requests = [];
    /** @var callable(array $doc): array resposta por documento */
    public $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        CompanyIntegration::create(['company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);

        $this->respond = fn (array $d) => $this->ok($d['docheader_id']);
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            public function fetchSupplierDocumentLines(int $companyId, array $docs): array
            {
                $this->test->requests[] = $docs;

                return array_map(fn ($d) => ($this->test->respond)($d), $docs);
            }
        });
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function doc(string $id, array $extra = []): PingwinSupplierDocument
    {
        return PingwinSupplierDocument::create(array_merge([
            'company_id' => $this->resto->id, 'docheader_id' => $id, 'docconfig_id' => '1209',
            'document' => "VFT BOVFT/{$id}", 'doc_date' => '2026-10-05', 'total_cents' => 117010,
            'docstatus_id' => '8002', 'docstatus_description' => 'Fechado',
        ], $extra));
    }

    /** Resposta OK no formato do Python (BOVFT/1020 real por omissão). */
    private function ok(string $id, ?array $details = null, array $header = []): array
    {
        $details ??= [$this->line(1, '100660', 'ALHEIRA', 90.0, 10.57, 951.3, 218.8, 13.001111)];

        return ['docheader_id' => $id, 'docconfig_id' => '1209', 'ok' => true, 'closed' => true, 'attempts' => 1,
            'header' => array_merge([
                'id' => $id, 'due_date' => '20261104T00:00:00', 'paycond_id' => '584955579139752253',
                'docreference_number' => '27731', 'docreference_date' => '20261005T00:00:00',
                'discount1' => 0.0, 'discount2_add' => 0.0, 'shipping_value' => 0.0, 'withholding' => 0.0,
                'total_products' => 951.3, 'total_tax' => 218.8, 'total' => 1170.1, 'docstatus_id' => '8002',
                'detail_discount_value' => 0.0, 'adjustment' => 0.0,
            ], $header),
            'details' => $details];
    }

    private function line(int $n, string $code, string $desc, float $qnt, float $price, float $total, float $tax, float $priceWTax, string $productId = 'P100660', string $supplierCode = ''): array
    {
        return ['id' => "L{$n}", 'line_number' => $n, 'product_id' => $productId, 'product_code' => $code,
            'entity_product_id' => $supplierCode, 'description' => $desc, 'qnt' => $qnt, 'unit_code' => 'KG',
            'unit_desc' => 'Quilograma', 'price' => $price, 'discount1' => 0.0, 'total' => $total,
            'taxgroup_id' => '1003001', 'tax_description' => 'Normal', 'tax_value' => $tax,
            'price_w_tax' => $priceWTax, 'total_w_tax' => $total + $tax, 'warehouse_id' => 'W1',
            'raw' => ['id' => "L{$n}", 'barcode' => '']];
    }

    private function svc(): SupplierDocumentLinesService
    {
        return app(SupplierDocumentLinesService::class);
    }

    private function lines(string $docId)
    {
        return PingwinSupplierDocumentLine::where('docheader_id', $docId)->orderBy('line_number')->get();
    }

    // ───────────────────────────────────────────────────────────── gravação

    public function test_reads_header_and_lines_of_bovft_1020(): void
    {
        $doc = $this->doc('584955579139786478');
        $stats = $this->svc()->sync($this->resto->id);

        $this->assertSame(['docs' => 1, 'ok' => 1, 'failed' => 0, 'diff' => 0, 'not_closed' => 0, 'lines' => 1], array_diff_key($stats, ['ms' => 1]));
        $this->assertSame([[['docconfig_id' => '1209', 'docheader_id' => '584955579139786478']]], $this->requests);

        $doc->refresh();
        $this->assertSame('2026-11-04', $doc->due_date->toDateString());
        $this->assertSame('584955579139752253', $doc->paycond_pingwin_id);
        $this->assertSame('2026-10-05', $doc->docreference_date->toDateString());
        $this->assertSame(95130, (int) $doc->total_products_cents);
        $this->assertSame(21880, (int) $doc->total_tax_cents);
        $this->assertSame('ok', $doc->lines_status);
        $this->assertSame('ok', $doc->lines_check);
        $this->assertSame(0, (int) $doc->lines_diff_cents);
        $this->assertNotNull($doc->lines_synced_at);
        $this->assertSame(117010, (int) $doc->lines_synced_total_cents);
        $this->assertSame('8002', $doc->lines_synced_docstatus_id);

        $l = $this->lines('584955579139786478')->first();
        $this->assertSame(['100660', 'ALHEIRA', 'KG', 'Quilograma'], [$l->product_code, $l->description, $l->unit_code, $l->unit_desc]);
        $this->assertSame([95130, 21880, 117010], [$l->total_cents, $l->tax_value_cents, $l->total_w_tax_cents]);
        $this->assertEquals(90.0, (float) $l->qnt);
        $this->assertNull($l->supplier_code); // entity_product_id "" → null
        $this->assertSame('', $l->raw['barcode']);
    }

    public function test_price_keeps_full_precision(): void
    {
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, '100700', 'MAMINHA', 5.085, 12.995, 66.08, 3.96, 13.7747),
            $this->line(2, '100701', 'LOMBO', 16.175, 4.0025, 64.74, 3.88, 4.24265),
        ], ['total_products' => 130.82, 'total_tax' => 7.84]);
        $this->doc('D1');
        $this->svc()->sync($this->resto->id);

        $rows = $this->lines('D1');
        $this->assertSame('12.995000', (string) $rows[0]->price);   // 3 casas, não 1300 cêntimos
        $this->assertSame('4.002500', (string) $rows[1]->price);    // 4 casas
        $this->assertSame('4.242650', (string) $rows[1]->price_w_tax);
        $this->assertSame('16.175000', (string) $rows[1]->qnt);
    }

    public function test_reread_replaces_lines_atomically(): void
    {
        $this->doc('D1');
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'A', 'A', 1, 10, 10, 2.3, 12.3), $this->line(2, 'B', 'B', 1, 20, 20, 4.6, 24.6), $this->line(3, 'C', 'C', 1, 30, 30, 6.9, 36.9),
        ], ['total_products' => 60, 'total_tax' => 13.8]);
        $this->svc()->sync($this->resto->id);
        $this->assertCount(3, $this->lines('D1'));

        // O documento foi editado no BO (total mudou na lista) → relê; a linha 2 desapareceu.
        PingwinSupplierDocument::where('docheader_id', 'D1')->update(['total_cents' => 4920]);
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'A', 'A', 1, 10, 10, 2.3, 12.3), $this->line(3, 'C', 'C', 1, 30, 30, 6.9, 36.9),
        ], ['total_products' => 40, 'total_tax' => 9.2]);
        $this->svc()->sync($this->resto->id);

        $this->assertSame([1, 3], $this->lines('D1')->pluck('line_number')->all());
        $this->assertSame(2, PingwinSupplierDocumentLine::count());
    }

    public function test_failure_keeps_old_lines_and_marks_failed(): void
    {
        $doc = $this->doc('D1');
        $this->svc()->sync($this->resto->id);
        $this->assertCount(1, $this->lines('D1'));

        PingwinSupplierDocument::where('docheader_id', 'D1')->update(['total_cents' => 99999]);
        $this->respond = fn ($d) => ['docheader_id' => $d['docheader_id'], 'ok' => false, 'error' => 'HTTPError: HTTP 500'];
        $stats = $this->svc()->sync($this->resto->id);

        $this->assertSame(1, $stats['failed']);
        $doc->refresh();
        $this->assertSame('failed', $doc->lines_status);
        $this->assertSame('HTTPError: HTTP 500', $doc->lines_error);
        $this->assertCount(1, $this->lines('D1'));                 // as antigas ficam
        $this->assertSame(117010, (int) $doc->lines_synced_total_cents); // snapshot da última leitura boa
    }

    public function test_write_error_rolls_back_and_keeps_old_lines(): void
    {
        $this->doc('D1');
        $this->svc()->sync($this->resto->id);

        PingwinSupplierDocument::where('docheader_id', 'D1')->update(['total_cents' => 1]);
        // line_number repetido → a gravação falha a meio: nada é substituído.
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'X', 'X', 1, 1, 1, 0, 1), $this->line(1, 'Y', 'Y', 1, 1, 1, 0, 1),
        ]);
        $this->svc()->sync($this->resto->id);

        $this->assertSame(['ALHEIRA'], $this->lines('D1')->pluck('description')->all());
        $this->assertSame('failed', PingwinSupplierDocument::where('docheader_id', 'D1')->value('lines_status'));
    }

    public function test_whole_batch_failure_marks_all_failed_without_looping(): void
    {
        $this->doc('D1');
        $this->doc('D2');
        $this->respond = function () {
            throw new \RuntimeException('Sem resposta do cliente PingWin');
        };
        $stats = $this->svc()->sync($this->resto->id);

        $this->assertSame(2, $stats['failed']);
        $this->assertCount(1, $this->requests); // um lote, sem repetir em loop no mesmo ciclo
        $this->assertSame(2, PingwinSupplierDocument::where('lines_status', 'failed')->count());
    }

    // ───────────────────────────────────────────────────────────── incremental e âmbito

    public function test_incremental_only_new_changed_or_failed_and_scope(): void
    {
        $this->doc('NEW');
        $this->doc('FRC', ['docconfig_id' => '584955579139752236']);
        $this->doc('NC', ['docconfig_id' => '1205']);
        $this->doc('ND', ['docconfig_id' => '1206']);
        $this->doc('ANUL', ['docstatus_id' => '8003']);                 // anulado: fora
        $this->doc('RASC', ['docstatus_id' => '8004']);                 // edição/rascunho: fora
        $this->doc('ENC', ['docconfig_id' => '1200']);                  // encomenda: fora
        $this->svc()->sync($this->resto->id);
        $this->assertEqualsCanonicalizing(['NEW', 'FRC', 'NC', 'ND'], array_column($this->requests[0], 'docheader_id'));

        // Nada mudou → nada a ler.
        $this->requests = [];
        $this->assertSame(0, $this->svc()->candidates($this->resto->id)->count());

        // Total mudou num, estado noutro (ex.: reaberto e fechado), um falhado → só esses 3.
        PingwinSupplierDocument::where('docheader_id', 'NEW')->update(['total_cents' => 5]);
        PingwinSupplierDocument::where('docheader_id', 'NC')->update(['lines_synced_docstatus_id' => '8004']);
        PingwinSupplierDocument::where('docheader_id', 'ND')->update(['lines_status' => 'failed', 'updated_at' => now()->subDay()]);
        $this->svc()->sync($this->resto->id);
        $this->assertEqualsCanonicalizing(['NEW', 'NC', 'ND'], array_column($this->requests[0], 'docheader_id'));
    }

    public function test_doc_option_forces_reread_and_accepts_document_number(): void
    {
        $this->doc('584955579139786478', ['document' => 'VFT BOVFT/1020']);
        $this->svc()->sync($this->resto->id);
        $this->requests = [];

        $this->svc()->sync($this->resto->id, ['doc' => 'VFT BOVFT/1020']);
        $this->assertSame('584955579139786478', $this->requests[0][0]['docheader_id']);
    }

    public function test_batches_of_50_and_limit(): void
    {
        foreach (range(1, 120) as $i) {
            $this->doc("D{$i}");
        }
        $this->svc()->sync($this->resto->id, ['limit' => 70]);
        $this->assertSame([50, 20], array_map('count', $this->requests));
        $this->assertSame(50, $this->svc()->candidates($this->resto->id)->count());
    }

    // ───────────────────────────────────────────────────────────── conferência

    public function test_check_diff_is_recorded_but_lines_are_kept(): void
    {
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'A', 'A', 1, 900, 900, 207, 1107),
        ]); // header diz 951,30 / 218,80
        $doc = $this->doc('D1');
        $stats = $this->svc()->sync($this->resto->id);

        $doc->refresh();
        $this->assertSame(1, $stats['diff']);
        $this->assertSame('ok', $doc->lines_status);
        $this->assertSame('diff', $doc->lines_check);
        $this->assertSame(-5130, (int) $doc->lines_diff_cents);
        $this->assertSame(-1180, (int) $doc->lines_tax_diff_cents);
        $this->assertCount(1, $this->lines('D1'));
    }

    public function test_check_uses_gross_total_products_minus_line_discounts(): void
    {
        // VFT BOVFT/1037 (Sumol Compal) real: total_products BRUTO 232,61, descontos de linha
        // 99,95 → Σ linhas líquidas 132,66; IVA 23,98; total 156,64.
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'A', 'A', 48, 0.87, 27.14, 6.24, 1.0701),
            $this->line(2, 'B', 'B', 10, 15.0, 105.52, 17.74, 12.33),
        ], ['total_products' => 232.61, 'detail_discount_value' => 99.95, 'total_tax' => 23.98, 'total' => 156.64, 'adjustment' => 0.0]);
        $doc = $this->doc('D1', ['total_cents' => 15664]);
        $this->svc()->sync($this->resto->id);

        $doc->refresh();
        $this->assertSame('ok', $doc->lines_check);
        $this->assertSame(0, (int) $doc->lines_diff_cents);
        $this->assertSame(23261, (int) $doc->total_products_cents);
        $this->assertSame(9995, (int) $doc->detail_discount_cents);
    }

    public function test_adjustment_is_stored(): void
    {
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], null, ['adjustment' => 0.35]);
        $this->doc('D1');
        $this->svc()->sync($this->resto->id);
        $this->assertSame(35, (int) PingwinSupplierDocument::where('docheader_id', 'D1')->value('adjustment_cents'));
    }

    public function test_check_tolerates_one_cent(): void
    {
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, 'A', 'A', 1, 10, 10.01, 2.30, 12.3),
        ], ['total_products' => 10.00, 'total_tax' => 2.30]);
        $this->doc('D1');
        $this->svc()->sync($this->resto->id);
        $this->assertSame('ok', PingwinSupplierDocument::where('docheader_id', 'D1')->value('lines_check'));
    }

    public function test_article_and_supplier_code_are_linked(): void
    {
        $item = PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'P100660', 'code' => '100660', 'description' => 'ALHEIRA']);
        $this->respond = fn ($d) => $this->ok($d['docheader_id'], [
            $this->line(1, '100660', 'ALHEIRA', 90, 10.57, 951.3, 218.8, 13.001111, 'P100660', '1002525'),
            $this->line(2, '999', 'SEM CATÁLOGO', 0, 0, 0, 0, 0, 'P-NAO-EXISTE'),
        ]);
        $this->doc('D1');
        $this->svc()->sync($this->resto->id);

        $rows = $this->lines('D1');
        $this->assertSame($item->id, $rows[0]->article_id);
        $this->assertSame('1002525', $rows[0]->supplier_code);
        $this->assertNull($rows[1]->article_id);
    }

    // ───────────────────────────────────────────────────────────── comando e jobs

    public function test_command_prints_summary(): void
    {
        $this->doc('584955579139786478', ['document' => 'VFT BOVFT/1020']);
        $this->artisan('pingwin:sync-supplier-document-lines', ['company' => $this->resto->id, '--doc' => 'VFT BOVFT/1020'])
            ->expectsOutputToContain('Documentos a ler: 1')
            ->assertExitCode(0);
        $this->assertSame(1, PingwinSupplierDocumentLine::count());
    }

    public function test_job_waits_for_documents_run_and_chains_batches(): void
    {
        foreach (range(1, 60) as $i) {
            $this->doc("D{$i}");
        }
        $run = PingwinDocumentSyncRun::create(['company_id' => $this->resto->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-07',
            'trigger' => PingwinDocumentSyncRun::TRIGGER_NIGHTLY, 'status' => PingwinDocumentSyncRun::STATUS_RUNNING]);

        // Run da F1 ativo → não lê nada (release).
        SyncPingwinSupplierDocumentLinesJob::dispatchSync($this->resto->id, 300, now()->toIso8601String());
        $this->assertSame([], $this->requests);

        $run->update(['status' => PingwinDocumentSyncRun::STATUS_OK]);
        Bus::fake([SyncPingwinSupplierDocumentLinesJob::class]);
        (new SyncPingwinSupplierDocumentLinesJob($this->resto->id, 300, now()->toIso8601String()))->handle($this->svc());

        $this->assertSame([50], array_map('count', $this->requests));
        Bus::assertDispatched(SyncPingwinSupplierDocumentLinesJob::class, fn ($j) => $j->budget === 250); // próximo lote
    }

    public function test_nightly_dispatcher_queues_one_cycle_per_company(): void
    {
        Bus::fake([SyncPingwinSupplierDocumentLinesJob::class]);
        (new DispatchSupplierDocumentLinesSyncJob())->handle();
        Bus::assertDispatched(SyncPingwinSupplierDocumentLinesJob::class, fn ($j) => $j->companyId === $this->resto->id
            && $j->budget === SupplierDocumentLinesService::NIGHTLY_MAX_DOCS);
    }
}
