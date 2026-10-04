<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Support\PricePosition;

/**
 * Escala de prioridade e desempates das regras do ramo Automóvel.
 *
 * As fórmulas das regras (herdadas do CarIssueEngine) somam bónus e chegavam a 104
 * ou mais: tudo saturava em 100 e o desempate caía na ordem das regras e no ID.
 * Agora (decisão do utilizador):
 *   · até 70 a prioridade fica igual (os níveis Alta ≥ 70 / Média ≥ 40 mantêm-se);
 *   · acima de 70 o topo é comprimido (70 + 60% do excesso), no máximo 95;
 *   · 100 fica reservado a "anúncio de viatura vendida ainda ativo" com gasto
 *     depois da venda (é dinheiro a sair sem retorno possível).
 * Desempate, por esta ordem: IMPACTO em euros (gasto depois da venda, capital
 * parado, gasto sem lead) e depois os DIAS acima do limiar do tipo.
 */
final class AutomotivePriority
{
    public const MAX_REGULAR = 95;
    public const RESERVED_MAX = 100;

    public static function scale(int|float $raw): int
    {
        $raw = (float) $raw;
        if ($raw <= 70) {
            return max(0, (int) round($raw));
        }

        return min(self::MAX_REGULAR, (int) round(70 + ($raw - 70) * 0.6));
    }

    /** Dias acima do limiar do tipo (StockThresholds); 0 se ainda não chegou. */
    public static function daysOverThreshold(Car $car, int $daysInStock): int
    {
        return max(0, $daysInStock - StockThresholds::ageThresholdFor($car->vehicle_type));
    }

    /** Capital parado: o preço efetivo de uma viatura que já passou o limiar do tipo. */
    public static function stuckCapital(Car $car, int $daysInStock): float
    {
        return self::daysOverThreshold($car, $daysInStock) > 0
            ? round((float) (PricePosition::effectivePriceForCar($car) ?? 0), 2)
            : 0.0;
    }
}
