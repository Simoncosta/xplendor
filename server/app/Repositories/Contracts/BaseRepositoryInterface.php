<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
interface BaseRepositoryInterface
{
    public function getAll(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed;
    public function findOrFail(mixed $id, ?string $field, array $columns = ['*'], array $relations = [], array $filters = []): mixed;
    public function findWithRelations(int $id, array $relations = []): mixed;
    public function store(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function destroy(int $id): mixed;
}
