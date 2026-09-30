<?php

namespace Tests\Unit;

use App\Services\MotorhomeMarketEngine;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * FASE 1 — testes do ALGORITMO (puro, sem BD).
 *
 * Os casos numéricos vêm da passagem adversarial do desenho (3 refutadores):
 * cada fixture abaixo correspondeu a uma falha demonstrada no desenho inicial
 * e fixa o comportamento endurecido. Valores EXATOS — a convenção de quantil
 * (R-7) muda a classe de confiança, por isso está pregada aqui.
 */
class MotorhomeMarketEngineTest extends TestCase
{
    private MotorhomeMarketEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new MotorhomeMarketEngine();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function pool(array $rows): Collection
    {
        return collect($rows)->map(function (array $row, int $i) {
            return (object) array_merge([
                'id'           => $i + 1,
                'external_id'  => 'ext-' . ($i + 1),
                'source'       => 'standvirtual',
                'title'        => 'Snapshot ' . ($i + 1),
                'url'          => 'https://example.test/' . ($i + 1),
                'year'         => 2020,
                'price'        => 40000.0,
                'beds'         => null,
                'displacement' => null,
                'length'       => null,
                'region'       => null,
                'dedup_hash'   => 'hash-' . ($i + 1),
                'scraped_at'   => '2026-09-30 00:00:00',
            ], $row);
        });
    }

