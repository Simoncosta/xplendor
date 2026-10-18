<?php

declare(strict_types=1);

namespace Tests\Unit\Units;

use App\Services\Restaurant\Units\PackagingParser;
use App\Services\Restaurant\Units\UnitImpact;
use App\Services\Restaurant\Units\UnitProposal;
use PHPUnit\Framework\TestCase;

/**
 * O Guião de unidades: o tipo e a base pela ordem das evidências (uso, descrição do fornecedor,
 * unidade faturada; nunca o nome do artigo), as doses individuais, a "unidade antiga = embalagem
 * inteira", o saco sem quantidade, o "O que muda" (fichas e stock) e o aviso de mudança de base.
 */
class UnitProposalTest extends TestCase
{
    private function e(array $o = []): array
    {
        return array_merge([
            'product_id' => 'P1', 'code' => '1', 'description' => 'Artigo', 'label' => '1 · Artigo', 'family' => 'COMPRAS › COMIDAS', 'family_leaf' => 'COMIDAS',
            'base_unit_id' => '11001', 'base_unit' => 'Unidade', 'base_family' => 'un', 'purchase_unit_id' => null, 'purchases' => [],
            'spend_12m_eur' => 0.0, 'spend_90d_eur' => 0.0, 'packaging' => null, 'recipes' => [], 'counts' => [], 'transfers' => [],
            'sold_qty_90d' => 0.0, 'sold_net_90d_eur' => 0.0, 'sold_one_to_one' => false, 'has_recipe' => false, 'units' => [], 'stock' => [],
            'profile' => null, 'extra_descriptions' => [], 'today' => '2026-10-15', 'price_per_base' => null, 'family_median' => null,
            'family_peers' => 0, 'price_ratio' => null, 'confidence' => ['score' => 0.5, 'why' => [], 'contradictions' => []], 'stock_relevant' => true,
        ], $o);
    }

    /** Uma linha de compra agrupada (fornecedor × unidade faturada), como a UnitEvidence a dá. */
    private function buy(string $desc, string $unit, ?string $family, float $qnt, float $total, array $o = []): array
    {
        $pack = PackagingParser::parse($desc);
        if (! $pack || $pack['quantity'] <= 0) {
            $byUnit = PackagingParser::parse($unit);
            $pack = $byUnit && $byUnit['quantity'] > 0 ? $byUnit : $pack;
        }
        $source = $o['base_source'] ?? (in_array($unit, ['Unidade', 'Quilograma', 'Litro'], true) ? 'base' : null);

        return array_merge([
            'supplier' => 'Fornecedor A', 'nif' => '500000001', 'unit_key' => $unit, 'unit_id' => $unit, 'unit_label' => $unit, 'unit_family' => $family,
            'pingwin_factor' => 1.0, 'qnt' => $qnt, 'total_eur' => $total, 'recent_qnt' => $qnt, 'recent_eur' => $total, 'lines' => 1,
            'first_date' => '2026-08-01', 'last_date' => '2026-10-01', 'last_doc' => 'D1', 'last_document' => 'VFT 1', 'descriptions' => [$desc],
            'confirmed_factor' => null, 'base_qnt' => 0.0, 'base_source' => $source, 'packaging' => $pack,
            'base_factor' => $source === 'base' ? 1.0 : null, 'price_per_unit' => $qnt > 0 ? round($total / $qnt, 4) : null, 'price_per_base' => null,
        ], $o);
    }

    private function recipe(float $qnt, string $family = 'un', string $unit = 'Unidade', float $sold = 30): array
    {
        return ['product_id' => 'D' . (int) ($qnt * 10000), 'label' => 'Prato', 'bom_id' => 'B1', 'qnt' => $qnt, 'unit_id' => $unit, 'unit' => $unit,
            'unit_family' => $family, 'qnt_base' => $qnt, 'sold_qty_90d' => $sold, 'is_bom' => false];
    }

    private function p(array $e, array $answers = []): array
    {
        return UnitProposal::build($e, $answers);
    }

    // ── Café: em grão (1 kg), em cápsulas (caixa de 100), em pastilhas ─────────────────────────

