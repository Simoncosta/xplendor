<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Menu\FichaSemComprasRule;
use App\Audit\Rules\Restaurant\Menu\FoodCostAcimaAlvoRule;
use App\Jobs\ComputeMenuEngineeringJob;
use App\Models\MenuEngineeringExclusion;
use App\Models\MenuEngineeringItem;
use App\Models\MenuEngineeringSetting;
use App\Models\MenuEngineeringSnapshot;
use App\Models\PermissionProfile;
use App\Models\PingwinUnit;
use App\Models\ProductBomYield;
use App\Models\ProductUnitConversion;
use App\Models\User;
use App\Services\Restaurant\Menu\MenuEngineeringService;
use App\Services\Restaurant\Menu\MenuMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 3 — engenharia de menu: os limiares de Kasavana e Smith (exemplo de livro), o IVA e o preço
 * médio, o custo pesado por versão da ficha (dia e loja de venda), as conversões confirmadas contra o
 * fator do PingWin, o semi-produto recursivo e o ciclo, a confiança, as exclusões, a classe frágil, o
 * food cost por compras com as famílias, as regras M1 e M2 e o ecrã (snapshot, ListSort, CSV, ACL).
 */
class MenuEngineeringTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private const FROM = '2026-07-17';
    private const TO = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();   // hoje: 15/10/2026; lojas S1 (Baixa) e S2 (Costa Cabral)
    }

    private function compute(?int $loc = null, string $mode = 'media'): array
    {
        return (new MenuEngineeringService($this->c))->compute(self::FROM, self::TO, $loc, $mode);
    }

    private function item(array $res, string $pid): array
    {
        foreach ($res['items'] as $i) {
            if ($i['product_id'] === $pid) {
                return $i;
            }
        }
        $this->fail("artigo {$pid} não está no cálculo");
    }

    /** Um prato com ficha de uma linha (componente $comp × $qty) válida desde $from nas lojas dadas. */
    private function dish(string $pid, string $comp, float $qty, array $stores = ['S1' => true, 'S2' => true], string $from = '2026-05-01', string $family = 'Família \\ Comidas \\ Pratos'): void
    {
        $this->catalog($this->c, $pid, ['description' => "Prato {$pid}", 'family' => $family, 'forsale' => true, 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom($pid, [['00000000', "B{$pid}", $from, $stores, [$this->bomLine($comp, $qty)]]]));
    }

    private function sell(string $pid, int $loc, string $date, float $qty, int $netCents, string $family = 'Família \\ Comidas \\ Pratos', int $taxCents = 0): void
    {
        DB::table('pingwin_item_sales_daily')->insert([
            'company_id' => $this->c, 'location_id' => $loc, 'business_date' => $date, 'product_pingwin_id' => $pid, 'product_code' => "P{$pid}",
            'product_name' => "Prato {$pid}", 'family_path' => $family, 'quantity' => $qty, 'net_cents' => $netCents, 'tax_cents' => $taxCents,
            'gross_cents' => $netCents + $taxCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Kasavana e Smith ──────────────────────────────────────────────────

    public function test_kasavana_smith_textbook_example(): void
    {
        // 7 pratos, 1 200 vendidos. Limiar da popularidade: 70 % × 1/7 = 10 % (120 unidades).
        // Margem média pesada = 8 924,25 ÷ 1 200 = 7,436875.
        $menu = [
            ['Camarão', 210, 12.95, 4.25], ['Frango', 420, 9.95, 3.10], ['Bife', 60, 16.95, 7.80], ['Porco', 225, 11.95, 3.95],
            ['Peixe', 180, 10.95, 4.60], ['Massa', 90, 8.95, 2.40], ['Vitela', 15, 15.95, 6.70],
        ];
        $res = MenuMatrix::classify(array_map(fn ($m) => ['id' => $m[0], 'family' => 'Pratos', 'qty' => $m[1], 'revenue' => $m[1] * $m[2],
            'price' => $m[2], 'cost' => $m[3], 'confidence' => 'confirmado'], $menu));
        $this->assertSame(['Camarão' => 'estrela', 'Frango' => 'cavalo', 'Bife' => 'quebra_cabecas', 'Porco' => 'estrela', 'Peixe' => 'cavalo',
            'Massa' => 'cao', 'Vitela' => 'quebra_cabecas'], array_map(fn ($i) => $i['class'], $res['items']));
        $this->assertEqualsWithDelta(7.436875, $res['families']['Pratos']['margin_line'], 1e-4);
        $this->assertEqualsWithDelta(10.0, $res['families']['Pratos']['popularity_threshold_pct'], 1e-9);
        $this->assertSame([], $res['families']['Pratos']['fragile']);
        // Exatamente no limiar conta como muito vendido; um artigo sem custo fica fora (sem classe), mas conta no N.
        $edge = MenuMatrix::classify([
            ['id' => 'a', 'family' => 'F', 'qty' => 35, 'revenue' => 350, 'price' => 10, 'cost' => 4, 'confidence' => 'confirmado'],
            ['id' => 'b', 'family' => 'F', 'qty' => 465, 'revenue' => 4650, 'price' => 10, 'cost' => 2, 'confidence' => 'confirmado'],
            ['id' => 'c', 'family' => 'F', 'qty' => 500, 'revenue' => 5000, 'price' => 10, 'cost' => null, 'confidence' => 'sem_custo'],
        ]);
        $this->assertTrue($edge['items']['a']['popular'] === false);             // 3,5 % < 70 % × 1/3
        $this->assertNull($edge['items']['c']['class']);
        $this->assertTrue($edge['items']['b']['fragile']);                       // só 2 com custo, preços iguais, cobertura 50 %
        $this->assertSame(['poucos_artigos', 'precos_iguais', 'cobertura_baixa'], array_column($edge['families']['F']['fragile'], 'code'));
    }

    // ── Receita, IVA e custo ───────────────────────────────────────────────

    public function test_revenue_is_without_vat_and_price_is_revenue_over_quantity(): void
    {
        $this->catalog($this->c, 'AGUA', ['description' => 'Água', 'forsale' => true]);
        $this->sell('AGUA', $this->locBaixa, '2026-09-01', 10, 1900, 'Família \\ Bebidas \\ Agua', 437);  // 23 %
        $this->sell('AGUA', $this->locCosta, '2026-10-01', 10, 2000, 'Família \\ Bebidas \\ Agua', 120);  // 6 %
        $i = $this->item($this->compute(), 'AGUA');
        $this->assertSame([3900, 557, 1.95, 20.0, 'sem_custo'], [$i['revenue_cents'], $i['tax_cents'], $i['price'], $i['qty'], $i['confidence']]);
        $this->assertSame('Bebidas › Agua', $i['family']);
        $this->assertEqualsWithDelta(14.3, $i['detail']['vat_pct'], 0.05);
    }

    public function test_cost_is_weighted_by_the_version_in_force_on_each_sale_day_and_store(): void
    {
        $this->catalog($this->c, 'CARNE', ['description' => 'Carne', 'forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'CARNE', '2026-09-20', 10, 10, 1000);   // 1,00 €/un
        $this->catalog($this->c, 'PRATO', ['description' => 'Prato', 'family' => 'Família \\ Comidas \\ Pratos', 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom('PRATO', [
            ['00000000', 'BV1', '2026-07-01', ['S1' => true, 'S2' => true], [$this->bomLine('CARNE', 2)]],   // 2,00 €
            ['00000001', 'BV2', '2026-09-01', ['S1' => true, 'S2' => true], [$this->bomLine('CARNE', 3)]],   // 3,00 €
        ]));
        $this->sell('PRATO', $this->locBaixa, '2026-08-10', 10, 10000);
        $this->sell('PRATO', $this->locCosta, '2026-09-10', 30, 30000);
        $i = $this->item($this->compute(), 'PRATO');
        $this->assertEqualsWithDelta((10 * 2 + 30 * 3) / 40, $i['cost'], 1e-6);
        $this->assertSame('confirmado', $i['confidence']);
        $this->assertTrue($i['detail']['changed']);   // "Ficha alterada no período"
        $this->assertSame([10.0, 30.0], array_column($i['detail']['versions'], 'qty'));
        $this->assertEqualsWithDelta(10 - 2.75, $i['margin'], 1e-6);
        // Só a Baixa: só a versão 1.
        $this->assertEqualsWithDelta(2.0, $this->item($this->compute($this->locBaixa), 'PRATO')['cost'], 1e-6);
        // Uma loja sem ficha em vigor: as vendas dela ficam sem custo e o prato fica Parcial.
        $this->persistBom($this->c, $this->bom('PRATO', [['00000000', 'BV1', '2026-07-01', ['S1' => true, 'S2' => false], [$this->bomLine('CARNE', 2)]]]));
        $p = $this->item($this->compute(), 'PRATO');
        $this->assertSame(['parcial', 30.0], [$p['confidence'], $p['detail']['uncovered_qty']]);
        $this->assertSame('loja_sem_ficha', $p['detail']['missing'][0]['reason']);
    }

    public function test_confirmed_conversion_rules_and_the_pingwin_factor_is_never_used(): void
    {
        // A grade do PingWin vale 1 unidade (fator distorcido); a XPLENDOR confirma 24.
        PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => 'GRD24', 'description' => 'Grade 24', 'parent_pingwin_id' => '11001', 'unit_value' => 1]);
        $this->catalog($this->c, 'LATA', ['description' => 'Lata', 'forpurchase' => true, 'forsale' => false, 'base_unit_id' => '11001']);
        $this->purchase($this->c, 'LATA', '2026-09-20', 2, 2, 1200, ['unit_id' => 'GRD24', 'unit_desc' => 'Grade 24']);   // 12 € por 2 grades
        $this->dish('COLA', 'LATA', 1, family: 'Família \\ Bebidas \\ Sumos');
        $this->sell('COLA', $this->locBaixa, '2026-10-01', 100, 25000, 'Família \\ Bebidas \\ Sumos');

        $res = $this->compute();
        $i = $this->item($res, 'COLA');
        $this->assertSame('parcial', $i['confidence']);
        $this->assertSame([['component_id' => 'LATA', 'code' => 'CLATA', 'description' => 'Componente LATA', 'reason' => 'sem_conversao', 'unit' => 'Grade 24']],
            $i['detail']['missing']);
        $this->assertSame(0.0, $i['cost']);          // "pelo menos 0 €": a lata não tem preço
        $work = collect($res['data']['worklist'])->firstWhere('product_id', 'COLA');
        $this->assertSame(['confirmar_fator', 'LATA'], [$work['actions'][0]['type'], $work['actions'][0]['product_id']]);

        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'LATA', 'unit_key' => 'GRD24', 'unit_label' => 'Grade 24',
            'supplier_nif' => '', 'factor_to_base' => 24, 'source' => 'confirmado']);
        $i = $this->item($this->compute(), 'COLA');
        $this->assertSame('confirmado', $i['confidence']);
        $this->assertEqualsWithDelta(12 / 48, $i['cost'], 1e-6);   // 12 € ÷ (2 × 24), nunca ÷ 2
        $this->assertEqualsWithDelta(0.25 / 2.5 * 100, $i['food_cost_pct'], 1e-6);
    }

    public function test_last_cost_mode_and_header_discount_warning(): void
    {
        $this->catalog($this->c, 'OLEO', ['forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'OLEO', '2026-09-01', 10, 10, 1000);   // 1,00
        $this->purchase($this->c, 'OLEO', '2026-10-01', 10, 10, 2000);   // 2,00
        DB::table('pingwin_supplier_documents')->where('docheader_id', 'D2')->update(['discount1' => 5]);
        $this->dish('FRITO', 'OLEO', 1);
        $this->sell('FRITO', $this->locBaixa, '2026-10-02', 20, 10000);
        $this->assertEqualsWithDelta(1.5, $this->item($this->compute(null, 'media'), 'FRITO')['cost'], 1e-6);
        $last = $this->item($this->compute(null, 'ultimo'), 'FRITO');
        $this->assertEqualsWithDelta(2.0, $last['cost'], 1e-6);
        $this->assertContains('header_discount', array_column($last['detail']['warnings'], 'type'));
    }

    public function test_semi_product_recursive_with_confirmed_yield_cycle_and_partial(): void
    {
        $this->catalog($this->c, 'CEBOLA', ['forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'CEBOLA', '2026-10-01', 10, 10, 2000);   // 2,00 €/kg
        // Molho (lote de 10): 5 kg de cebola = 10 €; por unidade 1 € (rendimento confirmado 10).
        $this->catalog($this->c, 'MOLHO', ['forsale' => false, 'forproduction' => true]);
        $this->persistBom($this->c, $this->bom('MOLHO', [['00000000', 'BMOLHO', '2026-05-01', ['BO' => true, 'S1' => true], [$this->bomLine('CEBOLA', 5)]]]));
        $this->catalog($this->c, 'FRAN', ['description' => 'Francesinha', 'family' => 'Família \\ Comidas \\ Francesinhas', 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BFRAN', '2026-05-01', ['S1' => true], [$this->bomLine('MOLHO', 0.3, ['isbom' => 1])]]]));
        $this->sell('FRAN', $this->locBaixa, '2026-10-01', 10, 13000, 'Família \\ Comidas \\ Francesinhas');

        $i = $this->item($this->compute(), 'FRAN');
        $this->assertSame('parcial', $i['confidence']);                       // rendimento por confirmar
        $this->assertSame('semi_sem_rendimento', $i['detail']['missing'][0]['reason']);
        ProductBomYield::create(['company_id' => $this->c, 'product_pingwin_id' => 'MOLHO', 'bom_pingwin_id' => 'BMOLHO', 'quantity' => 10, 'source' => 'confirmado', 'confirmed_at' => now()]);
        $i = $this->item($this->compute(), 'FRAN');
        $this->assertSame('confirmado', $i['confidence']);
        $this->assertEqualsWithDelta(0.3 * 1.0, $i['cost'], 1e-6);

        // Ciclo: o molho passa a usar a francesinha.
        $this->persistBom($this->c, $this->bom('MOLHO', [['00000000', 'BMOLHO', '2026-05-01', ['BO' => true, 'S1' => true],
            [$this->bomLine('CEBOLA', 5), $this->bomLine('FRAN', 1, ['_i' => 1, 'isbom' => 1])]]]));
        $i = $this->item($this->compute(), 'FRAN');
        $this->assertSame('parcial', $i['confidence']);
        $this->assertContains($i['detail']['missing'][0]['reason'], ['semi_parcial', 'ciclo']);
    }

    // ── Confiança, exclusões e classe frágil ───────────────────────────────

    public function test_confidence_exclusions_and_toggles(): void
    {
        $this->catalog($this->c, 'CARNE', ['forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'CARNE', '2026-10-01', 10, 10, 1000);
        $this->dish('BOM', 'CARNE', 2);
        $this->sell('BOM', $this->locBaixa, '2026-10-05', 100, 100000);
        $this->catalog($this->c, 'SEMF', ['forsale' => true]);
        $this->sell('SEMF', $this->locBaixa, '2026-10-05', 100, 50000);                      // sem ficha → Sem custo
        $this->sell('SSAL', $this->locBaixa, '2026-10-05', 40, 0);                            // modificador: preço 0
        $this->sell('POUCO', $this->locBaixa, '2026-10-05', 3, 900);                          // menos de 5 unidades
        $this->sell('VELHO', $this->locBaixa, '2026-08-01', 50, 5000);                        // sem vendas nos últimos 30 dias
        $this->sell('UBER', $this->locBaixa, '2026-10-05', 50, 9000, 'Família \\ Uber Eats \\ Comida');
        $this->sell('MANUAL', $this->locBaixa, '2026-10-05', 50, 5000);
        MenuEngineeringExclusion::create(['company_id' => $this->c, 'product_id' => 'MANUAL', 'reason' => 'Prato de staff']);

        $res = $this->compute();
        $ex = collect($res['items'])->pluck('excluded', 'product_id')->all();
        $this->assertEquals(['BOM' => null, 'SEMF' => null, 'SSAL' => 'preco_zero', 'POUCO' => 'pouco_vendido', 'VELHO' => 'retirado', 'UBER' => 'uber_eats', 'MANUAL' => 'manual'],
            array_intersect_key($ex, array_flip(['BOM', 'SEMF', 'SSAL', 'POUCO', 'VELHO', 'UBER', 'MANUAL'])));
        $this->assertSame('Excluído à mão: Prato de staff', $this->item($res, 'MANUAL')['detail']['excluded_reason']);
        $this->assertSame(['confirmado', 'sem_custo'], [$this->item($res, 'BOM')['confidence'], $this->item($res, 'SEMF')['confidence']]);
        $this->assertNull($this->item($res, 'SEMF')['class']);   // sem custo: fora da matriz
        $this->assertTrue($this->item($res, 'BOM')['fragile']);  // família com 1 artigo com custo
        // Cobertura: BOM (1 000 €) com custo; SEMF, POUCO, VELHO, UBER, MANUAL sem custo.
        $this->assertEqualsWithDelta(1000 / (1000 + 500 + 9 + 50 + 90 + 50) * 100, $res['data']['summary']['coverage']['confirmado_pct'], 0.1);
        $this->assertSame('criar_ficha', collect($res['data']['worklist'])->firstWhere('product_id', 'SEMF')['actions'][0]['type']);

        MenuEngineeringSetting::create(['company_id' => $this->c, 'include_uber_eats' => true, 'include_zero_price' => true]);
        $ex = collect($this->compute()['items'])->pluck('excluded', 'product_id')->all();
        $this->assertSame([null, null], [$ex['UBER'], $ex['SSAL']]);
    }

    // ── Food cost por compras ──────────────────────────────────────────────

    public function test_food_cost_from_purchases_with_the_configured_families(): void
    {
        $fam = fn ($id, $d, $p) => DB::table('pingwin_families')->insert(['company_id' => $this->c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $fam('F0', 'Família', null);
        $fam('FC', 'COMPRAS', 'F0');
        $fam('FCC', 'COMIDAS', 'FC');
        $fam('FCCP', 'PRODUÇÃO', 'FCC');
        $fam('FCB', 'BEBIDAS', 'FC');
        $fam('FCV', 'Cauções de vasilhames - fornecedores', 'FC');
        $fam('FS', 'FORNECIMENTOS E SERV EXTERNOS', 'F0');
        $this->catalog($this->c, 'CARNE', ['family_pingwin_id' => 'FCCP', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, 'CERVEJA', ['family_pingwin_id' => 'FCB', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, 'BARRIL', ['family_pingwin_id' => 'FCV', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, 'LUZ', ['family_pingwin_id' => 'FS', 'forpurchase' => true, 'forsale' => false]);
        foreach (['2026-07', '2026-08', '2026-09'] as $i => $m) {
            $this->sell('X', $this->locBaixa, "{$m}-15", 100, 1000000);                             // 10 000 € de vendas por mês
            $this->purchase($this->c, 'CARNE', "{$m}-10", 1, 1, 200000 + $i * 10000);               // 2 000, 2 100, 2 200 €
            $this->purchase($this->c, 'CERVEJA', "{$m}-11", 1, 1, 50000, ['docconfig_id' => '584955579139752236']);   // fatura-recibo: 500 €
            $this->purchase($this->c, 'BARRIL', "{$m}-12", 1, 1, 99900);                            // vasilhame: fora
            $this->purchase($this->c, 'LUZ', "{$m}-13", 1, 1, 77700);                               // fornecimentos: fora
        }
        $this->purchase($this->c, 'CARNE', '2026-09-20', 1, 1, 30000, ['docconfig_id' => '1205']);  // nota de crédito: − 300 €
        $this->purchase($this->c, 'CARNE', '2026-09-21', 1, 1, 99999, ['docstatus_id' => '8001']); // aberta: não conta

        $fc = (new MenuEngineeringService($this->c))->purchaseFoodCost('2026-09-30');
        $sep = collect($fc['months'])->firstWhere('month', '2026-09');
        $this->assertSame([1900.0, 500.0, 2400.0, 19.0, 5.0, 24.0], [$sep['comida_eur'], $sep['bebida_eur'], $sep['total_eur'], $sep['comida_pct'], $sep['bebida_pct'], $sep['total_pct']]);
        // Média de 3 meses: (2 500 + 2 600 + 2 400) ÷ 30 000.
        $this->assertSame(25.0, $sep['avg3_pct']);
        $this->assertSame(['month' => '2026-09', 'avg3_pct' => 25.0, 'total_pct' => 24.0], $fc['latest']);
    }

    // ── Auditoria M1 e M2 ──────────────────────────────────────────────────

    public function test_m1_food_cost_above_target_and_m2_recipe_with_an_article_without_purchases(): void
    {
        $this->catalog($this->c, 'CARNE', ['forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'CARNE', '2026-10-01', 10, 10, 5000);   // 5,00 €
        $this->dish('CARO', 'CARNE', 1);                                   // custo 5 € num prato a 10 € → 50 %
        $this->sell('CARO', $this->locBaixa, '2026-10-05', 100, 100000);
        // Coca Cola 0: a ficha usa o 100366, que não tem compras.
        $this->catalog($this->c, '100366', ['code' => '100366', 'description' => 'COCA-COLA ZERO GARRAFA', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, 'COCA', ['description' => 'Coca Cola 0', 'family' => 'Família \\ Bebidas \\ Sumos', 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom('COCA', [['00000000', 'BCOCA', '2026-05-06', ['S1' => true, 'S2' => true],
            [$this->bomLine('100366', 1, ['component_description' => 'COCA-COLA ZERO GARRAFA', 'component_code' => '100366', 'unit_cost' => 0.5275, 'cost' => 0.5275])]]]));
        $this->sell('COCA', $this->locBaixa, '2026-10-05', 500, 117000, 'Família \\ Bebidas \\ Sumos');
        (new MenuEngineeringService($this->c))->snapshot(self::FROM, self::TO, null, 'media');

        $scope = new AuditScope($this->c, 'restauracao', 'menu');
        $m1 = iterator_to_array(app(FoodCostAcimaAlvoRule::class)->run($this->c, $scope), false);
        $this->assertSame(['CARO'], array_map(fn ($f) => $f->entityId, $m1));
        $this->assertSame((int) round((5 - 0.35 * 10) * 100 * 100), $m1[0]->impactCents);   // (custo − 35 % × preço) × quantidade
        $this->assertStringContainsString('food cost de 50%', $m1[0]->title);
        config(['menu_engineering.family_targets' => ['Comidas' => 55]]);
        $this->assertSame([], iterator_to_array(app(FoodCostAcimaAlvoRule::class)->run($this->c, new AuditScope($this->c, 'restauracao', 'menu')), false));

        $m2 = iterator_to_array(app(FichaSemComprasRule::class)->run($this->c, $scope), false);
        $this->assertSame([['COCA', '100366', 117000]], array_map(fn ($f) => [$f->entityId, $f->evidenceKey, $f->impactCents], $m2));
        $this->assertStringContainsString('que não tem nenhuma compra registada', $m2[0]->seen);
        $this->assertNotSame('', $m2[0]->why);
        $this->assertNotSame('', $m2[0]->suggestion);
        $this->assertNotSame('', $m2[0]->stake);
    }

    // ── O ecrã ─────────────────────────────────────────────────────────────

    public function test_screen_reads_the_snapshot_sorts_on_the_server_exports_csv_and_respects_the_acl(): void
    {
        $this->catalog($this->c, 'CARNE', ['forpurchase' => true, 'forsale' => false]);
        $this->purchase($this->c, 'CARNE', '2026-10-01', 10, 10, 1000);
        foreach ([['A', 1, 100, 100000], ['B', 2, 60, 66000], ['C', 3, 40, 60000]] as [$pid, $q, $n, $net]) {
            $this->dish($pid, 'CARNE', $q);
            $this->sell($pid, $this->locBaixa, '2026-10-05', $n, $net);
        }
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $url = "/api/v1/companies/{$this->c}/restaurant/menu-engineering";
        Bus::fake();
        $this->actingAs($admin, 'sanctum')->getJson($url)->assertStatus(202)->assertJsonPath('data.snapshot', null);
        Bus::assertDispatched(ComputeMenuEngineeringJob::class);

        (new ComputeMenuEngineeringJob($this->c))->handle();
        $this->assertSame(6, MenuEngineeringSnapshot::count());   // empresa toda + 2 lojas, × 2 modos
        $r = $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk();
        $this->assertEquals(100, $r->json('data.snapshot.summary.coverage.confirmado_pct'));
        $this->assertCount(3, $r->json('data.items'));

        $items = $this->actingAs($admin, 'sanctum')->getJson("{$url}/items?sort=margin&dir=desc")->assertOk()->json('data.items');
        // Margens: C 15 − 3 = 12 €; A 10 − 1 = 9 €; B 11 − 2 = 9 € (empate: pelo id, no mesmo sentido).
        $this->assertSame(['C', 'B', 'A'], array_column($items, 'product_id'));
        $this->actingAs($admin, 'sanctum')->getJson("{$url}/items?sort=nada")->assertStatus(422);
        $csv = $this->actingAs($admin, 'sanctum')->get("{$url}/items?format=csv")->assertOk();
        $this->assertStringContainsString('Artigo;Família;Quantidade', $csv->streamedContent());
        $this->actingAs($admin, 'sanctum')->getJson("{$url}/items/A")->assertOk()->assertJsonPath('data.recipe.versions.0.lines.0.component_id', 'CARNE');

        // Quem só vê: não muda as exclusões nem os interruptores.
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'Ver', 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions(['restauracao.ver']);
        $viewer = User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
        $this->actingAs($viewer, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson("{$url}/exclusions", ['product_id' => 'A', 'reason' => 'Teste'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson("{$url}/settings", ['include_uber_eats' => true])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson("{$url}/exclusions", ['product_id' => 'A', 'reason' => 'Prato de staff'])->assertStatus(202);
        $this->assertSame('Prato de staff', MenuEngineeringExclusion::where('product_id', 'A')->value('reason'));
    }
}
