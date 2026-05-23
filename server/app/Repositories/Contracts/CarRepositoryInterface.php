<?php

namespace App\Repositories\Contracts;

interface CarRepositoryInterface extends BaseRepositoryInterface
{
    public function getAllWithAnalytics(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed;
    public function getSmartAdsContext(int $carId, int $companyId): array;
    public function getAiAnalysisData(int $carId, int $companyId): ?array;
}