    public function test_coffee_beans_1kg_used_in_grams_is_by_weight_with_base_kg(): void
    {
        $e = $this->e(['purchases' => [$this->buy('CAFE GRAO DELTA 1KG', 'Unidade', 'un', 20, 300)], 'recipes' => [$this->recipe(0.007), $this->recipe(0.014)],
            'stock' => [['warehouse' => 'W1', 'qnt' => 12, 'unit' => 'Unidade', 'pcm' => 15.0]]]);
        $p = $this->p($e);
        $this->assertSame('embalado_peso', $p['type']);
        $this->assertSame('kg', $p['base']);
        $this->assertSame('1 Embalagem 1 kg = 1 kg', $p['purchase_units'][0]['label']);
        $this->assertTrue($p['base_change']);
        $this->assertSame('Mudar a unidade base de unidade para quilograma', $p['base_change_warning']['title']);
        $this->assertCount(3, $p['base_change_warning']['paths']);

        // "O que muda": 0,007 Unidade → 7 g; o stock e o PCM convertidos; o custo por porção.
        $i = UnitImpact::build($e, $p, 15.0, 3000);
        $this->assertSame('0,007 Unidade', $i['recipes'][0]['before']);
        $this->assertSame('7 g', $i['recipes'][0]['after']);
        $this->assertEqualsWithDelta(0.105, $i['recipes'][0]['cost_before_eur'], 1e-6);
        $this->assertEqualsWithDelta(0.105, $i['recipes'][0]['cost_after_eur'], 1e-6);
        $this->assertSame('12 kg', $i['stock'][0]['after']);
        $this->assertEqualsWithDelta(15.0, $i['stock'][0]['pcm_after_eur'], 1e-6);
        $this->assertSame(10.0, $i['monthly_eur']); // 30 € em jogo a 90 dias ÷ 3
    }

    public function test_coffee_capsules_box_of_100_base_is_the_capsule(): void
    {
        $e = $this->e(['purchases' => [$this->buy('CAFE DELTA Q 100 CAPS', 'Unidade', 'un', 10, 250)], 'recipes' => [$this->recipe(0.01), $this->recipe(0.02)],
            'stock' => [['warehouse' => 'W1', 'qnt' => 5, 'unit' => 'Unidade', 'pcm' => 25.0]]]);
        $p = $this->p($e);
        $this->assertSame('contavel', $p['type']);
        $this->assertSame('un', $p['base']);
        $this->assertSame('Cápsula', $p['base_name']);
        $this->assertSame('1 Caixa 100 = 100 cápsulas', $p['purchase_units'][0]['label']);
        $this->assertTrue($p['old_unit_is_pack']); // a «Unidade» de hoje é a caixa
        $this->assertTrue($p['base_change']);
        $i = UnitImpact::build($e, $p, 25.0);
        $this->assertSame('1 cápsula', $i['recipes'][0]['after']);
        $this->assertSame('500 cápsulas', $i['stock'][0]['after']);
        $this->assertEqualsWithDelta(0.25, $i['stock'][0]['pcm_after_eur'], 1e-6);
    }

    public function test_coffee_pods_without_recipes_use_the_box_n_from_the_description(): void
    {
        $p = $this->p($this->e(['purchases' => [$this->buy('CAFE PASTILHAS 150', 'Unidade', 'un', 4, 90)]]));
        $this->assertSame('contavel', $p['type']);
        $this->assertSame('Pastilha', $p['base_name']);
        $this->assertSame(150.0, $p['purchase_units'][0]['factor']);
        $this->assertSame('Caixa 150', $p['purchase_units'][0]['proposed_unit']);
    }

    public function test_coffee_used_in_fractions_but_described_as_capsules_is_to_identify(): void
    {
        $p = $this->p($this->e(['description' => 'CAFE', 'purchases' => [$this->buy('CAFE DELTA CAPSULAS', 'Unidade', 'un', 10, 250)],
            'recipes' => [$this->recipe(0.007)]]));
        $this->assertFalse($p['identified']);
        $this->assertNull($p['type']);
        $this->assertSame('forma', $p['questions'][0]['id']);
        $this->assertSame('Cafe: é em grão, moído ou ao kg, ou em cápsulas?', $p['questions'][0]['text']);
        // A resposta de uma pessoa resolve.
        $this->assertSame('peso', $this->p($this->e(['purchases' => [$this->buy('CAFE DELTA CAPSULAS', 'Unidade', 'un', 10, 250)],
            'recipes' => [$this->recipe(0.007)]]), ['forma' => 'granel'])['type']);
    }

    public function test_doses_without_n_ask_how_many_and_never_assume(): void
    {
        $p = $this->p($this->e(['purchases' => [$this->buy('ACUCAR SAQUETAS', 'Caixa', 'un', 3, 30)]]));
        $this->assertSame('Saqueta', $p['base_name']);
        $this->assertNull($p['purchase_units'][0]['factor']);
        $this->assertSame('doses_por_caixa', $p['questions'][0]['id']);
        $this->assertSame(1000.0, $this->p($this->e(['purchases' => [$this->buy('ACUCAR SAQUETAS', 'Caixa', 'un', 3, 30)]]), ['doses_por_caixa' => '1000'])['purchase_units'][0]['factor']);
    }

