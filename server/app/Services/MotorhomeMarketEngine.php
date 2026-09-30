<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * XPLENDOR — FASE 1: motor de similaridade ponderada para AUTOCARAVANAS.
 *
 * Classe PURA (sem BD, sem Eloquent obrigatório — trabalha sobre uma Collection
 * de objetos com ->price/->year/->displacement/->beds/->source/->dedup_hash).
 * Toda a spec numérica vive aqui e nos testes de valores exatos
 * (tests/Unit/MotorhomeMarketEngineTest).
 *
 * Desenho endurecido por 3 refutadores independentes (2026-09-30):
 *   - score ORDENA a montra; a mediana usa TODOS os elegíveis pós-outliers
 *     (dados em falta ≠ dissemelhança; o top-N não escolhe a base do cálculo);
 *   - desconhecido → 0.5 neutro em s_cc/s_beds (com 0, um anúncio do mesmo
 *     ano sem cc parseada perdia para um de outro ano com cc);
 *   - dedupe cross-fonte ANTES de tudo (cross-posting SV+CJ fabricava n e
 *     estreitava o IQR → confiança alta artificial);
 *   - guarda grosseira (3×/⅓) em UMA passagem SIMULTÂNEA contra a mediana
 *     leave-one-out do conjunto ORIGINAL (iterativo dava 3 resultados
 *     diferentes para [9000, 30000, 95000]) e corre para TODO n≥3 (com n≥5 o
 *     fence IQR deixava sobreviver um anúncio de 1€ quando outro extremo
 *     alargava o IQR);
 *   - fence IQR 1.5 só com n≥5 e NUNCA quando IQR=0 (preços psicológicos
 *     repetidos [36000, 39900×3, 43000] faziam o fence remover anúncios
 *     normais e fabricar high);
 *   - quantis por INTERPOLAÇÃO LINEAR (R-7/NumPy default) — PHP não tem
 *     percentil nativo e a convenção muda a classe de confiança;
 *   - high exige n≥5 (3 unidades do mesmo stand ao mesmo preço davam "alta
 *     confiança" sobre o inventário de UM vendedor);
 *   - p25/p75 só com n≥4 (o min–máx de 2-3 anúncios não é uma banda).
 */
final class MotorhomeMarketEngine
{
    public const METHOD = 'motorhome_similarity_v1';

    // ── Elegibilidade ────────────────────────────────────────────────────────
    public const YEAR_WINDOW     = 2;
    public const PRICE_FLOOR     = 3000.0;
    public const PRICE_CEILING   = 300000.0;
    public const FRESHNESS_DAYS  = 60;

    // ── Pesos do score (tipologia é ELIMINATÓRIA — não pontua; o seu "peso
    //    máximo" é ser bilhete de entrada). Proporções ano≈cc>beds do modelo
    //    (0.30/0.25/0.15) renormalizadas sobre os critérios variáveis. ──────
    public const W_YEAR = 0.45;
    public const W_CC   = 0.35;
    public const W_BEDS = 0.20;

    public const UNKNOWN_NEUTRAL = 0.5;

    private const SOURCE_PRIORITY = ['standvirtual' => 0, 'custojusto' => 1];

    // ─────────────────────────────────────────────────────────────────────────
    // Componentes do score
    // ─────────────────────────────────────────────────────────────────────────

    public static function yearScore(int $deltaYears): float
    {
        return match (true) {
            $deltaYears === 0 => 1.0,
            $deltaYears === 1 => 0.8,
            $deltaYears === 2 => 0.55,
            default           => 0.0, // fora da janela — inelegível a montante
        };
    }

    public static function ccScore(?int $targetCc, ?int $snapshotCc): float
    {
        if ($targetCc === null || $snapshotCc === null) {
            return self::UNKNOWN_NEUTRAL;
        }
        $delta = abs($targetCc - $snapshotCc);

        return match (true) {
            $delta <= 100 => 1.0,
            $delta <= 250 => 0.7,
            $delta <= 500 => 0.4,
            default       => 0.0,
        };
    }

    public static function bedsScore(?int $targetBeds, ?int $snapshotBeds): float
    {
        if ($targetBeds === null || $snapshotBeds === null) {
            return self::UNKNOWN_NEUTRAL;
        }
        $delta = abs($targetBeds - $snapshotBeds);

        return match (true) {
            $delta === 0 => 1.0,
            $delta === 1 => 0.5,
            default      => 0.0,
        };
    }

    public static function similarityScore(
        int $targetYear,
        ?int $targetCc,
        ?int $targetBeds,
        int $snapshotYear,
        ?int $snapshotCc,
        ?int $snapshotBeds,
    ): float {
        $score = self::W_YEAR * self::yearScore(abs($targetYear - $snapshotYear))
            + self::W_CC * self::ccScore($targetCc, $snapshotCc)
            + self::W_BEDS * self::bedsScore($targetBeds, $snapshotBeds);

        return round($score, 4);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dedupe cross-fonte (mesma semântica do MarketSnapshotService dos carros:
    // Standvirtual vence; sem hash não há competição)
    // ─────────────────────────────────────────────────────────────────────────

    public function dedupeCrossSource(Collection $snapshots): Collection
    {
        return $snapshots
            ->sortBy(fn ($s) => self::SOURCE_PRIORITY[(string) ($s->source ?? '')] ?? PHP_INT_MAX)
            ->unique(function ($s) {
                $hash = (string) ($s->dedup_hash ?? '');

                return $hash !== '' ? $hash : 'no-hash-' . ($s->id ?? spl_object_id($s));
            })
            ->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ordenação da montra: score desc → Δano asc → Standvirtual primeiro
    // ─────────────────────────────────────────────────────────────────────────

    public function sortForDisplay(
        Collection $snapshots,
        int $targetYear,
        ?int $targetCc,
        ?int $targetBeds,
    ): Collection {
        return $snapshots
            ->map(function ($s) use ($targetYear, $targetCc, $targetBeds) {
                $s->similarity_score = self::similarityScore(
                    $targetYear,
                    $targetCc,
                    $targetBeds,
                    (int) $s->year,
                    $s->displacement !== null ? (int) $s->displacement : null,
                    $s->beds !== null ? (int) $s->beds : null,
                );

                return $s;
            })
            ->sort(function ($a, $b) use ($targetYear) {
                if ($a->similarity_score !== $b->similarity_score) {
                    return $b->similarity_score <=> $a->similarity_score;
                }
                $deltaA = abs($targetYear - (int) $a->year);
                $deltaB = abs($targetYear - (int) $b->year);
                if ($deltaA !== $deltaB) {
                    return $deltaA <=> $deltaB;
                }
                $prioA = self::SOURCE_PRIORITY[(string) ($a->source ?? '')] ?? PHP_INT_MAX;
                $prioB = self::SOURCE_PRIORITY[(string) ($b->source ?? '')] ?? PHP_INT_MAX;

                return $prioA <=> $prioB;
            })
            ->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Outliers — passo 1: guarda grosseira (todo n≥3)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * UMA passagem SIMULTÂNEA: cada preço é testado contra a mediana dos
     * RESTANTES do conjunto ORIGINAL (leave-one-out); todos os flaggados saem
     * de uma vez. Sem iteração nem re-aplicação (spec fixada pelo refutador:
     * [9000, 30000, 95000] → remove AMBOS os extremos → fica [30000]).
     *
     * @return array{kept: Collection, removed: Collection}
     */
    public function removeGrossOutliers(Collection $snapshots): array
    {
        if ($snapshots->count() < 3) {
            return ['kept' => $snapshots->values(), 'removed' => collect()];
        }

        $prices = $snapshots->map(fn ($s) => (float) $s->price)->values()->all();

        $flags = [];
        foreach ($prices as $i => $price) {
            $others = $prices;
            unset($others[$i]);
            sort($others);
            $medianOthers = self::medianOfSorted(array_values($others));

            $flags[$i] = $medianOthers > 0.0
                && ($price > 3.0 * $medianOthers || $price < $medianOthers / 3.0);
        }

        $kept = collect();
        $removed = collect();
        foreach ($snapshots->values() as $i => $snapshot) {
            $flags[$i] ? $removed->push($snapshot) : $kept->push($snapshot);
        }

        return ['kept' => $kept->values(), 'removed' => $removed->values()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Outliers — passo 2: fence IQR 1.5 (só n≥5; IQR=0 → no-op)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{kept: Collection, removed: Collection} */
    public function applyIqrFence(Collection $snapshots): array
    {
        if ($snapshots->count() < 5) {
            return ['kept' => $snapshots->values(), 'removed' => collect()];
        }

        $sorted = $snapshots->map(fn ($s) => (float) $s->price)->sort()->values()->all();
        $p25 = self::quantileR7($sorted, 0.25);
        $p75 = self::quantileR7($sorted, 0.75);
        $iqr = $p75 - $p25;

        if ($iqr <= 0.0) {
            // Preços psicológicos repetidos: o fence degeneraria num ponto e
            // removeria anúncios perfeitamente normais. Não aplicar.
            return ['kept' => $snapshots->values(), 'removed' => collect()];
        }

        $low  = $p25 - 1.5 * $iqr;
        $high = $p75 + 1.5 * $iqr;

        [$kept, $removed] = $snapshots->values()->partition(
            fn ($s) => (float) $s->price >= $low && (float) $s->price <= $high
        );

        return ['kept' => $kept->values(), 'removed' => $removed->values()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quantis — interpolação linear (R-7, o default do NumPy/R)
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<float> $sortedPrices ordenados ascendente, n>=1 */
    public static function quantileR7(array $sortedPrices, float $q): float
    {
        $n = \count($sortedPrices);
        if ($n === 1) {
            return $sortedPrices[0];
        }

        $h     = ($n - 1) * $q;
        $lower = (int) floor($h);
        $upper = (int) ceil($h);
        $frac  = $h - $lower;

        return $sortedPrices[$lower] + $frac * ($sortedPrices[$upper] - $sortedPrices[$lower]);
    }

    private static function medianOfSorted(array $sorted): float
    {
        $n = \count($sorted);
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 0
            ? ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0
            : $sorted[(int) ($n / 2)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Confiança: volume + dispersão
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * n=0 → none · n=1..2 → low (preço indicativo SEMPRE visível) ·
     * n=3..4 → medium no máximo (dispersão má despromove a low; a dispersão
     * em n=3 usa a amplitude total, documentado) · n≥5 → IQR_rel:
     * ≤0.25 high · ≤0.50 medium · >0.50 low; se p25==p75 (IQR=0), usa
     * (max−min)/mediana para a dispersão não mentir.
     */
    public function deriveConfidence(
        int $usedCount,
        float $median,
        ?float $p25,
        ?float $p75,
        float $min,
        float $max,
    ): string {
        if ($usedCount <= 0) {
            return 'none';
        }
        if ($usedCount <= 2) {
            return 'low';
        }

        $dispersion = $this->relativeDispersion($median, $p25, $p75, $min, $max);

        if ($usedCount <= 4) {
            return $dispersion <= 0.50 ? 'medium' : 'low';
        }

        return match (true) {
            $dispersion <= 0.25 => 'high',
            $dispersion <= 0.50 => 'medium',
            default             => 'low',
        };
    }

    private function relativeDispersion(float $median, ?float $p25, ?float $p75, float $min, float $max): float
    {
        if ($median <= 0.0) {
            return INF;
        }
        if ($p25 !== null && $p75 !== null && $p75 > $p25) {
            return ($p75 - $p25) / $median;
        }

        // p25==p75 (IQR=0) ou n<4 (sem quantis): amplitude total.
        return ($max - $min) / $median;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pipeline completo (ordem FIXA): dedupe → score/sort → guarda grosseira
    // → fence IQR → estatísticas → confiança → top 5 por score
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array{year: int, cc: ?int, beds: ?int} $target
     * @return array pronto a persistir em car_market_aggregates (sem funnel)
     */
    public function compute(array $target, Collection $eligiblePool): array
    {
        $deduped = $this->dedupeCrossSource($eligiblePool);
        $scored  = $this->sortForDisplay($deduped, $target['year'], $target['cc'], $target['beds']);

        $gross = $this->removeGrossOutliers($scored);
        $fence = $this->applyIqrFence($gross['kept']);

        $used            = $fence['kept'];
        $outliersRemoved = $gross['removed']->count() + $fence['removed']->count();

        if ($used->isEmpty()) {
            return [
                'status'            => 'none',
                'method'            => self::METHOD,
                'confidence'        => 'none',
                'comparables_count' => 0,
                'outliers_removed'  => $outliersRemoved,
                'fallback_used'     => false,
                'top_comparables'   => null,
                'sources_breakdown' => null,
                // NÃO é coluna: o serviço move-a para o funil (degrau
                // "after_dedupe"), fechando o invariante
                // after_dedupe − outliers_removed = comparables_count.
                'pool_after_dedupe' => $deduped->count(),
            ];
        }

        $prices = $used->map(fn ($s) => (float) $s->price)->sort()->values()->all();
        $n      = \count($prices);

        $median = round(self::quantileR7($prices, 0.50), 2);
        $p25    = $n >= 4 ? round(self::quantileR7($prices, 0.25), 2) : null;
        $p75    = $n >= 4 ? round(self::quantileR7($prices, 0.75), 2) : null;
        $min    = round($prices[0], 2);
        $max    = round($prices[$n - 1], 2);
        $avg    = round(array_sum($prices) / $n, 2);
        $std    = round(sqrt(array_sum(array_map(fn ($p) => ($p - $avg) ** 2, $prices)) / $n), 2);

        return [
            'status'            => 'success',
            'method'            => self::METHOD,
            'confidence'        => $this->deriveConfidence($n, $median, $p25, $p75, $min, $max),
            'comparables_count' => $n,
            'outliers_removed'  => $outliersRemoved,
            'median_price'      => $median,
            'p25_price'         => $p25,
            'p75_price'         => $p75,
            'min_price'         => $min,
            'max_price'         => $max,
            'avg_price'         => $avg,
            'std_dev'           => $std,
            'fallback_used'     => false,
            'top_comparables'   => $this->topComparables($used),
            'sources_breakdown' => $used->countBy(fn ($s) => (string) ($s->source ?? 'unknown'))->toArray(),
            // NÃO é coluna: o serviço move-a para o funil (ver ramo none).
            'pool_after_dedupe' => $deduped->count(),
        ];
    }

    /** Top 5 POR SCORE (os mais parecidos, já sem outliers) — não por
     *  proximidade à mediana como nos carros. */
    private function topComparables(Collection $usedSortedByScore): array
    {
        return $usedSortedByScore
            ->take(5)
            ->map(fn ($s) => [
                'external_id'      => $s->external_id,
                'source'           => $s->source,
                'title'            => $s->title ?? trim(($s->brand ?? '') . ' ' . ($s->model ?? '') . ' ' . ($s->year ?? '')),
                'url'              => $s->url,
                'price'            => (float) $s->price,
                'year'             => $s->year,
                'region'           => $s->region,
                'similarity_score' => $s->similarity_score ?? null,
                'beds'             => $s->beds !== null ? (int) $s->beds : null,
                'displacement'     => $s->displacement !== null ? (int) $s->displacement : null,
                'length'           => $s->length !== null ? (float) $s->length : null,
                'scraped_at'       => $s->scraped_at ? (string) $s->scraped_at : null,
            ])
            ->values()
            ->toArray();
    }
}
