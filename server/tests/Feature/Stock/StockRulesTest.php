<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Stock\ArmazemSemEntradasRule;
use App\Audit\Rules\Restaurant\Stock\CoberturaImpossivelRule;
use App\Audit\Rules\Restaurant\Stock\CompradoNuncaConsumidoRule;
use App\Audit\Rules\Restaurant\Stock\StockNegativoRule;
use App\Models\PingwinStockSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7a — as regras do stock, cada uma com um caso positivo e um negativo. O positivo da Coca Cola
 * Zero: a ficha da Coca Cola 0 usa o 100366 COCA-COLA ZERO GARRAFA (fica negativo) e as compras entram
 * no 100415 COCA COLA ZERO 0.35 (acumula: 5 112 + 1 896 + 3 432 = 10 440 unidades).
 */
class StockRulesTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
    }

    /** @return list<AuditFindingData> */
    private function findings(string $ruleClass): array
    {
        return iterator_to_array(app($ruleClass)->run($this->c, new AuditScope($this->c, 'restauracao', 'stock')), false);
    }

    private function cocaZeroCase(string $recipeComponent = '100366'): void
    {
        $this->catalog($this->c, 'COCA0', ['code' => '23', 'description' => 'Coca Cola 0']);
        $this->catalog($this->c, '100366', ['code' => '100366', 'description' => 'COCA-COLA ZERO GARRAFA', 'forsale' => false, 'forpurchase' => true]);
        $this->catalog($this->c, '100415', ['code' => '100415', 'description' => 'COCA COLA ZERO 0.35', 'forsale' => false, 'forpurchase' => true]);
        $this->persistBom($this->c, $this->bom('COCA0', [['00000000', 'V1', '2026-05-06', ['S1' => true, 'S2' => true], [$this->bomLine($recipeComponent, 1)]]]));
        $this->soldDaily('COCA0', $this->locBaixa, '2026-09-11', 28, 17, 2400);
        $this->soldDaily('COCA0', $this->locCosta, '2026-09-11', 28, 43, 6000);
        foreach (['2026-08-20', '2026-09-20', '2026-10-10'] as $d) {
            $this->boughtIn('100415', 'WBO', $d, 1672, 89000);
        }
        $this->stock('100415', 'WBO', 5112, 0.534564);
        $this->stock('100415', 'WB', 1896, 0.527748);
        $this->stock('100415', 'WC', 3432, 0.527746);
        $this->stock('100366', 'WBO', 0, 0.5275);
        $this->stock('100366', 'WB', -2637, 0.5275);
        $this->stock('100366', 'WC', -5309, 12.66);
    }

    // ── Stock negativo ───────────────────────────────────────────────────────

    public function test_negative_stock_points_to_the_purchased_twin_and_the_dish(): void
    {
        $this->cocaZeroCase();
        $f = $this->findings(StockNegativoRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('100366', $f[0]->entityId);
        $this->assertSame('COCA-COLA ZERO GARRAFA: stock negativo no PingWin', $f[0]->title);
        $this->assertStringStartsWith('No PingWin, o stock está negativo: -5 309 unidades no Armazém Yuko e -2 637 unidades no Armazém Yuko Baixa.', $f[0]->seen);
        $this->assertStringContainsString('as vendas consumiram, em teoria, 60 unidades por dia (sobretudo Coca Cola 0)', $f[0]->seen);
        $this->assertStringContainsString('O custo médio varia muito entre armazéns: de 0,53 € a 12,66 €.', $f[0]->seen);
        $this->assertStringContainsString('As compras parecem entrar noutro artigo: 100415 COCA COLA ZERO 0.35, que tem 10 440 unidades em stock e nenhum consumo.', $f[0]->seen);
        $this->assertStringStartsWith('Abra a ficha de Coca Cola 0 e troque o ingrediente pelo artigo que compra (100415 COCA COLA ZERO 0.35).', $f[0]->suggestion);
        $this->assertSame(['/restauracao/fichas-tecnicas/COCA0?editar=1', 'Abrir a ficha'], [$f[0]->fixUrl, $f[0]->fixLabel]);
        $this->assertNull($f[0]->impactCents);
        $this->assertSame((int) round((2637 * 0.5275 + 5309 * 12.66) * 100), $f[0]->weightCents);
        $this->assertSame('alta', app(StockNegativoRule::class)->severity());
    }

    public function test_no_negative_stock_no_finding(): void
    {
        $this->catalog($this->c, '100416', ['description' => 'COCA COLA 0.35']);
        $this->stock('100416', 'WBO', 2376);
        $this->stock('100416', 'WB', 0);
        $this->assertSame([], $this->findings(StockNegativoRule::class));
    }

    // ── Comprado mas nunca consumido ─────────────────────────────────────────

    public function test_coca_cola_zero_bought_and_never_consumed(): void
    {
        $this->cocaZeroCase();
        $f = $this->findings(CompradoNuncaConsumidoRule::class);
        $this->assertSame(['100415'], array_map(fn ($x) => $x->entityId, $f));
        $this->assertSame('COCA COLA ZERO 0.35: comprado, mas nada o consome', $f[0]->title);
        $this->assertSame('Nos últimos 90 dias comprou 5 016 unidades de COCA COLA ZERO 0.35. Tem 10 440 unidades em stock (5 112 unidades no Armazém Yuko BO, 3 432 unidades no Armazém Yuko e 1 896 unidades no Armazém Yuko Baixa). Nenhuma ficha em vigor usa este artigo e ele não se vende diretamente. As fichas usam um artigo parecido: 100366 COCA-COLA ZERO GARRAFA (em Coca Cola 0).', $f[0]->seen);
        $this->assertSame(['/restauracao/fichas-tecnicas/COCA0?editar=1', 'Abrir a ficha'], [$f[0]->fixUrl, $f[0]->fixLabel]);
        $value = 5112 * 0.534564 + 1896 * 0.527748 + 3432 * 0.527746;
        $this->assertSame((int) round($value * 100), $f[0]->impactCents);
        $this->assertStringStartsWith('5 544,53 €: o valor, ao custo médio, das 10 440 unidades em stock que nada desconta.', $f[0]->stake);
        $this->assertContains(['artigo', '100366'], $f[0]->refs);
        $this->assertSame('alta', app(CompradoNuncaConsumidoRule::class)->severity());
    }

    public function test_used_by_a_recipe_or_stock_falling_is_not_flagged(): void
    {
        // A ficha usa o próprio 100415: há consumo, nada a assinalar.
        $this->cocaZeroCase('100415');
        $this->assertSame([], $this->findings(CompradoNuncaConsumidoRule::class));
    }

    public function test_stock_going_down_since_the_older_snapshot_is_not_flagged(): void
    {
        $this->cocaZeroCase();
        PingwinStockSnapshot::create(['company_id' => $this->c, 'snapshot_date' => '2026-10-01', 'product_pingwin_id' => '100415', 'warehouse_pingwin_id' => 'WBO', 'qnt' => 12000]);
        $this->assertSame([], $this->findings(CompradoNuncaConsumidoRule::class));
    }

    // ── Cobertura impossível ─────────────────────────────────────────────────

    public function test_stock_for_more_than_90_days_of_consumption(): void
    {
        $this->catalog($this->c, 'FRAN', ['description' => 'Francesinha Tradicional']);
        $this->catalog($this->c, 'AVINHEZ', ['description' => 'AVINHEZ BRANCO', 'forsale' => false, 'forpurchase' => true]);
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BF', '2026-05-26', ['S1' => true, 'S2' => true], [$this->bomLine('AVINHEZ', 0.095)]]]));
        $this->soldDaily('FRAN', $this->locCosta, '2026-09-11', 28, 30);
        $this->stock('AVINHEZ', 'WB', 308.333, 0.744603, ['unit_name' => 'Litro']);
        $this->stock('AVINHEZ', 'WC', 339.038, 1.202714, ['unit_name' => 'Litro']);
        $this->stock('AVINHEZ', 'WBO', 55, 14.89, ['unit_name' => 'Litro']);

        $f = $this->findings(CoberturaImpossivelRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('AVINHEZ BRANCO: stock para 246 dias', $f[0]->title);
        $this->assertSame('Há 702 litros em stock, em todos os armazéns. De 11/09/2026 a 08/10/2026, as fichas consumiram, em teoria, 2,85 litros por dia. A este ritmo, o stock dava para 246 dias.', $f[0]->seen);
        $this->assertStringContainsString('(Francesinha Tradicional)', $f[0]->suggestion);
        $this->assertSame('media', app(CoberturaImpossivelRule::class)->severity());
        $this->assertGreaterThan(0, $f[0]->weightCents);
        $this->assertNull($f[0]->impactCents);
    }

    public function test_coverage_under_90_days_or_without_consumption_is_not_flagged(): void
    {
        $this->catalog($this->c, 'FRAN', ['description' => 'Francesinha Tradicional']);
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BF', '2026-05-26', ['S1' => true, 'S2' => true], [$this->bomLine('AVINHEZ', 0.095)]]]));
        $this->soldDaily('FRAN', $this->locCosta, '2026-09-11', 28, 30);
        $this->stock('AVINHEZ', 'WC', 50, 1.2, ['unit_name' => 'Litro']);      // ~18 dias
        $this->stock('SEMCONSUMO', 'WC', 500, 1.0);                            // sem consumo: é da outra regra
        $this->assertSame([], $this->findings(CoberturaImpossivelRule::class));
    }

    // ── Armazém de loja que deixou de receber ────────────────────────────────

    private function purchasesByMonth(bool $centralised): void
    {
        foreach (range(1, 15) as $i) {
            $pid = "A{$i}";
            $this->catalog($this->c, $pid, ['description' => "ARTIGO {$i}", 'forsale' => false, 'forpurchase' => true]);
            foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $d) {
                $this->boughtIn($pid, 'WB', $d, 10, 10000);
                $this->boughtIn($pid, 'WC', $d, 10, 10000);
            }
            foreach (['2026-08-10', '2026-09-10', '2026-10-05'] as $d) {
                $this->boughtIn($pid, $centralised ? 'WBO' : 'WB', $d, 20, 20000);
                $this->boughtIn($pid, $centralised ? 'WBO' : 'WC', $d, 20, 20000);
            }
        }
    }

    public function test_shop_warehouses_that_stopped_receiving_since_the_economato_took_over(): void
    {
        $this->purchasesByMonth(true);
        $f = collect($this->findings(ArmazemSemEntradasRule::class))->keyBy('entityId');
        $this->assertEqualsCanonicalizing(['WB', 'WC'], $f->keys()->all());
        $wb = $f['WB'];
        $this->assertSame('armazem', $wb->entityType);
        $this->assertSame('Armazém Yuko Baixa: deixou de receber compras', $wb->title);
        $this->assertSame('Desde 01/08/2026, o Armazém Yuko BO recebe 100% do valor das compras. Dos 15 artigos que o Armazém Yuko Baixa recebia nos 3 meses antes, só 0 lá entraram desde então; 15 passaram a entrar só no Armazém Yuko BO (por exemplo, ARTIGO 1, ARTIGO 2 e ARTIGO 3).', $wb->seen);
        $this->assertStringStartsWith('As vendas de Tabern Yuko Baixa descontam deste armazém.', $wb->why);
        $this->assertSame(['/restauracao/armazens', 'Abrir os armazéns'], [$wb->fixUrl, $wb->fixLabel]);
        $this->assertSame('info', app(ArmazemSemEntradasRule::class)->severity());
    }

    public function test_shop_warehouses_still_receiving_are_not_flagged(): void
    {
        $this->purchasesByMonth(false);
        $this->assertSame([], $this->findings(ArmazemSemEntradasRule::class));
    }
}
