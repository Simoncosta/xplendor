<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PingwinUnit;
use App\Services\Restaurant\RecipeCostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 1 — o custo XPLENDOR das fichas técnicas: compras dos últimos 90 dias (último e médio
 * ponderado, outliers, antigo, sem custo), versão em vigor por data e loja, desperdício, semi-produtos
 * recursivos e ciclos.
 */
class RecipeCostTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
    }

    private function cost(): RecipeCostService
    {
        return (new RecipeCostService($this->company->id))->load();
    }

    private function costOf(RecipeCostService $s, string $pid, ?string $store = null): array
    {
        $v = RecipeCostService::versionInForce($s->versionsOf($pid), '2026-10-15', $store);
        $this->assertNotNull($v, "versão em vigor de {$pid}");

        return $s->costVersion($v, $pid);
    }

    // ── Compras: último e médio ─────────────────────────────────────────────

    public function test_last_and_weighted_average_from_base_quantity(): void
    {
        $today = CarbonImmutable::parse('2026-10-15');
        $lines = [
            ['id' => 1, 'date' => '2026-09-02', 'total' => 240.65, 'qnt_base' => 456],   // 19 grades de 24 → 0,52775
            ['id' => 2, 'date' => '2026-09-30', 'total' => 312.75, 'qnt_base' => 576],   // 0,54297
            ['id' => 3, 'date' => '2026-06-01', 'total' => 999.0, 'qnt_base' => 10],     // fora dos 90 dias
        ];
        $s = RecipeCostService::purchaseStats($lines, $today);

        $this->assertSame('ok', $s['status']);
        $this->assertEqualsWithDelta(312.75 / 576, $s['last'], 1e-6);                  // a mais recente
        $this->assertEqualsWithDelta((240.65 + 312.75) / (456 + 576), $s['avg'], 1e-6); // ponderado pela quantidade
        $this->assertSame(['2026-09-30', 2, 0], [$s['last_date'], $s['lines'], $s['outliers']]);
    }

    public function test_outliers_above_4x_or_below_a_quarter_of_the_median_are_excluded(): void
    {
        $today = CarbonImmutable::parse('2026-10-15');
        $lines = [
            ['id' => 1, 'date' => '2026-09-01', 'total' => 10.0, 'qnt_base' => 10],   // 1,00
            ['id' => 2, 'date' => '2026-09-02', 'total' => 11.0, 'qnt_base' => 10],   // 1,10
            ['id' => 3, 'date' => '2026-09-03', 'total' => 12.0, 'qnt_base' => 10],   // 1,20
            ['id' => 4, 'date' => '2026-09-04', 'total' => 60.0, 'qnt_base' => 10],   // 6,00 > 4 × 1,15
            ['id' => 5, 'date' => '2026-09-05', 'total' => 2.0, 'qnt_base' => 10],    // 0,20 < 1,15 / 4
        ];
        $s = RecipeCostService::purchaseStats($lines, $today);

        $this->assertSame(2, $s['outliers']);
        $this->assertEqualsWithDelta(33.0 / 30, $s['avg'], 1e-6);
        $this->assertEqualsWithDelta(1.2, $s['last'], 1e-6); // a última que conta (a de 6,00 e a de 0,20 ficaram de fora)
    }

    public function test_old_price_and_no_cost(): void
    {
        $today = CarbonImmutable::parse('2026-10-15');
        $old = RecipeCostService::purchaseStats([
            ['id' => 1, 'date' => '2026-03-01', 'total' => 8.0, 'qnt_base' => 4],
            ['id' => 2, 'date' => '2026-05-01', 'total' => 9.0, 'qnt_base' => 3],
        ], $today);
        $this->assertSame(['antigo', 3.0, 3.0, '2026-05-01'], [$old['status'], $old['last'], $old['avg'], $old['last_date']]);

        $none = RecipeCostService::purchaseStats([], $today);
        $this->assertSame(['sem_custo', null, null], [$none['status'], $none['last'], $none['avg']]);
    }

    public function test_only_closed_supplier_invoices_count(): void
    {
        $this->persistBom($this->company->id, $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [$this->bomLine('X', 1)]]]));
        $this->purchase($this->company->id, 'X', '2026-09-01', 10, 10, 1000);                               // 1,00 conta
        $this->purchase($this->company->id, 'X', '2026-09-02', 10, 10, 5000, ['docconfig_id' => '1205']); // nota de crédito: não
        $this->purchase($this->company->id, 'X', '2026-09-03', 10, 10, 5000, ['docstatus_id' => '8001']); // não fechado: não

        $p = $this->cost()->purchaseOf('X');
        $this->assertSame([1, 1.0], [$p['lines'], $p['avg']]);
    }

    // ── Versão em vigor ───────────────────────────────────────────────────────

    public function test_version_in_force_by_date_and_store(): void
    {
        $v = fn ($key, $from, $stores, $deleted = false) => ['bom_id' => "B{$key}", 'key' => $key, 'valid_from' => $from, 'deleted' => $deleted,
            'stores' => array_map(fn ($sid, $a) => ['store_id' => $sid, 'active' => $a], array_keys($stores), array_values($stores)), 'lines' => []];
        $versions = [
            $v('00000000', '2026-05-06', ['BO' => true, 'BAIXA' => true, 'ORIGINAL' => false]),
            $v('00000001', '2026-09-01', ['BO' => true, 'BAIXA' => false, 'ORIGINAL' => false]),
            $v('00000002', '2026-10-20', ['BO' => true, 'BAIXA' => true, 'ORIGINAL' => true]),   // futura
            $v('00000003', '2026-10-01', ['BO' => true, 'BAIXA' => true, 'ORIGINAL' => true], true), // anulada
        ];

        $this->assertSame('00000001', RecipeCostService::versionInForce($versions, '2026-10-15', 'BO')['key']);
        $this->assertSame('00000000', RecipeCostService::versionInForce($versions, '2026-10-15', 'BAIXA')['key']); // a de setembro não tem a Baixa
        $this->assertNull(RecipeCostService::versionInForce($versions, '2026-10-15', 'ORIGINAL'));                 // a loja fica sem ficha em vigor
        $this->assertSame('00000002', RecipeCostService::versionInForce($versions, '2026-10-20', 'ORIGINAL')['key']);
        $this->assertNull(RecipeCostService::versionInForce($versions, '2026-05-05', 'BO'));                       // antes da primeira
        $this->assertSame('00000001', RecipeCostService::versionInForce($versions, '2026-10-15')['key']);          // sem loja: alguma ativa

        // Mesma data: conta a criada depois (key maior).
        $tie = [$v('00000000', '2026-06-02', ['BO' => true]), $v('00000001', '2026-06-02', ['BO' => true])];
        $this->assertSame('00000001', RecipeCostService::versionInForce($tie, '2026-10-15', 'BO')['key']);
    }

    // ── Custo da ficha ─────────────────────────────────────────────────────────

    public function test_coca_cola_0_comes_out_around_53_cents_and_is_compared_with_pingwin(): void
    {
        // A ficha: 1 garrafa; compras em grades de 24 (qnt_base = 24 × qnt), com desconto já no total.
        $line = $this->bomLine('GARRAFA', 1, ['unit_cost' => 0.5275, 'cost' => 0.5275]);
        $this->persistBom($this->company->id, $this->bom('COCA', [['00000000', 'BCOCA', '2026-05-06', ['S1' => true], [$line]]]));
        $this->purchase($this->company->id, 'GARRAFA', '2026-08-27', 20, 480, 25332, ['unit_desc' => 'Grade 24']);
        $this->purchase($this->company->id, 'GARRAFA', '2026-09-09', 20, 480, 25332, ['unit_desc' => 'Grade 24']);
        $this->purchase($this->company->id, 'GARRAFA', '2026-09-30', 576, 576, 31275);

        $c = $this->costOf($this->cost(), 'COCA');
        $this->assertEqualsWithDelta(0.53, $c['cost'], 0.01);
        $this->assertTrue($c['complete']);
        $this->assertEqualsWithDelta(0.5275, $c['pingwin_cost'], 1e-6);
        $this->assertLessThan(20, abs($c['diff_pct']));
        $this->assertSame('compras', $c['lines'][0]['cost_source']);
        $this->assertEqualsWithDelta(312.75 / 576, $c['lines'][0]['unit_cost_last'], 1e-6);
    }

    public function test_waste_is_added_and_recovery_is_only_shown(): void
    {
        $line = $this->bomLine('CEBOLA', 0.6, ['waste' => 10.0, 'recovery' => 5.0, 'unit_desc' => 'Quilograma']);
        $this->persistBom($this->company->id, $this->bom('MOLHO', [['00000000', 'BM', '2026-05-26', ['S1' => true], [$line]]]));
        $this->purchase($this->company->id, 'CEBOLA', '2026-09-10', 15, 15, 1500); // 1,00 €/kg

        $c = $this->costOf($this->cost(), 'MOLHO');
        // 0,6 × 1,00 × (1 + 10/100) = 0,66 (pressuposto: a fórmula do PingWin com desperdício não foi confirmada).
        $this->assertEqualsWithDelta(0.66, $c['lines'][0]['line_cost'], 1e-6);
        $this->assertEqualsWithDelta(0.66, $c['cost'], 1e-6);
        $this->assertSame(5.0, $c['lines'][0]['recovery']);
    }

    public function test_semi_products_are_costed_recursively(): void
    {
        // Francesinha = 0,01 L de molho (semi-produto) + 0,15 kg de bife; molho = 6 L de vinho + 5 kg de tomate.
        $this->persistBom($this->company->id, $this->bom('MOLHO', [['00000000', 'BM', '2026-05-26', ['BO' => true, 'BAIXA' => false],
            [$this->bomLine('VINHO', 6, ['unit_desc' => 'Litro']), $this->bomLine('TOMATE', 5)]]]));
        $this->persistBom($this->company->id, $this->bom('FRANCESINHA', [['00000000', 'BF', '2026-05-26', ['BO' => true, 'BAIXA' => true],
            [$this->bomLine('MOLHO', 0.01, ['isbom' => 1]), $this->bomLine('BIFE', 0.15)]]]));
        $this->purchase($this->company->id, 'VINHO', '2026-09-10', 6, 6, 600);     // 1,00 €/L
        $this->purchase($this->company->id, 'TOMATE', '2026-09-10', 5, 5, 1000);   // 2,00 €/kg
        $this->purchase($this->company->id, 'BIFE', '2026-09-10', 10, 10, 13000);  // 13,00 €/kg

        $c = $this->costOf($this->cost(), 'FRANCESINHA', 'BAIXA');
        $molho = $c['lines'][0];
        $this->assertSame(['ficha', 'ok'], [$molho['cost_source'], $molho['cost_status']]);
        $this->assertEqualsWithDelta(16.0, $molho['unit_cost_avg'], 1e-6);       // 6 × 1 + 5 × 2, por unidade base
        $this->assertEqualsWithDelta(0.16, $molho['line_cost'], 1e-6);
        $this->assertCount(2, $molho['sub']['lines']);
        $this->assertEqualsWithDelta(0.16 + 1.95, $c['cost'], 1e-6);
        $this->assertTrue($c['complete']);
    }

    public function test_a_cycle_does_not_loop_and_is_warned(): void
    {
        $this->persistBom($this->company->id, $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [$this->bomLine('B', 1, ['isbom' => 1])]]]));
        $this->persistBom($this->company->id, $this->bom('B', [['00000000', 'BB', '2026-05-01', ['S1' => true],
            [$this->bomLine('A', 1, ['isbom' => 1]), $this->bomLine('X', 2)]]]));
        $this->purchase($this->company->id, 'X', '2026-09-10', 1, 1, 100);

        $c = $this->costOf($this->cost(), 'A');
        $b = $c['lines'][0];
        $this->assertSame('parcial', $b['cost_status']);                        // B tem uma linha (A) sem custo
        $this->assertSame('ciclo', $b['sub']['lines'][0]['cost_status']);
        $this->assertContains('cycle', array_column($b['sub']['lines'][0]['warnings'], 'type'));
        $this->assertEqualsWithDelta(2.0, $c['cost'], 1e-6);                     // o parcial (2 × 1,00)
        $this->assertFalse($c['complete']);
    }

    public function test_warnings_inactive_no_cost_old_unit_factor_and_pingwin_difference(): void
    {
        PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => '11001', 'description' => 'Unidade', 'shortname' => 'UN', 'unit_value' => 1]);
        PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => 'CX24', 'description' => 'Caixa 24 Unidades', 'shortname' => 'CX 24',
            'parent_pingwin_id' => '11001', 'unit_value' => 1]);
        $this->catalog($this->company->id, 'CAIXA', ['default_purchase_unit_id' => 'CX24']);
        $this->persistBom($this->company->id, $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [
            $this->bomLine('INATIVO', 1, ['status' => 2]),
            $this->bomLine('SEMCOMPRAS', 1, ['unit_cost' => 0.3, 'cost' => 0.3]), // a PingWin tem custo: não é subproduto
            $this->bomLine('ANTIGO', 1),
            $this->bomLine('CAIXA', 1, ['unit_cost' => 0.5, 'cost' => 0.5]),
        ]]]));
        $this->purchase($this->company->id, 'INATIVO', '2026-09-10', 1, 1, 100);
        $this->purchase($this->company->id, 'ANTIGO', '2026-04-10', 1, 1, 100);
        $this->purchase($this->company->id, 'CAIXA', '2026-09-10', 1, 1, 1200); // 12,00 "por unidade" (a caixa a fator 1)

        $c = $this->costOf($this->cost(), 'A');
        $types = fn ($i) => array_column($c['lines'][$i]['warnings'], 'type');
        $this->assertContains('inactive', $types(0));
        $this->assertContains('no_cost', $types(1));
        $this->assertContains('old_cost', $types(2));
        $this->assertContains('unit_factor', $types(3));
        $this->assertSame(2300.0, $c['lines'][3]['diff_pct']);                   // 12,00 contra 0,50 do PingWin
        $this->assertFalse($c['complete']);
    }

    public function test_cost_is_null_when_no_line_has_a_cost(): void
    {
        $this->persistBom($this->company->id, $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [$this->bomLine('NADA', 1, ['unit_cost' => 1, 'cost' => 1])]]]));
        $c = $this->costOf($this->cost(), 'A');
        $this->assertNull($c['cost']);
        $this->assertFalse($c['complete']);
    }
}
