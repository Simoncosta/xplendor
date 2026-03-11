<?php

namespace App\Repositories\Contracts;

interface CarRepositoryInterface extends BaseRepositoryInterface
{
    public function getAllWithAnalytics(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed;
    public function updateFromCarmine(int $id, array $data): mixed;
}
