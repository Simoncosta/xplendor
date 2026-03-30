<?php

namespace App\Services;

use App\Models\Car;

class SmartAdsRecommendationService
{
    public function __construct(
        protected CarDecisionEngineService $carDecisionEngineService,
    ) {}

    public function generate(Car $car): ?array
    {
        $recommendation = $this->carDecisionEngineService->decide($car);

        return [
            ...$recommendation,
            'source' => 'smart_ads',
            'is_fallback' => false,
        ];
    }
}
