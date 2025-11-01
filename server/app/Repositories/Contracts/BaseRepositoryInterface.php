<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
interface BaseRepositoryInterface
{
    public function find(int $id): ?Model;
    public function create(array $data): Model;
    public function update(Model $model, array $data): Model;
    public function delete(Model $model): void;
    public function all(): \Illuminate\Database\Eloquent\Collection;
    public function findWithRelations(int $id, array $relations = []): ?Model;
    public function allWithRelations(array $relations = []): \Illuminate\Database\Eloquent\Collection;
}
