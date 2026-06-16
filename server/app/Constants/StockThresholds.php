<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Thresholds operacionais do "Relatório A — candidatas a promoção".
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
}
