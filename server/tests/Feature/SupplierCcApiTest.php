<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinSupplierCcJob;
use App\Models\Company;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierCcBalance;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\SupplierCcService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Conta Corrente de Fornecedor, API da página (S2). Os dados entram pelo
 * SupplierCcService::persistSupplier (o mesmo caminho da sync). Cobre: visão geral com
 * totais IGUAIS aos de balances(), fornecedor ("por liquidar"), extrato com período e loja,
 * refresh 202 + estado, tenancy e gate do módulo.
 */
class SupplierCcApiTest extends TestCase
{
    use RefreshDatabase;

    private const FV = '1209';
    private const FR = '584955579139752236';
    private const NL = '1204';
    private const NC = '1205';

    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinSupplier $mc;
    private PingwinSupplier $carnes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-09 12:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013400', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500013401', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->applyPreset($this->other->id, 'base');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

        foreach ([[self::FV, false], [self::FR, true], [self::NL, true], [self::NC, false]] as [$id, $settled]) {
            PingwinDocumentConfig::create(['company_id' => $this->company->id, 'external_id' => $id, 'code' => $id,
                'description' => $id, 'deleted' => false, 'settled' => $settled, 'synced_at' => now()]);
        }
        $this->mc = $this->supplier('E-MC', '150', "MCDONALD'S", '501111111');
        $this->carnes = $this->supplier('E-CA', '9', 'Carnes SÁdaBANDEIRA', '502222222');
        $this->supplier('E-ZERO', '77', 'Fornecedor sem movimentos', '503333333');

