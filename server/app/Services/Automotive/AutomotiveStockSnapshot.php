<?php

declare(strict_types=1);

namespace App\Services\Automotive;

use App\Models\Car;
use App\Services\CarMarketIntelligenceService;
use App\Support\PricePosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * XPLENDOR — Fotografia do stock ativo para as regras do ramo Automóvel.
 *
 * O mesmo universo do antigo CarIssueEngine (viaturas 'active', sem retomas), com
 * UMA diferença: vistas, leads e interações contam só numa JANELA recente
 * (window_days, 30 por omissão) em vez de desde sempre. As médias do stock que as
 * regras usam como referência são calculadas sobre a mesma janela.
 *
 * Fontes únicas: dias em stock (StockAge via Car::daysInStock), preço efetivo e
 * posição de preço (PricePosition via CarMarketIntelligenceService), limiares por
 * tipo (StockThresholds, nas regras). Calculada uma vez por empresa+janela+dia e
 * partilhada pelas quatro regras.
 */
class AutomotiveStockSnapshot
{
    /** @var array<string, array{cars: Collection, context: array}> */
    private array $memo = [];

    public function __construct(private readonly CarMarketIntelligenceService $market) {}

    /**
     * @return array{cars: Collection, context: array{avg_views: float, avg_interactions: float, avg_leads: float, window_days: int}}
     *   Cada viatura traz days_in_stock, views_count, leads_count, interactions_count
     *   (na janela), images_count, external_images_count e market (analyze()).
     */
    public function for(int $companyId, int $windowDays, CarbonImmutable $now): array
    {
        $key = "{$companyId}|{$windowDays}|{$now->toDateString()}";
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $since = $now->subDays($windowDays)->startOfDay();
        $inWindow = fn ($q) => $q->where('created_at', '>=', $since);

        $cars = Car::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->with(['brand:id,name', 'model:id,name', 'latestPricedMarketAggregate'])
            ->withCount([
                'views' => $inWindow,
                'leads' => $inWindow,
                'interactions' => $inWindow,
                'images',
                'externalImages',
            ])
            ->orderBy('id')
            ->get()
            ->map(function (Car $car) use ($now) {
                $car->days_in_stock = (int) ($car->daysInStock($now) ?? 0);
                $car->market = $this->market->analyze($car);

                return $car;
            });

        return $this->memo[$key] = [
            'cars' => $cars,
            'context' => [
                'avg_views' => (float) $cars->avg('views_count'),
                'avg_interactions' => (float) $cars->avg('interactions_count'),
                'avg_leads' => (float) $cars->avg('leads_count'),
                'window_days' => $windowDays,
            ],
        ];
    }

    public static function carTitle(Car $car): string
    {
        return trim(preg_replace('/\s+/', ' ', sprintf('%s %s %s', $car->brand?->name ?? '', $car->model?->name ?? '', $car->version ?? '')));
    }

    public static function effectivePrice(Car $car): ?float
    {
        return PricePosition::effectivePriceForCar($car);
    }
}
