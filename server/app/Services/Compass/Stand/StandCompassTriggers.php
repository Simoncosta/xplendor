<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Jobs\RecomputeStandCompassJob;
use App\Models\Car;
use App\Models\CarMarketAggregate;

/**
 * Bússola do stand: recalcula os sinais quando o stock, os preços ou o mercado mudam (os
 * anúncios: no fim de MetaAdCarAllocator::rebuild). Só nas empresas com o interruptor ligado;
 * um só recálculo em fila por empresa (RecomputeStandCompassJob é único).
 */
final class StandCompassTriggers
{
    public const CAR_FIELDS = ['status', 'price_gross', 'promo_price_gross', 'sold_at', 'is_resume', 'vehicle_type', 'registration_year', 'mileage_km', 'car_brand_id', 'car_model_id'];

    public static function register(): void
    {
        Car::saved(function (Car $car) {
            if ($car->wasRecentlyCreated || $car->wasChanged(self::CAR_FIELDS)) {
                self::dispatch((int) $car->company_id);
            }
        });
        Car::deleted(fn (Car $car) => self::dispatch((int) $car->company_id));
        CarMarketAggregate::saved(function (CarMarketAggregate $aggregate) {
            if ($aggregate->median_price !== null && ($companyId = Car::whereKey($aggregate->car_id)->value('company_id'))) {
                self::dispatch((int) $companyId);
            }
        });
    }

    public static function dispatch(int $companyId): void
    {
        if ($companyId > 0 && StandSignalService::isEnabled($companyId)) {
            RecomputeStandCompassJob::dispatch($companyId)->afterCommit();
        }
    }
}