    // ── Açúcar, manteiga, chá, ketchup ─────────────────────────────────────────────────────────

    public function test_sugar_in_sachets_and_sugar_by_the_kg(): void
    {
        $sachets = $this->p($this->e(['purchases' => [$this->buy('ACUCAR SAQ. 1000', 'Caixa', 'un', 3, 30)]]));
        $this->assertSame(['contavel', 'Saqueta', 1000.0], [$sachets['type'], $sachets['base_name'], $sachets['purchase_units'][0]['factor']]);

        $kg = $this->p($this->e(['base_family' => 'kg', 'base_unit' => 'Quilograma', 'purchases' => [$this->buy('ACUCAR BRANCO GRANULADO', 'Quilograma', 'kg', 50, 45)],
            'recipes' => [$this->recipe(0.015, 'kg', 'Quilograma')]]));
        $this->assertSame(['peso', 'kg', 1.0], [$kg['type'], $kg['base'], $kg['purchase_units'][0]['factor']]);

        // As duas descrições no mesmo artigo contradizem-se: por identificar.
        $mixed = $this->p($this->e(['purchases' => [$this->buy('ACUCAR SAQUETAS', 'Caixa', 'un', 3, 30), $this->buy('ACUCAR 1KG', 'Unidade', 'un', 10, 9)]]));
        $this->assertFalse($mixed['identified']);
        $this->assertSame('forma', $mixed['questions'][0]['id']);
    }

    public function test_butter_in_portions_and_by_the_kg(): void
    {
        $doses = $this->p($this->e(['purchases' => [$this->buy('MANTEIGA DOSE 10G C/100', 'Caixa', 'un', 5, 60)]]));
        $this->assertSame(['contavel', 'Dose', 100.0], [$doses['type'], $doses['base_name'], $doses['purchase_units'][0]['factor']]);

        $e = $this->e(['purchases' => [$this->buy('MANTEIGA C/SAL 1KG - PACOTE', 'Unidade', 'un', 20, 160)], 'recipes' => [$this->recipe(0.04)]]);
        $kg = $this->p($e);
        $this->assertSame(['embalado_peso', 'kg', 1.0], [$kg['type'], $kg['base'], $kg['purchase_units'][0]['factor']]);
        $this->assertSame('40 g', UnitImpact::build($e, $kg, 8.0)['recipes'][0]['after']);
    }

    public function test_tea_in_sachets(): void
    {
        $e = $this->e(['purchases' => [$this->buy('CHA LIPTON 25 SAQUETAS', 'Unidade', 'un', 10, 30)], 'recipes' => [$this->recipe(0.04)]]);
        $p = $this->p($e);
        $this->assertSame(['contavel', 'Saqueta', 25.0], [$p['type'], $p['base_name'], $p['purchase_units'][0]['factor']]);
        $this->assertSame('1 saqueta', UnitImpact::build($e, $p, 3.0)['recipes'][0]['after']);
    }

    public function test_ketchup_in_single_portions_and_in_a_bottle(): void
    {
        $mono = $this->p($this->e(['purchases' => [$this->buy('KETCHUP MONODOSE 200X10G', 'Caixa', 'un', 2, 40)]]));
        $this->assertSame(['contavel', 'Dose', 200.0, 'Caixa 200'], [$mono['type'], $mono['base_name'], $mono['purchase_units'][0]['factor'], $mono['purchase_units'][0]['proposed_unit']]);

        $e = $this->e(['purchases' => [$this->buy('KETCHUP HEINZ 1KG', 'Unidade', 'un', 12, 60)], 'recipes' => [$this->recipe(0.02)]]);
        $bottle = $this->p($e);
        $this->assertSame(['embalado_peso', 'kg'], [$bottle['type'], $bottle['base']]);
        $this->assertSame('20 g', UnitImpact::build($e, $bottle, 5.0)['recipes'][0]['after']);
    }

    // ── Ovos, queijo, pão, azeite ──────────────────────────────────────────────────────────────

