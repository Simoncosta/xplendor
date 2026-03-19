<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CarMarketingRoiRepositoryInterface extends BaseRepositoryInterface
{
    public function getMetricsByChannel(int $companyId, string $from, string $to): Collection;

    public function getCampaignPerformance(int $companyId, string $from, string $to): Collection;

    public function getCarPerformance(int $companyId, string $from, string $to): Collection;

    public function getMetaSpendSummary(int $companyId, string $from, string $to): object;

    public function getMetaSpendByCar(int $companyId, string $from, string $to): Collection;
}
