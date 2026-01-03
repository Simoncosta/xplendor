<?php

namespace App\Repositories;

use App\Models\BlingConnection;
use App\Models\BlingToken;
use App\Models\CompanyUserPivot;
use App\Repositories\Contracts\BaseRepositoryInterface;
use App\Services\BlingAPI\BlingAuthenticationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    /**
     * Construtor da classe
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Método responsável por buscar um registro pelo ID
     *
     * @param mixed $id
     * @param string|null $field
     * @param array $columns
     * @return array
     */
    public function findOrFail(mixed $id, ?string $field = null, array $columns = ['*'], array $relations = [], array $filters = []): mixed
    {
        try {
            $field = $field ?? 'id';
            $data = $this->model->where($field, $id)->select($columns);

            if (!empty($relations)) {
                $formattedRelations = [];

                foreach ($relations as $key => $value) {
                    if (is_int($key)) {
                        // Relações simples: ['user', 'category.company']
                        $formattedRelations[] = $value;
                    } elseif (is_callable($value)) {
                        // Relações com closure: ['user' => fn(...) => ...]
                        $formattedRelations[$key] = $value;
                    }
                }

                $data->with($formattedRelations);
            }

            // Filtros dinâmicos
            foreach ($filters as $field => $value) {

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                // Filtro LIKE
                if (is_array($value) && isset($value['like'])) {
                    $data->where($field, 'LIKE', '%' . $value['like'] . '%');
                    continue;
                }

                // Filtro whereIn
                if (is_array($value)) {
                    $data->whereIn($field, $value);
                    continue;
                }

                // Filtro exato
                $data->where($field, $value);
            }

            $data = $data->firstOrFail();

            return $data;
        } catch (ModelNotFoundException $e) {

            return ['message' => 'Não há dados com estes parâmetros.', 'error' => $e->getMessage()];
        } catch (Exception $e) {

            return ['message' => 'Ocorreu um erro inesperado.', 'error' => $e->getMessage()];
        }
    }

    /**
     * Método responsável por buscar um pedido pelo ID e relacionamentos
     *
     * @param int $id
     * @return mixed
     */
    public function findWithRelations($id, $relations = []): mixed
    {
        if (empty($relations)) {
            return $this->model->find($id);
        }

        return $this->model->with($relations)->find($id);
    }

    /**
     * Retorna dados com ou sem paginação, relações e colunas
     *
     * @param array $columns
     * @param array $relations
     * @param int|null $perPage
     * @param array $filters
     * @param array $orderBy
     * @return mixed
     */
    public function getAll(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed
    {
        $query = $this->model->select($columns);

        // relações dinâmicas
        if (!empty($relations)) {
            $query->with($relations);
        }

        // Filtros dinâmicos
        foreach ($filters as $field => $value) {

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // Filtro LIKE
            if (is_array($value) && isset($value['like'])) {
                $query->where($field, 'LIKE', '%' . $value['like'] . '%');
                continue;
            }

            // Filtro whereIn
            if (is_array($value)) {
                $query->whereIn($field, $value);
                continue;
            }

            // Filtro exato
            $query->where($field, $value);
        }

        // Ordenação dinâmica
        foreach ($orderBy as $field => $direction) {
            $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc'; // segurança contra inputs inválidos
            $query->orderBy($field, $direction);
        }

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * Método responsável por salvar um registro
     *
     * @param array $data
     * @return array
     */
    public function store(array $data): mixed
    {
        try {
            $store = $this->model->create($data);

            return $store;
        } catch (Exception $e) {

            return ['message' => 'Erro ao inserir dados.', 'error' => $e->getMessage()];
        }
    }

    /**
     * Método responsável responsável por atualizar um registro
     *
     * @param int $id
     * @param array $response
     * @return array
     */
    public function update(int $id, array $data): mixed
    {
        $response = $this->model->find($id);
        $response->update($data);
        return $response;
    }

    /**
     * Método responsável por excluir um registro
     *
     * @param int $id
     */
    public function destroy(int $id): mixed
    {
        $model = $this->model->find($id);
        return $model->delete();
    }
}