    private function target(int $year = 2020, ?int $cc = 2287, ?int $beds = 4): array
    {
        return ['year' => $year, 'cc' => $cc, 'beds' => $beds];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Score — componentes e a regra desconhecido→0.5
    // ─────────────────────────────────────────────────────────────────────

    public function test_score_componentes_valores_exatos(): void
    {
        $this->assertSame(1.0, MotorhomeMarketEngine::yearScore(0));
        $this->assertSame(0.8, MotorhomeMarketEngine::yearScore(1));
        $this->assertSame(0.55, MotorhomeMarketEngine::yearScore(2));

        $this->assertSame(1.0, MotorhomeMarketEngine::ccScore(2287, 2300));   // Δ13
        $this->assertSame(0.7, MotorhomeMarketEngine::ccScore(2287, 2100));   // Δ187
        $this->assertSame(0.4, MotorhomeMarketEngine::ccScore(2287, 2787));   // Δ500
        $this->assertSame(0.0, MotorhomeMarketEngine::ccScore(2287, 3000));   // Δ713
        $this->assertSame(0.5, MotorhomeMarketEngine::ccScore(2287, null));
        $this->assertSame(0.5, MotorhomeMarketEngine::ccScore(null, 2300));

        $this->assertSame(1.0, MotorhomeMarketEngine::bedsScore(4, 4));
        $this->assertSame(0.5, MotorhomeMarketEngine::bedsScore(4, 5));
        $this->assertSame(0.0, MotorhomeMarketEngine::bedsScore(4, 6));
        $this->assertSame(0.5, MotorhomeMarketEngine::bedsScore(null, 4));
    }

    public function test_desconhecido_nao_e_dissemelhanca_caso_do_painel(): void
    {
        // Refutador matemático: com desconhecido→0, o anúncio A (mesmo ano,
        // cc/beds por parsear) perdia para B (2 anos off). Com 0.5 a ordem
        // correta é restaurada: A=0.725 > B=0.5925.
        $a = MotorhomeMarketEngine::similarityScore(2020, 2287, 4, 2020, null, null);
        $b = MotorhomeMarketEngine::similarityScore(2020, 2287, 4, 2018, 2179, 3);

        $this->assertSame(0.725, $a);
        $this->assertSame(0.5925, $b);
        $this->assertGreaterThan($b, $a);
    }

    public function test_ordenacao_score_desc_depois_delta_ano_depois_fonte(): void
    {
        $pool = $this->pool([
            ['external_id' => 'cj-2019',  'source' => 'custojusto',   'year' => 2019],
            ['external_id' => 'sv-2019',  'source' => 'standvirtual', 'year' => 2019],
            ['external_id' => 'sv-2021',  'source' => 'standvirtual', 'year' => 2021],
            ['external_id' => 'sv-2020',  'source' => 'standvirtual', 'year' => 2020],
        ]);

        $sorted = $this->engine->sortForDisplay($pool, 2020, null, null)
            ->pluck('external_id')->all();

        // 2020 (Δ0) primeiro; os três Δ1 empatam no score → Δano igual →
        // Standvirtual antes de CustoJusto.
        $this->assertSame('sv-2020', $sorted[0]);
        $this->assertContains($sorted[1], ['sv-2019', 'sv-2021']);
        $this->assertSame('cj-2019', $sorted[3]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Outliers — guarda grosseira SIMULTÂNEA (fixture do painel)
    // ─────────────────────────────────────────────────────────────────────

    public function test_guarda_grosseira_uma_passagem_simultanea(): void
    {
        // [9000, 30000, 95000]: leave-one-out no conjunto ORIGINAL flagga
        // AMBOS os extremos (9000 < 62500/3; 95000 > 3×19500) e remove-os
        // de uma vez. Iterativo daria 3 resultados diferentes — proibido.
        $pool = $this->pool([
            ['price' => 9000.0],
            ['price' => 30000.0],
            ['price' => 95000.0],
        ]);

        $result = $this->engine->removeGrossOutliers($pool);

        $this->assertSame([30000.0], $result['kept']->pluck('price')->all());
        $this->assertCount(2, $result['removed']);
    }

    public function test_guarda_grosseira_corre_tambem_com_n5_apanha_o_anuncio_de_1_euro(): void
    {
        // Refutador: com n>=5 o fence IQR deixava sobreviver 1€ porque o
        // outro extremo alargava o IQR. A guarda corre ANTES, para todo n>=3.
        // (1€ passa aqui porque a elegibilidade é testada a montante — este
        // teste prova a guarda em si.)
        $pool = $this->pool([
            ['price' => 1.0],
            ['price' => 30000.0],
            ['price' => 31000.0],
            ['price' => 60000.0],
            ['price' => 65000.0],
        ]);

        $result = $this->engine->removeGrossOutliers($pool);

        $this->assertSame([30000.0, 31000.0, 60000.0, 65000.0], $result['kept']->pluck('price')->all());
        $this->assertSame([1.0], $result['removed']->pluck('price')->all());
    }

    public function test_guarda_grosseira_nao_corre_com_menos_de_3(): void
    {
        $pool = $this->pool([['price' => 1000.0], ['price' => 90000.0]]);
        $result = $this->engine->removeGrossOutliers($pool);

        $this->assertCount(2, $result['kept']);
        $this->assertCount(0, $result['removed']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Outliers — fence IQR (n>=5) e a regra IQR=0
    // ─────────────────────────────────────────────────────────────────────

    public function test_fence_iqr_nao_aplica_quando_iqr_zero(): void
    {
        // Preços psicológicos repetidos: p25=p75=39900 → fence degeneraria
        // num ponto e removeria os anúncios normais de 36000 e 43000.
        $pool = $this->pool([
            ['price' => 36000.0],
            ['price' => 39900.0],
            ['price' => 39900.0],
            ['price' => 39900.0],
            ['price' => 43000.0],
        ]);

        $result = $this->engine->applyIqrFence($pool);

        $this->assertCount(5, $result['kept']);
        $this->assertCount(0, $result['removed']);
    }

    public function test_fence_iqr_remove_extremo_com_n5(): void
    {
        // [30000, 32000, 34000, 36000, 90000]: R-7 p25=32000, p75=36000,
        // IQR=4000 → fence [26000, 42000] → 90000 removido.
        $pool = $this->pool([
            ['price' => 30000.0],
            ['price' => 32000.0],
            ['price' => 34000.0],
            ['price' => 36000.0],
            ['price' => 90000.0],
        ]);

        $result = $this->engine->applyIqrFence($pool);

        $this->assertSame([30000.0, 32000.0, 34000.0, 36000.0], $result['kept']->pluck('price')->sort()->values()->all());
        $this->assertSame([90000.0], $result['removed']->pluck('price')->all());
    }

    public function test_fence_iqr_nao_corre_abaixo_de_5(): void
    {
        $pool = $this->pool([
            ['price' => 30000.0], ['price' => 31000.0],
            ['price' => 32000.0], ['price' => 90000.0],
        ]);

        $result = $this->engine->applyIqrFence($pool);
        $this->assertCount(4, $result['kept']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quantis R-7 — valores EXATOS (a convenção muda a classe de confiança)
    // ─────────────────────────────────────────────────────────────────────

    public function test_quantis_r7_valores_exatos_caso_bimodal_do_painel(): void
    {
        $prices = [30000.0, 31000.0, 39000.0, 40000.0];

        $this->assertSame(30750.0, MotorhomeMarketEngine::quantileR7($prices, 0.25));
        $this->assertSame(35000.0, MotorhomeMarketEngine::quantileR7($prices, 0.50));
        $this->assertSame(39250.0, MotorhomeMarketEngine::quantileR7($prices, 0.75));
    }

    public function test_quantis_r7_n_impar_e_n1(): void
    {
        $this->assertSame(31000.0, MotorhomeMarketEngine::quantileR7([30000.0, 31000.0, 39000.0], 0.50));
        $this->assertSame(30500.0, MotorhomeMarketEngine::quantileR7([30000.0, 31000.0, 39000.0], 0.25));
        $this->assertSame(42000.0, MotorhomeMarketEngine::quantileR7([42000.0], 0.50));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Confiança — volume + dispersão
    // ─────────────────────────────────────────────────────────────────────

    public function test_high_exige_n5_mesmo_com_dispersao_zero(): void
    {
        // 3 unidades do mesmo stand ao mesmo preço NÃO são o mercado.
        $this->assertSame(
            'medium',
            $this->engine->deriveConfidence(3, 39900.0, null, null, 39900.0, 39900.0)
        );
        // Caso bimodal n=4 (IQR_rel 0.243) satura em medium — nunca high.
        $this->assertSame(
            'medium',
            $this->engine->deriveConfidence(4, 35000.0, 30750.0, 39250.0, 30000.0, 40000.0)
        );
    }

    public function test_confianca_n5_por_iqr_rel(): void
    {
        // IQR_rel = (40000-20000)/30000 = 0.667 → low apesar de n=5.
        $this->assertSame(
            'low',
            $this->engine->deriveConfidence(5, 30000.0, 20000.0, 40000.0, 10000.0, 50000.0)
        );
        // IQR_rel = (36000-32000)/34000 = 0.118 → high.
        $this->assertSame(
            'high',
            $this->engine->deriveConfidence(5, 34000.0, 32000.0, 36000.0, 30000.0, 36000.0)
        );
    }

    public function test_confianca_iqr_zero_usa_amplitude_total(): void
    {
        // p25==p75: [36000..43000] amplitude 0.175 → high (spread real ok)…
        $this->assertSame(
            'high',
            $this->engine->deriveConfidence(5, 39900.0, 39900.0, 39900.0, 36000.0, 43000.0)
        );
        // …mas [20000..70000] amplitude 1.253 → low (o high fabricado morreu).
        $this->assertSame(
            'low',
            $this->engine->deriveConfidence(5, 39900.0, 39900.0, 39900.0, 20000.0, 70000.0)
        );
    }

    public function test_confianca_n_baixo(): void
    {
        $this->assertSame('low', $this->engine->deriveConfidence(1, 38000.0, null, null, 38000.0, 38000.0));
        $this->assertSame('low', $this->engine->deriveConfidence(2, 29000.0, null, null, 20000.0, 38000.0));
        $this->assertSame('none', $this->engine->deriveConfidence(0, 0.0, null, null, 0.0, 0.0));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dedupe cross-fonte — o mesmo veículo em SV+CJ não fabrica volume
    // ─────────────────────────────────────────────────────────────────────

    public function test_dedupe_cross_fonte_impede_confianca_fabricada(): void
    {
        $pool = $this->pool([
            ['source' => 'custojusto',   'price' => 39900.0, 'dedup_hash' => 'same-vehicle'],
            ['source' => 'standvirtual', 'price' => 39900.0, 'dedup_hash' => 'same-vehicle'],
            ['source' => 'standvirtual', 'price' => 41500.0, 'dedup_hash' => 'other'],
        ]);

        $result = $this->engine->compute($this->target(), $pool);

        // 3 linhas → 2 veículos reais → low (não medium com n=3).
        $this->assertSame(2, $result['comparables_count']);
        $this->assertSame('low', $result['confidence']);
        // O Standvirtual vence o cross-posting.
        $this->assertSame(
            ['standvirtual' => 2],
            $result['sources_breakdown']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pipeline completo
    // ─────────────────────────────────────────────────────────────────────

    public function test_compute_pipeline_completo_com_outlier_e_banda(): void
    {
        $pool = $this->pool([
            ['price' => 36000.0, 'year' => 2020, 'displacement' => 2287, 'beds' => 4],
            ['price' => 38000.0, 'year' => 2019, 'displacement' => 2300, 'beds' => 4],
            ['price' => 39900.0, 'year' => 2020, 'displacement' => null, 'beds' => null],
            ['price' => 41000.0, 'year' => 2021, 'displacement' => 2200, 'beds' => 5],
            ['price' => 43000.0, 'year' => 2018, 'displacement' => 2287, 'beds' => 4],
            ['price' => 200000.0, 'year' => 2020, 'displacement' => 2287, 'beds' => 4], // grosseiro (>3× mediana dos restantes)
        ]);

        $result = $this->engine->compute($this->target(), $pool);

        $this->assertSame('success', $result['status']);
        $this->assertSame('motorhome_similarity_v1', $result['method']);
        $this->assertSame(5, $result['comparables_count']);
        $this->assertSame(1, $result['outliers_removed']);
        $this->assertSame(39900.0, $result['median_price']);
        $this->assertNotNull($result['p25_price']);
        $this->assertNotNull($result['p75_price']);
        $this->assertFalse($result['fallback_used']);

        // Top por SCORE: o 36000 (Δ0 ano, cc igual, beds igual → 1.0) primeiro;
        // o outlier removido NÃO aparece na montra.
        $top = $result['top_comparables'];
        $this->assertSame(36000.0, $top[0]['price']);
        $this->assertSame(1.0, $top[0]['similarity_score']);
        $this->assertNotContains(200000.0, array_column($top, 'price'));
        $this->assertArrayHasKey('length', $top[0]);
        $this->assertArrayHasKey('scraped_at', $top[0]);
    }

    public function test_compute_n1_2_devolve_preco_indicativo_nunca_vazio(): void
    {
        $result = $this->engine->compute($this->target(), $this->pool([
            ['price' => 38000.0, 'year' => 2020],
        ]));

        $this->assertSame('success', $result['status']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(38000.0, $result['median_price']);
        $this->assertNull($result['p25_price']);
        $this->assertNull($result['p75_price']);
        $this->assertCount(1, $result['top_comparables']);
    }

    public function test_compute_pool_vazio_devolve_none(): void
    {
        $result = $this->engine->compute($this->target(), collect());

        $this->assertSame('none', $result['status']);
        $this->assertSame('none', $result['confidence']);
        $this->assertSame(0, $result['comparables_count']);
        $this->assertNull($result['top_comparables']);
    }

    public function test_compute_outliers_reduzem_3_para_1_e_confianca_cai_para_low(): void
    {
        $result = $this->engine->compute($this->target(), $this->pool([
            ['price' => 9000.0,  'year' => 2020],
            ['price' => 30000.0, 'year' => 2020],
            ['price' => 95000.0, 'year' => 2020],
        ]));

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['comparables_count']);
        $this->assertSame(2, $result['outliers_removed']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(30000.0, $result['median_price']);
    }
}
