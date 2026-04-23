<?php

namespace App\Services;

class StockAdRankingService
{
    public function __construct(
        protected NextBestCarToPromoteService $nextBestCarToPromoteService
    ) {}

    public function rankActiveCarsForAds(int $companyId): array
    {
        return $this->nextBestCarToPromoteService->rankCarsForPromotion($companyId);
    }
}