        $cc = app(SupplierCcService::class);
        // McDonald's: 1 FV em aberto (venc. = data) + 4 FR auto-pagas (o caso real do spike).
        $cc->persistSupplier($this->company->id, $this->mc, ['ok' => true, 'balance' => ['balance' => 146.55], 'documents' => [
            $this->doc('m1', self::FV, 52.55, 52.55, 1, '2026-08-26', '2026-08-26', '53028', '1099511639284', 'Yuko BO'),
            $this->doc('m2', self::FR, 6.70, 6.70, 1, '2026-09-11', '2026-09-11', '159983', '1099511639284', 'Yuko BO'),
            $this->doc('m3', self::FR, 28.40, 28.40, 1, '2026-09-20', '2026-09-20', '63376', '1099511639284', 'Yuko BO'),
            $this->doc('m4', self::FR, 51.00, 51.00, 1, '2026-09-24', '2026-09-24', '22748', '1099511639284', 'Yuko BO'),
            $this->doc('m5', self::FR, 7.90, 7.90, 1, '2026-10-03', '2026-10-03', '23801', '1099511639284', 'Yuko BO'),
        ]]);
        // Carnes: duas lojas; FV paga por NL, FV em aberto, NC em aberto. Σ topay×sig = 300 − 50 = 250.
        $cc->persistSupplier($this->company->id, $this->carnes, ['ok' => true, 'balance' => ['balance' => 250.00], 'documents' => [
            $this->doc('c1', self::FV, 100.00, 0.00, 1, '2026-01-10', '2026-02-10', '1001', 'S-A', 'Loja A'),
            $this->doc('c2', self::NL, 100.00, 0.00, -1, '2026-02-15', '', '', 'S-A', 'Loja A'),
            $this->doc('c3', self::FV, 300.00, 300.00, 1, '2026-09-01', '2026-11-30', '', 'S-B', 'Loja B'),
            $this->doc('c4', self::NC, 50.00, 50.00, -1, '2026-09-05', '2026-09-05', '', 'S-B', 'Loja B'),
        ]]);
    }

    private function supplier(string $pid, string $code, string $name, string $nif): PingwinSupplier
    {
        return PingwinSupplier::create(['company_id' => $this->company->id, 'pingwin_id' => $pid, 'code' => $code,
            'name' => $name, 'tax_number' => $nif, 'is_active' => true, 'synced_at' => now()]);
    }

    private function doc(string $id, string $cfg, float $total, float $topay, int $sig, string $date, string $due, string $ref, string $store, string $storeName): array
    {
        $pw = static fn (string $d) => str_replace('-', '', $d) . 'T00:00:00';

        return ['docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg, 'document' => "DOC/{$id}",
            'doc_date' => $pw($date), 'due_date' => $due ? $pw($due) : '', 'fiscal_date' => $pw($date),
            'total' => $total, 'total_paid' => round($total - $topay, 2), 'topay' => $topay, 'paid' => $topay == 0.0 ? 1 : 0,
            'ca_signal' => $sig, 'docreference_number' => $ref, 'docreference_date' => $ref ? $pw($date) : '',
            'docheader_store_id' => $store, 'store' => $storeName, 'docstatus_description' => 'Fechado'];
    }

    private function url(string $suffix = '', ?int $company = null): string
    {
        return '/api/v1/companies/' . ($company ?? $this->company->id) . '/integrations/pingwin/supplier-cc' . $suffix;
    }

    public function test_visao_geral_totais_iguais_ao_service(): void
    {
        $data = $this->actingAs($this->user, 'sanctum')->getJson($this->url())->assertOk()->json('data');

        $rows = collect($data['suppliers'])->keyBy('code');
        $this->assertCount(3, $rows);
        $cc = app(SupplierCcService::class);
        foreach ([$this->mc, $this->carnes] as $s) {
            $b = $cc->balances($this->company->id, $s->id, CarbonImmutable::parse('2026-10-09'));
            $row = $rows[$s->code];
            $this->assertSame($b['pingwin_balance_cents'], $row['pingwin_balance_cents']);
            $this->assertSame($b['real_balance_cents'], $row['real_balance_cents']);
            $this->assertSame($b['difference_cents'], $row['difference_cents']);
            $this->assertSame($b['overdue_cents'], $row['overdue_cents']);
        }
        $this->assertSame([14655, 5255, 9400, 5255, 1, null, 'ok'], [
            $rows['150']['pingwin_balance_cents'], $rows['150']['real_balance_cents'], $rows['150']['difference_cents'],
            $rows['150']['overdue_cents'], $rows['150']['open_docs'], $rows['150']['next_due_date'], $rows['150']['status'],
        ]);
        // Carnes: real 250 (300 − NC 50); vencido = −50 (só a NC venceu); próximo vencimento 30/11.
        $this->assertSame([25000, 0, -5000, 2, '2026-11-30'], [
            $rows['9']['real_balance_cents'], $rows['9']['difference_cents'], $rows['9']['overdue_cents'],
            $rows['9']['open_docs'], $rows['9']['next_due_date'],
        ]);
        $this->assertSame('never', $rows['77']['status']);
        $this->assertSame(['real_balance_cents' => 30255, 'overdue_cents' => 255, 'difference_cents' => 9400,
            'pingwin_balance_cents' => 39655, 'problem_suppliers' => 0], $data['cards']);
    }

    public function test_visao_geral_conta_problemas(): void
    {
        PingwinSupplierCcBalance::where('supplier_id', $this->mc->id)->update(['reconciled' => false]);
        PingwinSupplierCcBalance::where('supplier_id', $this->carnes->id)->update(['sync_status' => 'failed', 'last_error' => 'HTTP 500']);

        $data = $this->actingAs($this->user, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $rows = collect($data['suppliers'])->keyBy('code');

        $this->assertSame('not_reconciled', $rows['150']['status']);
        $this->assertSame('failed', $rows['9']['status']);
        $this->assertSame('HTTP 500', $rows['9']['last_error']);
        $this->assertSame(2, $data['cards']['problem_suppliers']);
    }

    public function test_fornecedor_por_liquidar(): void
    {
        $data = $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$this->carnes->id}"))->assertOk()->json('data');

        $this->assertSame(25000, $data['supplier']['real_balance_cents']);
        $this->assertSame(['DOC/c3', 'DOC/c4'], array_column($data['open'], 'document')); // a FV paga e a NL não aparecem
        [$fv, $nc] = $data['open'];
        $this->assertSame([30000, 30000, 0, false], [$fv['total_cents'], $fv['open_cents'], $fv['days_overdue'], $fv['due_is_doc_date']]);
        $this->assertSame([5000, -5000, 34, true], [$nc['total_cents'], $nc['open_cents'], $nc['days_overdue'], $nc['due_is_doc_date']]);
        $this->assertSame(25000, $data['open_total_cents']);
        $this->assertSame([['id' => 'S-A', 'name' => 'Loja A'], ['id' => 'S-B', 'name' => 'Loja B']], $data['stores']);

        // McDonald's: as FR auto-pagas não aparecem em "por liquidar"; Doc. Fornecedor e a sua data.
        $mc = $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$this->mc->id}"))->json('data');
        $this->assertSame(['DOC/m1'], array_column($mc['open'], 'document'));
        $this->assertSame('53028', $mc['open'][0]['docreference_number']);
        $this->assertSame('2026-08-26', $mc['open'][0]['docreference_date']);
        $this->assertSame(44, $mc['open'][0]['days_overdue']);
    }

    public function test_extrato_periodo_e_loja(): void
    {
        $get = fn (string $qs) => $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url("/{$this->carnes->id}/statement") . $qs)->assertOk()->json('data.statement');

        $full = $get('');
        $this->assertSame(25000, $full['closing_cents']); // = Saldo real (invariante da S1)

        $period = $get('?from=2026-09-01&to=2026-10-09');
        $this->assertSame(0, $period['opening_cents']);   // FV 100 − NL 100 antes do período
        $this->assertSame(['DOC/c3', 'DOC/c4'], array_column($period['lines'], 'document'));
        $this->assertSame(25000, $period['closing_cents']);

        $lojaA = $get('?from=2026-02-01&store=S-A');
        $this->assertSame(10000, $lojaA['opening_cents']); // só a FV de janeiro da Loja A
        $this->assertSame(['DOC/c2'], array_column($lojaA['lines'], 'document'));
        $this->assertSame(0, $lojaA['closing_cents']);

        // McDonald's período completo: 5 linhas, FR "pago no ato" (débito = crédito), saldo 52,55.
        $mc = $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$this->mc->id}/statement"))->json('data.statement');
        $this->assertCount(5, $mc['lines']);
        $this->assertSame([670, 670, true, true], [$mc['lines'][1]['debit_cents'], $mc['lines'][1]['credit_cents'], $mc['lines'][1]['auto_paid'], $mc['lines'][1]['paid_on_issue']]);
        $this->assertFalse($mc['lines'][0]['paid_on_issue']); // FV normal
        // A NL é auto-paga (settled=1) mas NÃO é "pago no ato" — é a própria liquidação.
        $nl = collect($full['lines'])->firstWhere('document', 'DOC/c2');
        $this->assertSame([true, false], [$nl['auto_paid'], $nl['paid_on_issue']]);
        $this->assertSame(5255, $mc['closing_cents']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$this->carnes->id}/statement") . '?from=2026-10-09&to=2026-01-01')->assertStatus(422);
    }

    public function test_refresh_202_e_estado(): void
    {
        Bus::fake([SyncPingwinSupplierCcJob::class]);

        $this->actingAs($this->user, 'sanctum')->postJson($this->url("/{$this->mc->id}/refresh"))
            ->assertStatus(202)->assertJsonPath('data.sync_status', 'queued');
        Bus::assertDispatched(SyncPingwinSupplierCcJob::class, fn ($j) => $j->supplierIds === [$this->mc->id]);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$this->mc->id}/status"))
            ->assertOk()->assertJsonPath('data.sync_status', 'queued');
    }

    public function test_tenancy_e_gate(): void
    {
        // Outra empresa (sem o módulo) não vê esta.
        $this->actingAs($this->otherUser, 'sanctum')->getJson($this->url())->assertStatus(403);
        // Na própria empresa, sem o módulo restauracao_conta_corrente → 403.
        $this->actingAs($this->otherUser, 'sanctum')->getJson($this->url('', $this->other->id))->assertStatus(403);
        // Fornecedor inexistente / de outra empresa → 404.
        $this->actingAs($this->user, 'sanctum')->getJson($this->url('/999999'))->assertStatus(404);
        $alheio = PingwinSupplier::create(['company_id' => $this->other->id, 'pingwin_id' => 'X', 'code' => '1', 'name' => 'Alheio', 'is_active' => true]);
        $this->actingAs($this->user, 'sanctum')->getJson($this->url("/{$alheio->id}"))->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url("/{$alheio->id}/refresh"))->assertStatus(404);
    }
}
