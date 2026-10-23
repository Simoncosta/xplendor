<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Car;
use App\Models\CarMarketAggregate;
use App\Services\MarketSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A leitura noturna do preço de mercado das viaturas à venda (Standvirtual), no máximo 20 por noite. Os
 * vendidos entram desde 25/05 (o site público passou a mostrá-los), mas só nos SOLD_DAYS dias depois da
 * data da venda (decisão de 24/10); depois saem. Um vendido sem data de venda não entra.
 */
class RefreshStaleMarketAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SOLD_DAYS = 30;

    public int $tries   = 1;
    public int $timeout = 600;

    public function handle(MarketSnapshotService $service): void
    {
        $cars = $this->resolveStaleCars();

        Log::info('[MarketRefresh] Refresh nocturno iniciado', ['total' => $cars->count()]);

        foreach ($cars as $index => $car) {
            $service->snapshotForCar($car);

            // 15-second gap between dispatches to avoid hammering Standvirtual
            if ($index < $cars->count() - 1) {
                sleep(15);
            }
        }

        Log::info('[MarketRefresh] Jobs despachados', ['total' => $cars->count()]);
    }

    public function resolveStaleCars(): Collection
    {
        return Car::query()
            ->where(function ($query): void {
                $query->whereIn('status', ['active', 'available_soon'])
                    ->orWhere(fn ($sold) => $sold->where('status', 'sold')->whereNotNull('sold_at')->where('sold_at', '>=', now()->subDays(self::SOLD_DAYS)));
            })
            ->whereIn('vehicle_type', ['car', 'motorhome'])
            ->whereNotNull('car_brand_id')
            ->whereNotNull('car_model_id')
            ->whereNotNull('registration_year')
            ->where(function ($query): void {
                $query->whereDoesntHave('marketAggregates')
                    ->orWhereHas('latestMarketAggregate', function ($q): void {
                        $q->where('updated_at', '<', now()->subDays(7));
                    });
            })
            ->orderByRaw('NOT EXISTS (SELECT 1 FROM car_market_aggregates WHERE car_id = cars.id) DESC')
            ->limit(20)
            ->get();
    }
}
