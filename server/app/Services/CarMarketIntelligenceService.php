<?php

namespace App\Services;

use App\Models\Car;
use App\Support\PricePosition;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;

class CarMarketIntelligenceService
{
    public function __construct(
        protected CarMarketSnapshotRepositoryInterface $repository
    ) {}

    /**
     * Posição da viatura face ao mercado. Passou a LER da fonte única
     * (App\Support\PricePosition: mediana do último car_market_aggregates, incl. o
     * motor das autocaravanas, vs o preço ATUAL efetivo), com o MESMO formato de
     * saída de sempre — IPS, motor de problemas, contexto de decisão e análise IA
     * continuam a ler as mesmas chaves.
     */
    public function analyze(Car $car): array
    {
        $pos = PricePosition::for($car);
        $currentPrice = $pos['effective_price'];

        if (! $pos['available'] || $currentPrice === null || $pos['median'] === null) {
            return $this->buildInsufficientDataResponse($pos['comparables_count'], $currentPrice);
        }

        $marketPosition = $pos['position']; // escala de 3 (já com a regra de confiança mínima)
        $pricingSignal = match ($marketPosition) {
            'below_market' => 'good',
            'above_market' => 'warning',
            default => 'neutral',
        };

        return [
            'competitors_count' => $pos['comparables_count'],
            'market_median_price' => $pos['median'],
            'market_p25_price' => $pos['p25'],
            'market_p75_price' => $pos['p75'],
            'car_price_vs_median_pct' => $pos['difference_pct'],
            'market_position' => $marketPosition,
            'pricing_signal' => $pricingSignal,
            'recommended_price' => $this->recommendPrice($marketPosition, $currentPrice, $pos['median']),
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

    private function roundMoney(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
