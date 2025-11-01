<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use App\Repositories\Contracts\BaseRepositoryInterface;

abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->update($data);
        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->model->get();
    }

    public function findWithRelations(int $id, array $relations = []): ?Model
    {
        return $this->model->with($relations)->find($id);
    }

    public function allWithRelations(array $relations = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->model->with($relations)->get();
    }
}