    public function test_eggs_box_of_180_and_a_dozen(): void
    {
        // Caixa de 180: as fichas contam a caixa (0,0055 = 1 ovo), mas o preço por «Unidade» é o de um ovo.
        $e = $this->e(['family_median' => 0.2, 'purchases' => [
            $this->buy('MC OVOS M IND 15DUZIAS', 'Caixa 180 ovos', 'un', 10, 340, ['base_source' => null, 'pingwin_factor' => 180.0]),
            $this->buy('MC OVOS M IND 15DUZIAS', 'Unidade', 'un', 2716, 1088),
        ], 'recipes' => [$this->recipe(0.0055), $this->recipe(0.022)]]);
        $p = $this->p($e);
        $this->assertSame(['contavel', 'un'], [$p['type'], $p['base']]);
        $this->assertSame([180.0, 1.0], [$p['purchase_units'][0]['factor'], $p['purchase_units'][1]['factor']]);
        $this->assertTrue($p['recipes_use_pack']);
        $this->assertFalse($p['base_change']);
        $i = UnitImpact::build($e, $p, 0.4);
        $this->assertSame('0,99 unidades', $i['recipes'][0]['after']);
        $this->assertStringContainsString('são as fichas que estão erradas', $i['recipes_note']);

        // Dúzia: a «Unidade» faturada custa 12 ovos (o preço diz que é a caixa): fator 12.
        $dozen = $this->p($this->e(['family_median' => 0.2, 'purchases' => [$this->buy('OVOS CLASSE M DUZIA', 'Unidade', 'un', 30, 72)]]));
        $this->assertSame([12.0, 'Caixa 12'], [$dozen['purchase_units'][0]['factor'], $dozen['purchase_units'][0]['proposed_unit']]);
    }

    public function test_sliced_cheese_slices_vs_kg(): void
    {
        $slices = $this->p($this->e(['purchases' => [$this->buy('QUEIJO FLAMENGO 40 FATIAS', 'Unidade', 'un', 20, 80)], 'recipes' => [$this->recipe(0.05)]]));
        $this->assertSame(['contavel', 'Fatia', 40.0], [$slices['type'], $slices['base_name'], $slices['purchase_units'][0]['factor']]);

        $kg = $this->p($this->e(['base_family' => 'kg', 'base_unit' => 'Quilograma', 'purchases' => [$this->buy('QUEIJO FLAMENGO BARRA 3KG', 'Quilograma', 'kg', 30.5, 240)],
            'recipes' => [$this->recipe(0.03, 'kg', 'Quilograma')]]));
        $this->assertSame(['embalado_peso', 'kg', 1.0], [$kg['type'], $kg['base'], $kg['purchase_units'][0]['factor']]);
        $this->assertFalse($kg['base_change']);
    }

    public function test_bread_per_unit_and_per_kg(): void
    {
        $unit = $this->p($this->e(['purchases' => [$this->buy('PAO DO CAMPO 320G -20UN', 'Unidade', 'un', 30, 240)], 'recipes' => [$this->recipe(0.05)]]));
        $this->assertSame(['contavel', 'un', 20.0, 'Caixa 20'], [$unit['type'], $unit['base'], $unit['purchase_units'][0]['factor'], $unit['purchase_units'][0]['proposed_unit']]);

        // Comprado ao kg e usado às peças (⅓): peso, com a contagem «Peça ≈ X kg» e a pergunta do peso.
        $kg = $this->p($this->e(['base_family' => 'kg', 'base_unit' => 'Quilograma', 'purchases' => [$this->buy('PAO DE FORMA AO KG', 'Quilograma', 'kg', 40, 120)],
            'recipes' => [$this->recipe(0.333)]]), ['peso_variavel' => 'sim', 'peso_medio' => '0,8']);
        $this->assertSame(['peso', 'kg'], [$kg['type'], $kg['base']]);
        $this->assertSame('Peça ≈ 800 g', $kg['count_unit']['label']);
        $this->assertContains('peso_variavel', array_column($kg['questions'], 'id'));
    }

    public function test_olive_oil_bottle_used_in_ml(): void
    {
        $e = $this->e(['purchases' => [$this->buy('AZEITE VIRGEM EXTRA 0,75L', 'Unidade', 'un', 24, 144)], 'recipes' => [$this->recipe(0.015, 'L', 'Litro')]]);
        $p = $this->p($e);
        $this->assertSame(['embalado_peso', 'L', 0.75], [$p['type'], $p['base'], $p['purchase_units'][0]['factor']]);
        $this->assertSame('15 ml', UnitImpact::build($e, $p, 8.0)['recipes'][0]['after']);
    }

    // ── O Guião: frango, Coca-Cola em grade, molho em bidão, saco sem quantidade ─────────────────

