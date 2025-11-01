<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Contracts\BaseRepositoryInterface;

class BaseService
{
    protected BaseRepositoryInterface $repository;

    public function __construct(BaseRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): ?Model
    {
        return $this->repository->find($id);
    }

    public function store(array $data): Model
    {
        return $this->repository->create($data);
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->repository->find($id);
        if (! $model) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Register not found.');
        }

        return $this->repository->update($model, $data);
    }

    public function delete(int $id): void
    {
        $model = $this->repository->find($id);
        if (! $model) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Register not found.');
        }

        $this->repository->delete($model);
    }

    public function findWithRelations(int $id, array $relations = []): ?Model
    {
        return $this->repository->findWithRelations($id, $relations);
    }
}
