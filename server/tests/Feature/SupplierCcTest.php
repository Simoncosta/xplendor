<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DispatchSupplierCcSyncJob;
use App\Jobs\SyncPingwinSupplierCcJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierCcBalance;
use App\Models\PingwinSupplierCcDocument;
use App\Services\PingwinService;
use App\Services\SupplierCcService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1). O Python é simulado (fetchSupplierCc /
 * invoke). Cobre: upsert + apagar os que faltam, as duas guardas, reconciled, os 4
 * cálculos (com FR auto-paga, NL, NC em aberto e docconfig fora do espelho), o extrato
 * e o seu invariante, o refresh de 1 fornecedor, o despacho em lotes e o `settled`.
 */
class SupplierCcTest extends TestCase
{
    use RefreshDatabase;

    private const FV = '1209';
    private const FR = '584955579139752236';
    private const NL = '1204';
    private const NC = '1205';

    private Company $company;
    private PingwinSupplier $sup;
    /** @var array<string, array> respostas simuladas por entity_id */
    private array $responses = [];
    /** @var callable|null sync de fornecedores simulada (S2: corre antes da CC noturna) */
    public $syncSuppliers = null;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013200', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->sup = $this->supplier('E1', '44', 'Pingo Doce');

        foreach ([[self::FV, 'V/FT', false], [self::FR, 'FR C', true], [self::NL, 'NL', true], [self::NC, 'V/NC', false]] as [$id, $code, $settled]) {
            PingwinDocumentConfig::create(['company_id' => $this->company->id, 'external_id' => $id, 'code' => $code,
                'description' => $code, 'deleted' => false, 'settled' => $settled, 'synced_at' => now()]);
        }

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            public function fetchSupplierCc(int $companyId, array $entityIds): array
            {
                return $this->test->respond($entityIds);
            }
            public function syncSuppliers(int $companyId): int
            {
                return ($this->test->syncSuppliers ?? fn () => 0)($companyId);
            }
        });
    }

    public function respond(array $entityIds): array
    {
        return array_map(fn ($e) => $this->responses[$e] ?? ['entity_id' => $e, 'ok' => false, 'error' => 'sem resposta simulada'], $entityIds);
    }

    private function supplier(string $pingwinId, string $code, string $name): PingwinSupplier
    {
        return PingwinSupplier::create(['company_id' => $this->company->id, 'pingwin_id' => $pingwinId, 'code' => $code,
            'name' => $name, 'is_active' => true, 'synced_at' => now()]);
    }

    private function doc(string $id, string $cfg, float $total, float $topay, int $sig, string $date, string $due = '', string $ref = ''): array
    {
        // Datas no formato REAL do PingWin: "20260801T00:00:00".
        $pw = static fn (string $d) => str_replace('-', '', $d) . 'T00:00:00';

        return ['docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg, 'document' => " DOC/{$id}",
            'doc_date' => $pw($date), 'due_date' => $due ? $pw($due) : '', 'fiscal_date' => $pw($date),
            'total' => $total, 'total_paid' => round($total - $topay, 2), 'topay' => $topay, 'suspended_value' => 0.0,
            'paid' => $topay == 0.0 ? 1 : 0, 'ca_signal' => $sig, 'iscredit' => $sig === 1 ? 1 : 0, 'isdebit' => $sig === -1 ? 1 : 0,
            'docstatus_description' => 'Fechado', 'docreference_number' => $ref, 'docheader_store_id' => '1099511639284', 'store' => 'Yuko BO'];
    }

    private function ok(string $eid, array $docs, float $balance): array
    {
        return ['entity_id' => $eid, 'ok' => true, 'documents' => $docs, 'balance' => ['balance' => $balance], 'attempts' => 1];
    }

    private function service(): SupplierCcService
    {
        return app(SupplierCcService::class);
    }

    /** Conjunto de documentos com todos os casos. Σ topay×sig = 155,00 (saldo PingWin). */
    private function fullSet(): array
    {
        return [
            $this->doc('1', self::FV, 100.00, 100.00, 1, '2026-08-01', '2026-08-31', '3448'), // aberta, vencida
            $this->doc('2', self::FV, 50.00, 0.00, 1, '2026-08-05', '2026-09-04', '3449'),     // paga pela NL
            $this->doc('3', self::FR, 30.00, 30.00, 1, '2026-08-10', '2026-08-10'),            // auto-paga (Pago)
            $this->doc('4', self::NL, 50.00, 0.00, -1, '2026-08-20'),                          // liquidação
            $this->doc('5', self::NC, 20.00, 20.00, -1, '2026-08-25', '2026-08-25'),           // NC em aberto, vencida
            $this->doc('6', self::FV, 40.00, 40.00, 1, '2026-09-15', '2026-12-31', ''),        // aberta, não vencida
            $this->doc('7', '999', 5.00, 5.00, 1, '2026-09-20', '2026-12-31'),                 // docconfig fora do espelho
        ];
    }

    // ── Sync ────────────────────────────────────────────────────────────────

    public function test_upsert_e_apaga_os_que_faltam(): void
    {
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 10, 1, '2026-08-01'), $this->doc('B', self::FV, 20, 20, 1, '2026-08-02')], 30.00);
        $this->service()->sync($this->company->id);
        $this->assertSame(['A', 'B'], PingwinSupplierCcDocument::orderBy('docheader_id')->pluck('docheader_id')->all());

        // 2.ª sync: A muda (topay 10→4), B desaparece, C entra.
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 4, 1, '2026-08-01'), $this->doc('C', self::FV, 7, 7, 1, '2026-08-03')], 11.00);
        $summary = $this->service()->sync($this->company->id);

        $this->assertSame(['A', 'C'], PingwinSupplierCcDocument::orderBy('docheader_id')->pluck('docheader_id')->all());
        $this->assertSame(400, PingwinSupplierCcDocument::where('docheader_id', 'A')->value('topay_cents'));
        $this->assertSame(['suppliers' => 1, 'ok' => 1, 'failed' => 0, 'documents' => 2, 'reconciled' => 1],
            array_intersect_key($summary, array_flip(['suppliers', 'ok', 'failed', 'documents', 'reconciled'])));
        $bal = PingwinSupplierCcBalance::first();
        $this->assertSame(1100, $bal->balance_cents);
        $this->assertTrue($bal->reconciled);
        $this->assertSame('ok', $bal->sync_status);
    }

    public function test_guarda_fetch_falhado_nao_toca_nos_documentos(): void
    {
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 10, 1, '2026-08-01')], 10.00);
        $this->service()->sync($this->company->id);

        $this->responses['E1'] = ['entity_id' => 'E1', 'ok' => false, 'error' => 'RuntimeError: suppliercc ccdocuments: HTTP 500'];
        $summary = $this->service()->sync($this->company->id);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(['A'], PingwinSupplierCcDocument::pluck('docheader_id')->all());
        $bal = PingwinSupplierCcBalance::first();
        $this->assertSame('failed', $bal->sync_status);
        $this->assertStringContainsString('HTTP 500', $bal->last_error);
        $this->assertSame(1000, $bal->balance_cents); // último saldo bom mantém-se
    }

    public function test_guarda_lista_vazia_suspeita_nao_apaga(): void
    {
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 10, 1, '2026-08-01')], 10.00);
        $this->service()->sync($this->company->id);

        $this->responses['E1'] = $this->ok('E1', [], 0.00);
        $this->service()->sync($this->company->id);

        $this->assertSame(1, PingwinSupplierCcDocument::count());
        $bal = PingwinSupplierCcBalance::first();
        $this->assertSame('failed', $bal->sync_status);
        $this->assertStringContainsString('lista vazia suspeita', $bal->last_error);
    }

    public function test_lista_vazia_sem_documentos_locais_e_ok(): void
    {
        $this->responses['E1'] = $this->ok('E1', [], 0.00);
        $summary = $this->service()->sync($this->company->id);

        $this->assertSame(1, $summary['ok']);
        $this->assertTrue(PingwinSupplierCcBalance::first()->reconciled);
    }

    public function test_reconciled_falso_guarda_os_dados(): void
    {
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 10, 1, '2026-08-01')], 10.01);
        $summary = $this->service()->sync($this->company->id);

        $this->assertSame(0, $summary['reconciled']);
        $this->assertSame(1, PingwinSupplierCcDocument::count());
        $bal = PingwinSupplierCcBalance::first();
        $this->assertFalse($bal->reconciled);
        $this->assertSame('ok', $bal->sync_status);
        $this->assertSame(1001, $bal->balance_cents);
    }

    public function test_falha_num_fornecedor_nao_trava_os_outros(): void
    {
        $mau = $this->supplier('E2', '9', 'Carnes');
        $this->responses['E1'] = $this->ok('E1', [$this->doc('A', self::FV, 10, 10, 1, '2026-08-01')], 10.00);
        $this->responses['E2'] = ['entity_id' => 'E2', 'ok' => false, 'error' => 'boom'];

        $summary = $this->service()->sync($this->company->id);

        $this->assertSame(1, $summary['ok']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame('ok', PingwinSupplierCcBalance::where('supplier_id', $this->sup->id)->value('sync_status'));
        $this->assertSame('failed', PingwinSupplierCcBalance::where('supplier_id', $mau->id)->value('sync_status'));
    }

    // ── Cálculos ────────────────────────────────────────────────────────────

    public function test_quatro_calculos_com_fr_nl_nc_e_docconfig_fora_do_espelho(): void
    {
        $this->responses['E1'] = $this->ok('E1', $this->fullSet(), 155.00);
        $this->service()->sync($this->company->id);

        $b = $this->service()->balances($this->company->id, $this->sup->id, CarbonImmutable::parse('2026-10-09'));

        $this->assertSame(15500, $b['pingwin_balance_cents']);
        $this->assertTrue($b['reconciled']);
        // Real: 100 + 0 − 20 (NC) + 40 + 5 (fora do espelho = não auto-pago) = 125,00
        $this->assertSame(12500, $b['real_balance_cents']);
        // Diferença: FR auto-paga com topay 30 (a NL é auto-paga mas tem topay 0)
        $this->assertSame(3000, $b['difference_cents']);
        // Vencido a 09/10: FV 100 (venc. 31/08) − NC 20 (venc. 25/08) = 80,00
        $this->assertSame(8000, $b['overdue_cents']);
        $this->assertSame($b['pingwin_balance_cents'] - $b['real_balance_cents'], $b['difference_cents']);
    }

    public function test_extrato_periodo_completo_e_invariante(): void
    {
        $this->responses['E1'] = $this->ok('E1', $this->fullSet(), 155.00);
        $this->service()->sync($this->company->id);

        $st = $this->service()->statement($this->company->id, $this->sup->id);

        $this->assertSame(0, $st['opening_cents']);
        $cols = fn ($l) => [$l['document'], $l['supplier_doc'], $l['debit_cents'], $l['credit_cents'], $l['balance_cents']];
        $this->assertSame([
            ['DOC/1', '3448', 0, 10000, 10000],
            ['DOC/2', '3449', 0, 5000, 15000],
            ['DOC/3', null, 3000, 3000, 15000],     // FR auto-paga: crédito e débito ("pago no ato")
            ['DOC/4', null, 5000, 0, 10000],        // NL
            ['DOC/5', null, 2000, 0, 8000],         // NC
            ['DOC/6', null, 0, 4000, 12000],
            ['DOC/7', null, 0, 500, 12500],
        ], array_map($cols, $st['lines']));
        $this->assertSame('Yuko BO', $st['lines'][0]['store']);
        $this->assertSame('2026-08-31', $st['lines'][0]['due_date']);
        $this->assertSame(10000, $st['total_debit_cents']);
        $this->assertSame(22500, $st['total_credit_cents']);
        // INVARIANTE: saldo final do período completo == Saldo real.
        $this->assertSame($this->service()->balances($this->company->id, $this->sup->id)['real_balance_cents'], $st['closing_cents']);
    }

    public function test_extrato_com_periodo_tem_saldo_anterior(): void
    {
        $this->responses['E1'] = $this->ok('E1', $this->fullSet(), 155.00);
        $this->service()->sync($this->company->id);

        $st = $this->service()->statement($this->company->id, $this->sup->id, '2026-08-15', '2026-08-31');

        $this->assertSame(15000, $st['opening_cents']);                      // docs 1-3
        $this->assertSame(['DOC/4', 'DOC/5'], array_column($st['lines'], 'document'));
        $this->assertSame([10000, 8000], array_column($st['lines'], 'balance_cents'));
        $this->assertSame(8000, $st['closing_cents']);
    }

    // ── Refresh, despacho e settled ─────────────────────────────────────────

    public function test_refresh_de_um_fornecedor_fica_queued_e_despacha(): void
    {
        Bus::fake();
        $bal = $this->service()->requestRefresh($this->company->id, $this->sup->id);

        $this->assertSame('queued', $bal->sync_status);
        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => $j->companyId === $this->company->id && $j->supplierIds === [$this->sup->id]);
    }

    public function test_job_de_lote_sincroniza(): void
    {
        $this->responses['E1'] = $this->ok('E1', $this->fullSet(), 155.00);
        SyncPingwinSupplierCcJob::dispatchSync($this->company->id, [$this->sup->id]);

        $this->assertSame(7, PingwinSupplierCcDocument::count());
        $this->assertSame('ok', PingwinSupplierCcBalance::first()->sync_status);
    }

    public function test_despacho_noturno_parte_em_lotes_de_40(): void
    {
        Bus::fake([SyncPingwinSupplierCcJob::class]);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        foreach (range(2, 41) as $i) {
            $this->supplier("E{$i}", (string) $i, "F{$i}");
        }

        (new DispatchSupplierCcSyncJob())->handle($this->service(), app(PingwinService::class));

        Bus::assertDispatchedTimes(SyncPingwinSupplierCcJob::class, 2);
        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => count($j->supplierIds) === 40);
        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => count($j->supplierIds) === 1);
        $this->assertSame(41, PingwinSupplierCcBalance::where('sync_status', 'queued')->count());
    }

    public function test_despacho_noturno_sincroniza_fornecedores_primeiro(): void
    {
        Bus::fake([SyncPingwinSupplierCcJob::class]);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        // A sync de fornecedores traz um fornecedor NOVO → tem de entrar nos lotes desta noite.
        $this->syncSuppliers = function () {
            $this->supplier('E-NOVO', '501', 'Fornecedor novo');

            return 2;
        };

        (new DispatchSupplierCcSyncJob())->handle($this->service(), app(PingwinService::class));

        $novo = PingwinSupplier::where('pingwin_id', 'E-NOVO')->first();
        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => in_array($novo->id, $j->supplierIds, true));
        $this->assertSame('queued', PingwinSupplierCcBalance::where('supplier_id', $novo->id)->value('sync_status'));
    }

    public function test_despacho_noturno_segue_se_a_sync_de_fornecedores_falhar(): void
    {
        Bus::fake([SyncPingwinSupplierCcJob::class]);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->syncSuppliers = fn () => throw new \RuntimeException('Sincronização de fornecedores PingWin falhou: HTTP 500');

        (new DispatchSupplierCcSyncJob())->handle($this->service(), app(PingwinService::class));

        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => $j->supplierIds === [$this->sup->id]);
    }

    public function test_sync_rica_de_docconfigs_preenche_settled(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->app->instance(PingwinService::class, new class extends PingwinService {
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'documents' => [
                    ['id' => '584955579139752236', 'code' => 'FR C', 'description' => 'Fatura-recibo compra', 'deleted' => false,
                     'maindataset' => ['code' => 'FR C', 'settled' => 1], 'children' => [], 'additionalfields' => []],
                    ['id' => '1209', 'code' => 'V/FT', 'description' => 'Fatura de fornecedor', 'deleted' => false,
                     'maindataset' => ['code' => 'V/FT', 'settled' => 0], 'children' => [], 'additionalfields' => []],
                    ['id' => '777', 'code' => 'ANUL', 'description' => 'Anulado', 'deleted' => true],
                ]];
            }
        });

        app(PingwinService::class)->syncDocumentConfigsRich($this->company->id);

        $settled = PingwinDocumentConfig::where('company_id', $this->company->id)->pluck('settled', 'external_id');
        $this->assertTrue($settled['584955579139752236']);
        $this->assertFalse($settled['1209']);
        $this->assertFalse($settled['777']);
    }
}
