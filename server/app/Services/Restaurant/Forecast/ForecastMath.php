<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Forecast;

/** Fatia 6a: as contas da previsão (sem base de dados), para se testarem à parte. */
final class ForecastMath
{
    /** A média sem o maior e o menor (com 3 ou mais valores); com menos, null. */
    public static function trimmedMean(array $values): ?float
    {
        $values = array_values(array_map('floatval', $values));
        if (count($values) < 3) {
            return null;
        }
        sort($values);
        array_shift($values);
        array_pop($values);

        return array_sum($values) / count($values);
    }

    public static function median(array $values): ?float
    {
        $v = array_values(array_map('floatval', $values));
        if (! $v) {
            return null;
        }
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /** O percentil p (0 a 100), com interpolação linear. */
    public static function percentile(array $values, float $p): ?float
    {
        $v = array_values(array_map('floatval', $values));
        if (! $v) {
            return null;
        }
        sort($v);
        $pos = ($p / 100) * (count($v) - 1);
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $v[$lo] + ($v[$hi] - $v[$lo]) * ($pos - $lo);
    }

    /** O erro percentual de uma previsão: (previsto − real) ÷ real. Real 0 → null (não conta). */
    public static function pctError(float $forecast, float $actual): ?float
    {
        return $actual > 0 ? ($forecast - $actual) / $actual : null;
    }

    /** O erro percentual médio (em %) de uma lista de erros relativos. */
    public static function mape(array $errors): ?float
    {
        return $errors ? array_sum(array_map('abs', $errors)) / count($errors) * 100 : null;
    }

    /**
     * O intervalo do valor real a partir da previsão e dos percentis 10 e 90 dos erros: real = previsto ÷ (1 + erro).
     *
     * @return array{0: float, 1: float}
     */
    public static function interval(float $forecast, float $p10, float $p90): array
    {
        $lo = $forecast / max(0.05, 1 + $p90);
        $hi = $forecast / max(0.05, 1 + $p10);

        return [min($lo, $hi), max($lo, $hi)];
    }

    /** A razão das reservas, com limites. */
    public static function reservationRatio(float $now, float $median, float $min = 0.7, float $max = 1.4): ?float
    {
        return $median > 0 ? max($min, min($max, $now / $median)) : null;
    }
}