    public function test_chicken_bought_by_kg_used_by_thirds(): void
    {
        $p = $this->p($this->e(['base_family' => 'kg', 'base_unit' => 'Quilograma', 'purchases' => [$this->buy('FRANGO INTEIRO', 'Quilograma', 'kg', 72.1, 208.37)],
            'recipes' => [$this->recipe(0.333), $this->recipe(0.5)]]));
        $this->assertSame(['peso', 'kg'], [$p['type'], $p['base']]);
        $this->assertSame('peso_variavel', $p['questions'][0]['id']);
    }

    public function test_cola_in_a_crate_of_24_is_counted_by_the_bottle(): void
    {
        $p = $this->p($this->e(['purchases' => [
            $this->buy('COCA COLA 0.35', 'Grade 24', 'un', 153, 2023.37, ['base_source' => null, 'pingwin_factor' => 24.0]),
            $this->buy('COCA COLA 0.35', 'Unidade', 'un', 504, 284.21),
        ], 'recipes' => [$this->recipe(1.0)]]));
        $this->assertSame(['contavel', 'un'], [$p['type'], $p['base']]);
        $this->assertSame([24.0, 1.0], [$p['purchase_units'][0]['factor'], $p['purchase_units'][1]['factor']]);
        $this->assertFalse($p['base_change']);
    }

    public function test_beer_crate_sold_one_to_one_is_based_on_the_bottle_never_the_litre(): void
    {
        $e = $this->e(['base_family' => 'L', 'base_unit' => 'Litro', 'family_median' => 2.92,
            'purchases' => [$this->buy('SB STOUT TR 0.33X24 GR IDR', 'Litro', 'L', 50, 689.59)], 'recipes' => [$this->recipe(0.042, 'L', 'Litro')]]);
        $p = $this->p($e);
        $this->assertSame(['embalado_inteiro', 'un', 'Garrafa'], [$p['type'], $p['base'], $p['base_name']]);
        $this->assertSame('1 Grade 24 = 24 garrafas', $p['purchase_units'][0]['label']);
        $this->assertTrue($p['base_change']);
        $this->assertSame('Mudar a unidade base de litro para garrafa', $p['base_change_warning']['title']);
        $this->assertSame('1,008 garrafas', UnitImpact::build($e, $p, 13.79)['recipes'][0]['after']);
    }

    public function test_sauce_in_a_30l_drum_used_in_fractions(): void
    {
        $e = $this->e(['purchases' => [$this->buy('MOLHO FRANCESINHA BIDON 30L', 'Unidade', 'un', 6, 540)], 'recipes' => [$this->recipe(0.009)]]);
        $p = $this->p($e);
        $this->assertSame(['embalado_peso', 'L', 30.0], [$p['type'], $p['base'], $p['purchase_units'][0]['factor']]);
        $this->assertTrue($p['base_change']);
        $this->assertSame('270 ml', UnitImpact::build($e, $p, 90.0)['recipes'][0]['after']);
    }

    public function test_bag_without_quantity_is_never_countable_by_default(): void
    {
        $p = $this->p($this->e(['purchases' => [$this->buy('FARINHA SACO', 'Unidade', 'un', 8, 96)]]));
        $this->assertFalse($p['identified']);
        $this->assertSame('qtd_embalagem', $p['questions'][0]['id']);
        $this->assertSame('Quanto tem o saco? (kg ou unidades)', $p['questions'][0]['text']);
        $answered = $this->p($this->e(['purchases' => [$this->buy('FARINHA SACO', 'Unidade', 'un', 8, 96)]]), ['qtd_embalagem' => '25|kg', 'uso' => 'dose_peso']);
        $this->assertSame(['kg', 25.0], [$answered['base'], $answered['purchase_units'][0]['factor']]);
    }

    public function test_the_article_name_never_decides(): void
    {
        // "CAFE" no nome, mas o fornecedor fatura ao kg e as fichas usam kg: peso; e com cápsulas na
        // descrição do fornecedor, contável. O mesmo nome, tipos diferentes.
        $kg = $this->p($this->e(['description' => 'CAFE', 'base_family' => 'kg', 'purchases' => [$this->buy('LOTE ESPECIAL MOIDO', 'Quilograma', 'kg', 10, 150)],
            'recipes' => [$this->recipe(0.007, 'kg', 'Quilograma')]]));
        $caps = $this->p($this->e(['description' => 'CAFE', 'purchases' => [$this->buy('LOTE ESPECIAL 50 CAPS', 'Caixa', 'un', 10, 150)]]));
        $this->assertSame('peso', $kg['type']);
        $this->assertSame('contavel', $caps['type']);
    }
}
