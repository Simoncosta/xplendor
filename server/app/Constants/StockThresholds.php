<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Limiares de IDADE DO STOCK — fonte ÚNICA para "parada há muito" em toda a
 * aplicação (relatório de promoção, dashboard, motor de problemas, IPS). Nenhum
 * outro sítio deve ter números de dias para este efeito.
 *
 * Decisão de produto (sessão 2026-06-16): "parada há muito" varia por
 * vehicle_type — autocaravanas/caravanas têm ciclos de venda 2-3× mais
 * lentos que carros, logo o threshold é diferente. Valores iniciais
 * acordados, ajustáveis depois de validação com o stand.
 *
 * Os valores são puramente APRESENTAÇÃO/FILTRO no relatório — a flag
 * "is_stale" emitida no Resource é só sugestão visual. Não bloqueiam
 * nada do business logic, não disparam jobs, não alteram IPS.
 */
final class StockThresholds
{
    /**
     * Dias em stock acima dos quais a viatura é considerada "parada há muito".
     *
     * @var array<string, int>
     */
    public const STOCK_AGE_THRESHOLD = [
        'car'        => 45,
        'motorcycle' => 45,
        'motorhome'  => 120,
        'caravan'    => 120,
    ];

    /**
     * Default quando o vehicle_type não está mapeado (defensivo — não
     * deveria acontecer, mas evita rebentar se aparecer um tipo novo).
     */
    public const STOCK_AGE_DEFAULT = 60;

    public static function ageThresholdFor(?string $vehicleType): int
    {
        return self::STOCK_AGE_THRESHOLD[$vehicleType] ?? self::STOCK_AGE_DEFAULT;
    }

    /** A viatura está "parada há muito" (≥ limiar do seu tipo)? */
    public static function isStale(?string $vehicleType, ?int $daysInStock): bool
    {
        return $daysInStock !== null && $daysInStock >= self::ageThresholdFor($vehicleType);
    }

    /**
     * Faixas da curva de pontuação por idade (IPS), DERIVADAS do limiar T do tipo:
     * [T/3, 2T/3, 4T/3]. Para carros (T=45) dá 15/30/60 — os valores históricos;
     * para autocaravanas (T=120) dá 40/80/160, em vez de as penalizar ao ritmo de
     * um carro. Assim deixa de haver números de dias espalhados pelo código.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function ageBandsFor(?string $vehicleType): array
    {
        $t = self::ageThresholdFor($vehicleType);

        return [(int) round($t / 3), (int) round($t * 2 / 3), (int) round($t * 4 / 3)];
    }

    /**
     * Um múltiplo do limiar T do tipo, em dias (ex.: 2.0 → 2T). Para as curvas de
     * pontuação por idade: em vez de números fixos (15/30/60/90…) que só servem
     * carros, usam-se frações de T — carros ficam com os valores de sempre e as
     * autocaravanas passam a ser medidas ao seu próprio ritmo.
     */
    public static function scaled(?string $vehicleType, float $multiplier): int
    {
        return (int) round(self::ageThresholdFor($vehicleType) * $multiplier);
    }

    /**
     * O limiar por tipo em SQL (CASE sobre vehicle_type), para filtros/agregados em
     * BD sem duplicar os números. $table = tabela ou alias de `cars`.
     */
    public static function sqlThresholdExpr(string $table = 'cars'): string
    {
        $cases = '';
        foreach (self::STOCK_AGE_THRESHOLD as $type => $days) {
            $cases .= " WHEN '{$type}' THEN {$days}";
        }

        return "(CASE {$table}.vehicle_type{$cases} ELSE " . self::STOCK_AGE_DEFAULT . ' END)';
    }
}
