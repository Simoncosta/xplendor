<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Units\BaseGrandeRule;
use App\Audit\Rules\Restaurant\Units\BebidaFichaUnidadeRule;
use App\Audit\Rules\Restaurant\Units\CombinacaoNovaRule;
use App\Audit\Rules\Restaurant\Units\EmbalagemFatorUmRule;
use App\Audit\Rules\Restaurant\Units\FornecedoresUnidadesRule;
use App\Audit\Rules\Restaurant\Units\PesoVariavelRule;
use App\Audit\Rules\Restaurant\Units\PorIdentificarRule;
use App\Audit\Rules\Restaurant\Units\PrecoFamiliaRule;
use App\Audit\Rules\Restaurant\Units\UnidadeGlobalIncoerenteRule;
use App\Audit\Rules\Restaurant\Units\UnidadesSemUsoRule;
use App\Models\ProductUnitConversion;
use App\Models\ProductUnitProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsUnits;
use Tests\TestCase;

/**
 * Auditoria v2 · as regras da família "Unidades" (U0 a U8), cada uma com um caso que dá achado e um
 * que não dá. Os nomes dos artigos são de teste; nada de clientes.
 */
class UnitRulesTest extends TestCase
{
    use BuildsUnits;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUnits();
    }

    /** @return array<string, AuditFindingData> por artigo (e chave) */
    private function found(string $rule): array
    {
        $out = [];
        foreach (app($rule)->run($this->c, new AuditScope($this->c, 'restauracao', 'unidades')) as $f) {
            $out[$f->entityId . ($f->evidenceKey !== '' ? '#' . $f->evidenceKey : '')] = $f;
        }

        return $out;
    }

    public function test_u0_a_new_unit_from_a_supplier_asks_at_once(): void
    {
        $this->art('A', 'Artigo A');
        $this->buy('A', '2026-05-10', 10, 1000, 'ARTIGO A');
        $this->buy('A', '2026-10-10', 2, 2400, 'ARTIGO A', 'CX6', 'Caixa 6 Unidades');
        $this->art('B', 'Artigo B');
        $this->buy('B', '2026-10-10', 2, 2400, 'ARTIGO B', 'CX6', 'Caixa 6 Unidades'); // artigo novo de todo: não é combinação nova

        $f = $this->found(CombinacaoNovaRule::class);
        $this->assertArrayHasKey('A#500000001|CX6', $f);
        $this->assertSame(2400, $f['A#500000001|CX6']->impactPendingCents);
        $this->assertSame(['type' => 'guiao', 'product_id' => 'A'], $f['A#500000001|CX6']->fixAction);
        $this->assertCount(1, $f);
    }

    public function test_u1_packaging_in_the_description_with_factor_one(): void
    {
        $this->peers(self::DRINKS, '11001', 'Unidade', 0.6);
        $this->art('LATA', 'Refrigerante lata', self::DRINKS);
        $this->buy('LATA', '2026-09-20', 8, 14400, 'REFRIGERANTE LATA 28X33CL'); // 18 € por «Unidade» = 28 latas
        $this->peers(self::FOOD, '11002', 'Quilograma', 8.0, 3, '11002');
        $this->art('QUEIJO', 'Queijo barra', self::FOOD, '11002');
        $this->buy('QUEIJO', '2026-09-20', 30.5, 24000, 'QUEIJO BARRA 3KG', '11002', 'Quilograma'); // já ao kg: não é embalagem

        $f = $this->found(EmbalagemFatorUmRule::class);
        $lata = $f['LATA#500000001|11001'] ?? null;
        $this->assertNotNull($lata);
        $this->assertSame((int) round(144 * 27 / 28 * 100), $lata->impactCents); // 144 € × (28 − 1) ÷ 28
        $this->assertArrayNotHasKey('QUEIJO#500000001|11002', $f);
    }

    public function test_u2_base_too_big_for_the_recipes(): void
    {
        $this->art('CAFE', 'Lote café');
        $this->buy('CAFE', '2026-09-20', 10, 15000, 'CAFE GRAO 1KG');
        $this->dish('D1', [['CAFE', 0.007, '11001', 'Unidade']]);
        $this->art('QJ', 'Queijo', self::FOOD, '11002');
        $this->buy('QJ', '2026-09-20', 10, 8000, 'QUEIJO', '11002', 'Quilograma');
        $this->dish('D2', [['QJ', 0.03, '11002', 'Quilograma']]);

        $f = $this->found(BaseGrandeRule::class);
        $this->assertArrayHasKey('CAFE', $f);
        $this->assertStringContainsString('0,007', $f['CAFE']->title);
        $this->assertArrayNotHasKey('QJ', $f);
    }

    public function test_u3_bought_by_kg_used_in_thirds_of_a_unit(): void
    {
        $this->art('FRANGO', 'Frango', self::FOOD, '11002');
        $this->buy('FRANGO', '2026-09-20', 72.1, 20837, 'FRANGO INTEIRO', '11002', 'Quilograma');
        $this->dish('D3', [['FRANGO', 0.333, '11001', 'Unidade']]);
        $this->art('PERNA', 'Perna', self::FOOD, '11002');
        $this->buy('PERNA', '2026-09-20', 20, 9000, 'PERNA', '11002', 'Quilograma');
        $this->dish('D4', [['PERNA', 0.25, '11002', 'Quilograma']]);

        $f = $this->found(PesoVariavelRule::class);
        $this->assertArrayHasKey('FRANGO', $f);
        $this->assertArrayNotHasKey('PERNA', $f);
    }

    public function test_u4_two_suppliers_in_different_units_without_conversion(): void
    {
        foreach (['X', 'Y'] as $pid) {
            $this->art($pid, "Artigo {$pid}");
            $this->buy($pid, '2026-09-01', 10, 1000, "ARTIGO {$pid}");
            $this->buy($pid, '2026-09-02', 2, 1200, "ARTIGO {$pid}", 'CX6', 'Caixa 6 Unidades', 1.0, '500000002', 'Fornecedor B');
        }
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'Y', 'unit_key' => 'CX6', 'unit_label' => 'Caixa 6 Unidades',
            'supplier_nif' => '500000002', 'factor_to_base' => 6, 'source' => 'confirmado']);

        $f = $this->found(FornecedoresUnidadesRule::class);
        $this->assertArrayHasKey('X', $f);
        $this->assertArrayNotHasKey('Y', $f);
    }

    public function test_u5_price_per_base_out_of_the_family_range(): void
    {
        $this->peers(self::FOOD, '11001', 'Unidade', 1.0, 5);
        $this->art('CARO', 'Artigo caro');
        $this->buy('CARO', '2026-09-20', 10, 12000, 'ARTIGO CARO'); // 12 € por unidade, 12 vezes a família
        $this->art('NORMAL', 'Artigo normal');
        $this->buy('NORMAL', '2026-09-20', 10, 1200, 'ARTIGO NORMAL');

        $f = $this->found(PrecoFamiliaRule::class);
        $this->assertArrayHasKey('CARO', $f);
        $this->assertLessThanOrEqual(12000, $f['CARO']->impactPendingCents); // nunca mais do que se pagou
        $this->assertArrayNotHasKey('NORMAL', $f);
    }

    public function test_u6_units_without_use_for_12_months(): void
    {
        $this->art('A', 'Artigo A');
        $this->buy('A', '2026-09-20', 2, 2400, 'ARTIGO A', 'GRD24', 'Grade 24', 24.0);

        $f = $this->found(UnidadesSemUsoRule::class);
        $this->assertArrayHasKey('CX6', $f);
        $this->assertSame('info', $f['CX6']->severity);
        $this->assertArrayNotHasKey('GRD24', $f);
    }

    public function test_u7_drink_sold_one_by_one_with_the_recipe_in_another_unit(): void
    {
        $this->peers(self::DRINKS, '11003', 'Litro', 2.9, 3, '11003');
        $this->art('STOUT', 'Cerveja preta', self::DRINKS, '11003');
        $this->buy('STOUT', '2026-09-20', 50, 68959, 'CERVEJA PRETA TR 0.33X24 GR', '11003', 'Litro');
        $this->dish('S1', [['STOUT', 0.042, '11003', 'Litro']]);
        $this->art('AGUA', 'Água', self::DRINKS, '11001');
        $this->buy('AGUA', '2026-09-20', 48, 2400, 'AGUA 0,5L');
        $this->dish('S2', [['AGUA', 1, '11001', 'Unidade']]);

        $f = $this->found(BebidaFichaUnidadeRule::class);
        $this->assertArrayHasKey('STOUT#S1', $f);
        $this->assertArrayNotHasKey('AGUA#S2', $f);
    }

    public function test_u8_to_identify_when_the_evidence_contradicts(): void
    {
        $this->art('ACUCAR', 'Açúcar');
        $this->buy('ACUCAR', '2026-09-20', 3, 3000, 'ACUCAR SAQUETAS', '11001', 'Unidade');
        $this->buy('ACUCAR', '2026-09-21', 10, 900, 'ACUCAR 1KG', '11001', 'Unidade', 1.0, '500000002', 'Fornecedor B');
        $this->art('FARINHA', 'Farinha', self::FOOD, '11002');
        $this->buy('FARINHA', '2026-09-20', 25, 2000, 'FARINHA TIPO 65', '11002', 'Quilograma');
        $this->dish('D9', [['FARINHA', 0.2, '11002', 'Quilograma']]);

        $f = $this->found(PorIdentificarRule::class);
        $this->assertArrayHasKey('ACUCAR', $f);
        $this->assertStringContainsString('saquetas', $f['ACUCAR']->seen);
        $this->assertArrayNotHasKey('FARINHA', $f);
    }

    public function test_u9_global_unit_incoherent_with_its_name_lists_every_article_and_the_total(): void
    {
        foreach (['A', 'B'] as $pid) {
            $this->art($pid, "Artigo {$pid}");
            $this->buy($pid, '2026-09-20', 2, 1200, "ARTIGO {$pid}", 'CX6', 'Caixa 6 Unidades');
        }
        $this->art('C', 'Artigo C');
        \App\Models\PingwinCatalogItem::where('pingwin_id', 'C')->update(['default_purchase_unit_id' => 'CX6']);
        $this->art('G', 'Artigo G');
        $this->buy('G', '2026-09-20', 2, 4800, 'ARTIGO G', 'GRD24', 'Grade 24', 24);

        $f = $this->found(UnidadeGlobalIncoerenteRule::class);
        $this->assertArrayHasKey('CX6', $f);
        $this->assertArrayNotHasKey('GRD24', $f); // a Grade 24 conta 24: coerente
        $this->assertSame(2000, $f['CX6']->impactCents); // (12 € + 12 €) × (1 − 1/6)
        $this->assertEqualsCanonicalizing([['artigo', 'A'], ['artigo', 'B'], ['artigo', 'C']], $f['CX6']->refs);
        $this->assertSame(['type' => 'unidade_global', 'unit_id' => 'CX6'], $f['CX6']->fixAction);
        $this->assertStringContainsString('crie uma vez a unidade certa, «Caixa 6 un»', $f['CX6']->suggestion);
        $this->assertStringContainsString('não se anula nem se altera', $f['CX6']->suggestion);
    }

    public function test_u1_does_not_repeat_what_u9_already_says(): void
    {
        $this->peers(self::DRINKS, '11001', 'Unidade', 0.6);
        $this->art('SUMO', 'Sumo', self::DRINKS);
        $this->buy('SUMO', '2026-09-20', 4, 1440, 'SUMO 6X0,33', 'CX6', 'Caixa 6 Unidades');

        $this->assertArrayHasKey('CX6', $this->found(UnidadeGlobalIncoerenteRule::class));
        $this->assertSame([], array_filter(array_keys($this->found(EmbalagemFatorUmRule::class)), fn ($k) => str_starts_with($k, 'SUMO')));
    }

    public function test_not_applicable_and_decided_articles_leave_the_rules(): void
    {
        $this->art('CAFE', 'Lote café');
        $this->buy('CAFE', '2026-09-20', 10, 15000, 'CAFE GRAO 1KG');
        $this->dish('D1', [['CAFE', 0.007, '11001', 'Unidade']]);
        ProductUnitProfile::create(['company_id' => $this->c, 'product_pingwin_id' => 'CAFE', 'state' => 'nao_se_aplica', 'reason' => 'Teste']);

        $this->assertArrayNotHasKey('CAFE', $this->found(BaseGrandeRule::class));
    }
}
