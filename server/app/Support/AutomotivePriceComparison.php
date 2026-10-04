<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Car;
use App\Models\CarMarketAggregate;

/**
 * XPLENDOR — Hub do Automóvel: posição de preço com a salvaguarda de FALLBACK.
 *
 * Lê a posição da fonte única (PricePosition, sem a recalcular) e acrescenta se a
 * comparação é EXATA ou APROXIMADA. Decisão do utilizador: APROXIMADO só quando a
 * comparação LARGA O MODELO.
 *   · exata                → degrau principal, ou degraus que mantêm marca e modelo
 *                            e só alargam o ano ou largam combustível/caixa/potência
 *                            (carros, degraus 2 e 3): neste caso com a etiqueta
 *                            discreta criteria_widened ("critérios alargados");
 *   · aproximada           → degraus que largam o modelo (categoria; marca + faixa
 *                            de preço). Só existiram na cascata ANTIGA das
 *                            autocaravanas (method null + fallback_used); desde a
 *                            Fase 1 as autocaravanas usam o motor de semelhança e os
 *                            carros nunca largam o modelo. Num aggregate antigo de
 *                            autocaravana com recurso não se sabe se foi o degrau 2
 *                            (com modelo) ou o 4/5 (sem modelo): conta como
 *                            aproximado (prudente) até ser recalculado.
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
    /** A comparação deste aggregate pode ter largado o modelo? */
    public static function dropsModel(?CarMarketAggregate $aggregate): bool
    {
        return $aggregate !== null
            && (bool) $aggregate->fallback_used
            && $aggregate->method === null
            && in_array($aggregate->vehicle_type, ['motorhome', 'caravan'], true);
    }

    public static function fromPosition(array $position, ?CarMarketAggregate $aggregate): array
    {
        $comparison = null;
        if ($position['available'] ?? false) {
            $comparison = self::dropsModel($aggregate) ? self::APPROXIMATE : self::EXACT;
        }

        $display = $position['position'] ?? 'insufficient_data';
        if ($comparison === self::APPROXIMATE) {
            $display = self::DISPLAY_APPROXIMATE;
        }

        return $position + [
            'comparison' => $comparison,
            'counts_as_above_market' => $comparison === self::EXACT && ($position['position'] ?? null) === 'above_market',
            'display_position' => $display,
            // Exata, mas com critérios alargados (ano alargado, sem combustível/caixa/potência).
            'criteria_widened' => $comparison === self::EXACT && $aggregate !== null && (bool) $aggregate->fallback_used,
        ];
    }

    public static function isApproximate(Car $car): bool
    {
        return self::dropsModel(PricePosition::latestAggregateFor($car));
    }
}
