<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;

class CarMarketIntelligenceService
{
    public function __construct(
        protected CarMarketSnapshotRepositoryInterface $repository
    ) {}

    public function analyze(Car $car): array
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        $snapshots = $this->repository->getComparableSnapshots($car);
        $competitorsCount = $snapshots->count();
        $currentPrice = $car->price_gross !== null ? round((float) $car->price_gross, 2) : null;

        if ($competitorsCount < 3 || $currentPrice === null || $currentPrice <= 0) {
            return $this->buildInsufficientDataResponse($competitorsCount, $currentPrice);
        }

        $prices = $snapshots
            ->pluck('price')
            ->filter(fn($price) => is_numeric($price) && (float) $price > 0)
            ->map(fn($price) => (float) $price)
            ->sort()
            ->values();

        if ($prices->count() < 3) {
            return $this->buildInsufficientDataResponse($competitorsCount, $currentPrice);
        }

        $median = $this->quantile($prices->all(), 0.50);
        $p25 = $this->quantile($prices->all(), 0.25);
        $p75 = $this->quantile($prices->all(), 0.75);

        $vsMedianPct = $median > 0
            ? round((($currentPrice - $median) / $median) * 100, 2)
            : null;

        $marketPosition = $this->resolveMarketPosition($vsMedianPct);
        $pricingSignal = match ($marketPosition) {
            'below_market' => 'good',
            'above_market' => 'warning',
            default => 'neutral',
        };

        return [
            'competitors_count' => $competitorsCount,
            'market_median_price' => $this->roundMoney($median),
            'market_p25_price' => $this->roundMoney($p25),
            'market_p75_price' => $this->roundMoney($p75),
            'car_price_vs_median_pct' => $vsMedianPct,
            'market_position' => $marketPosition,
            'pricing_signal' => $pricingSignal,
            'recommended_price' => $this->recommendPrice($marketPosition, $currentPrice, $median),
        ];
    }

    private function buildInsufficientDataResponse(int $competitorsCount, ?float $currentPrice): array
    {
        return [
            'competitors_count' => $competitorsCount,
            'market_median_price' => null,
            'market_p25_price' => null,
            'market_p75_price' => null,
            'car_price_vs_median_pct' => null,
            'market_position' => 'insufficient_data',
            'pricing_signal' => 'neutral',
            'recommended_price' => $currentPrice,
        ];
    }

    private function resolveMarketPosition(?float $vsMedianPct): string
    {
        if ($vsMedianPct === null) {
            return 'insufficient_data';
        }

        if ($vsMedianPct < -5) {
            return 'below_market';
        }

        if ($vsMedianPct > 5) {
            return 'above_market';
        }

        return 'aligned_market';
    }

    private function recommendPrice(string $marketPosition, ?float $currentPrice, float $median): ?float
    {
        if ($currentPrice === null) {
            return null;
        }

        if ($marketPosition === 'above_market' && $median > 0) {
            return $this->roundMoney($median * 0.99);
        }

        return $this->roundMoney($currentPrice);
    }

    private function quantile(array $values, float $quantile): float
    {
        $count = count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $position = ($count - 1) * $quantile;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return (float) $values[$lowerIndex];
        }

        $weight = $position - $lowerIndex;

        return ((1 - $weight) * (float) $values[$lowerIndex]) + ($weight * (float) $values[$upperIndex]);
    }

    private function roundMoney(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
