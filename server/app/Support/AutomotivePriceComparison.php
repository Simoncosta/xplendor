<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Car;
use App\Models\CarMarketAggregate;

/**
 * XPLENDOR — Hub do Automóvel: posição de preço com a salvaguarda de FALLBACK.
 *
 * Lê a posição da fonte única (PricePosition, sem a recalcular) e acrescenta se a
 * comparação é EXATA ou APROXIMADA:
 *   · exata      → o aggregate veio do degrau principal (fallback_used = false);
 *   · aproximada → veio de um degrau de recurso (ano alargado, sem combustível/
 *                  caixa/potência, ou, nas autocaravanas antigas, sem o modelo).
 *
 * Uma comparação aproximada NUNCA conta como "acima do mercado" (nem para a
 * percentagem do topo nem para a regra de preço): mostra-se como "comparação
 * aproximada". A regra de confiança mínima (média/alta) já vem do PricePosition.
 */
final class AutomotivePriceComparison
{
    public const EXACT = 'exact';
    public const APPROXIMATE = 'approximate';

    /** Posição mostrada no hub quando a comparação é aproximada. */
    public const DISPLAY_APPROXIMATE = 'approximate_comparison';

    public static function forCar(Car $car): array
    {
        return self::fromPosition(PricePosition::for($car), PricePosition::latestAggregateFor($car));
    }

    /**
     * @param  array  $position  saída de PricePosition::compute()/for()
     * @return array  $position + comparison, counts_as_above_market, display_position
     */
    public static function fromPosition(array $position, ?CarMarketAggregate $aggregate): array
    {
        $comparison = null;
        if ($position['available'] ?? false) {
            $comparison = ($aggregate && $aggregate->fallback_used) ? self::APPROXIMATE : self::EXACT;
        }

        $display = $position['position'] ?? 'insufficient_data';
        if ($comparison === self::APPROXIMATE) {
            $display = self::DISPLAY_APPROXIMATE;
        }

        return $position + [
            'comparison' => $comparison,
            'counts_as_above_market' => $comparison === self::EXACT && ($position['position'] ?? null) === 'above_market',
            'display_position' => $display,
        ];
    }

    public static function isApproximate(Car $car): bool
    {
        $aggregate = PricePosition::latestAggregateFor($car);

        return $aggregate !== null && (bool) $aggregate->fallback_used;
    }
}
