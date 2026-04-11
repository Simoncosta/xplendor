<?php

namespace App\Services;

use App\Models\Car;

class VehicleIpsService
{
    public function calculate(Car $car, array $data): float
    {
        return match ($car->vehicle_type) {
            'motorhome' => $this->calculateMotorhomeIps($car, $data),
            default => $this->calculateCarIps($car, $data),
        };
    }

    private function calculateCarIps(Car $car, array $data): float
    {
        $views = (int) ($data['views_total'] ?? 0);
        $interactions = (int) ($data['interactions_total'] ?? 0);
        $views7d = (int) ($data['views_7d'] ?? 0);
        $days = (int) ($data['dias_em_stock'] ?? 0);
        $pricePosition = $this->normalizeMarketPosition($data['market_position'] ?? null);

        if ($views === 0) {
            return 0;
        }

        $interestRate = $interactions / $views;

        $score = 0;
        $score += min($interestRate * 100, 40);
        $score += min($views7d * 0.5, 10);
        $score += $days <= 30 ? 20 : max(0, 20 - ($days - 30));
        $score += match ($pricePosition) {
            'below_market' => 20,
            'at_market' => 10,
            'above_market' => 0,
            default => 5,
        };

        if ($days > 45) {
            $score -= min(($days - 45) * 0.5, 15);
        }

        $score = max(0, min(100, $score));

        return round($score);
    }

    private function calculateMotorhomeIps(Car $car, array $data): float
    {
        $views = (int) ($data['views_total'] ?? 0);
        $interactions = (int) ($data['interactions_total'] ?? 0);
        $days = (int) ($data['dias_em_stock'] ?? 0);
        $pricePosition = $this->normalizeMarketPosition($data['market_position'] ?? null);

        if ($views === 0) {
            return 0;
        }

        $interestRate = $interactions / $views;

        $score = 0;
        $score += min($interestRate * 100, 20);

        if ($days <= 90) {
            $score += 25;
        } else {
            $score += max(0, 25 - (($days - 90) * 0.7));
        }

        $score += match ($pricePosition) {
            'below_market' => 25,
            'at_market' => 15,
            'above_market' => 0,
            default => 10,
        };

        if ($interactions > 0) {
            $score += min($interactions * 5, 25);
        }

        if ($views > 100 && $interactions === 0) {
            $score -= 10;
        }

        $score = max(0, min(100, $score));

        return round($score);
    }

    private function normalizeMarketPosition(?string $pricePosition): ?string
    {
        return match ($pricePosition) {
            'aligned_market', 'at_market' => 'at_market',
            'below_market' => 'below_market',
            'above_market' => 'above_market',
            default => null,
        };
    }
}
