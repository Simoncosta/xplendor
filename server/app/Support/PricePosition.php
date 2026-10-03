<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Car;
use App\Models\CarMarketAggregate;

/**
 * XPLENDOR — PREÇO VS MERCADO: uma única fonte de verdade.
 *
 * Compara o preço ATUAL da viatura (preço efetivo) com a MEDIANA do último
 * car_market_aggregates com mediana (inclui o motor de similaridade das
 * autocaravanas). Antes coexistiam duas contas: a inteligência de mercado sobre
 * snapshots crus (±5%) e o sinal do aggregate (4 faixas, com a cópia do preço
 * guardada no aggregate). Agora há uma.
 *
 *   Preço efetivo (regra única): promo se > 0 e < preço bruto; senão bruto.
 *   4 faixas (diferença %): ≥10 overpriced · ≥3 slightly_high · ≥-5 fair · resto competitive.
 *   Escala de 3 (para o IPS e consumidores antigos), DERIVADA das faixas:
 *     competitive → below_market · fair → aligned_market · slightly_high|overpriced → above_market.
 *   Confiança mínima: só se sinaliza "acima do mercado" com confiança ≥ medium no
 *   aggregate; abaixo disso a posição fica 'insufficient_data' (não se pede para
 *   baixar o preço com base em poucos dados).
 */
final class PricePosition
{
    public const MIN_CONFIDENCE_FOR_ABOVE = 'medium';

    private const CONFIDENCE_RANK = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    /** Regra ÚNICA do preço efetivo. */
    public static function effectivePrice(mixed $gross, mixed $promo): ?float
    {
        $g = $gross !== null && (float) $gross > 0 ? (float) $gross : null;
        $p = $promo !== null ? (float) $promo : null;

        if ($g !== null && $p !== null && $p > 0 && $p < $g) {
            return round($p, 2);
        }

        return $g !== null ? round($g, 2) : null;
    }

    public static function effectivePriceForCar(Car $car): ?float
    {
        return self::effectivePrice($car->price_gross, $car->promo_price_gross);
    }

    /** Diferença % do preço face à mediana (positivo = acima do mercado). */
    public static function differencePct(?float $price, ?float $median): ?float
    {
        if ($price === null || $median === null || $median <= 0) {
            return null;
        }

        return round(($price - $median) / $median * 100, 2);
    }

    /** As 4 faixas. */
    public static function band(?float $diffPct): ?string
    {
        if ($diffPct === null) {
            return null;
        }

        return match (true) {
            $diffPct >= 10.0 => 'overpriced',
            $diffPct >= 3.0 => 'slightly_high',
            $diffPct >= -5.0 => 'fair',
            default => 'competitive',
        };
    }

    /** Escala de 3 derivada das faixas. */
    public static function threeScale(?string $band): string
    {
        return match ($band) {
            'competitive' => 'below_market',
            'fair' => 'aligned_market',
            'slightly_high', 'overpriced' => 'above_market',
            default => 'insufficient_data',
        };
    }

    public static function meetsMinConfidence(?string $confidence): bool
    {
        return (self::CONFIDENCE_RANK[$confidence ?? 'none'] ?? 0) >= self::CONFIDENCE_RANK[self::MIN_CONFIDENCE_FOR_ABOVE];
    }

    /** Último aggregate COM mediana da viatura (o que serve de referência). */
    public static function latestAggregateFor(Car $car): ?CarMarketAggregate
    {
        if ($car->relationLoaded('latestPricedMarketAggregate')) {
            return $car->getRelation('latestPricedMarketAggregate');
        }

        return CarMarketAggregate::where('car_id', $car->id)
            ->whereNotNull('median_price')
            ->where('median_price', '>', 0)
            ->orderByDesc('id')
            ->first();
    }

    /** Posição da viatura (vai buscar o último aggregate). */
    public static function for(Car $car): array
    {
        return self::compute($car, self::latestAggregateFor($car));
    }

    /**
     * Posição com um aggregate já carregado (evita uma query por linha em listas).
     *
     * @return array{available: bool, aggregate_id: ?int, method: ?string, confidence: ?string,
     *   comparables_count: int, median: ?float, p25: ?float, p75: ?float, effective_price: ?float,
     *   difference_pct: ?float, band: ?string, position: string, signal_suppressed: bool}
     */
    public static function compute(Car $car, ?CarMarketAggregate $aggregate): array
    {
        $price = self::effectivePriceForCar($car);
        $median = $aggregate && $aggregate->median_price !== null ? (float) $aggregate->median_price : null;
        $diff = self::differencePct($price, $median);
        $band = self::band($diff);

        $position = self::threeScale($band);
        $suppressed = false;
        if ($position === 'above_market' && ! self::meetsMinConfidence($aggregate?->confidence)) {
            $position = 'insufficient_data';
            $suppressed = true;
        }

        return [
            'available' => $band !== null,
            'aggregate_id' => $aggregate?->id,
            'method' => $aggregate?->method,
            'confidence' => $aggregate?->confidence,
            'comparables_count' => (int) ($aggregate?->comparables_count ?? 0),
            'median' => $median !== null ? round($median, 2) : null,
            'p25' => $aggregate && $aggregate->p25_price !== null ? round((float) $aggregate->p25_price, 2) : null,
            'p75' => $aggregate && $aggregate->p75_price !== null ? round((float) $aggregate->p75_price, 2) : null,
            'effective_price' => $price,
            'difference_pct' => $diff,
            'band' => $band,
            'position' => $position,
            'signal_suppressed' => $suppressed,
        ];
    }

    /**
     * Preço efetivo em SQL (mesma regra), para filtros sobre `cars`. $table = tabela
     * ou alias de `cars`.
     */
    public static function effectivePriceSql(string $table = 'cars'): string
    {
        return "(CASE WHEN {$table}.promo_price_gross > 0 AND {$table}.price_gross > 0 AND {$table}.promo_price_gross < {$table}.price_gross "
            . "THEN {$table}.promo_price_gross ELSE {$table}.price_gross END)";
    }
}
