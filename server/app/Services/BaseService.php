<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use App\Repositories\Contracts\BaseRepositoryInterface;

class BaseService
{
    protected BaseRepositoryInterface $repository;

    public function __construct(BaseRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Método responsável por buscar todos os registros da tabela.
     * @param array $columns Colunas a serem retornadas.
     * @param array $relations Relações a serem incluídas.
     * @param int $perPage Quantidade de registros por página.
     * @param array $filters Filtros dinâmicos.
     * @param array $orderBy Ordenação dinâmica.
     * @return mixed Retorna um array com os registros encontrados ou um objeto com a mensagem de erro.
     */
    public function getAll(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed
    {
        return $this->repository->getAll($columns, $relations, $perPage, $filters, $orderBy);
    }

    /**
     * Método responsável por buscar um registro específico.
     * @param int $id Identificador do registro a ser buscado.
     * @param string $field Nome do campo a ser buscado.
     * @param array $columns Colunas a serem retornadas.
     * @param array $relations Relações a serem incluídas.
     * @return mixed Retorna um array com os registros encontrados ou um objeto com a mensagem de erro.
     */
    public function findOrFail(int $id, ?string $field = null, array $columns = ['*'], array $relations = []): mixed
    {
        return $this->repository->findOrFail($id, $field, $columns, $relations);
    }

    /**
     * Método responsável por criar um novo registro.
     * @param array $data Dados do registro a ser criado.
     * @return array Retorna um array com os dados do registro criado ou um objeto com a mensagem de erro.
     */
    public function store(array $data): mixed
    {
        return $this->repository->store($data);
    }

    /**
     * Método responsável por atualizar um registro.
     * @param int $id Identificador do registro a ser atualizado.
     * @param array $data Dados do registro a ser atualizado.
     * @return array Retorna um array com os dados do registro atualizado ou um objeto com a mensagem de erro.
     */
    public function update(int $id, array $data): mixed
    {
        $model = $this->repository->findOrFail($id, null);
        if (! $model) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Register not found.');
        }

        return $this->repository->update($model['id'], $data);
    }

    /**
     * Método responsável por excluir um registro.
     * @param int $id Identificador do registro a ser excluído.
     * @return void
     */
    public function destroy(int $id): mixed
    {
        $model = $this->repository->findOrFail($id, null);
        if (! $model) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Register not found.');
        }

        return $this->repository->destroy($model['id']);
    }

    /**
     * Método responsável por buscar um registro com suas relações.
     * @param int $id Identificador do registro a ser buscado.
     * @param array $relations Relações a serem incluídas.
     * @return mixed Retorna um array com os registros encontrados ou um objeto com a mensagem de erro.
     */
    public function findWithRelations(int $id, array $relations = []): ?Model
    {
        return $this->repository->findWithRelations($id, $relations);
    }
}
