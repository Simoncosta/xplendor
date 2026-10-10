<?php

namespace App\Repositories\Contracts;

interface CarRepositoryInterface extends BaseRepositoryInterface
{
    /** @param \Closure(\Illuminate\Database\Eloquent\Builder): void|null $sort a ordenação (ListSort), nunca nomes de coluna do pedido */
    public function getAllWithAnalytics(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], ?\Closure $sort = null): mixed;
    public function getSmartAdsContext(int $carId, int $companyId): array;
    public function getAiAnalysisData(int $carId, int $companyId): ?array;
}
