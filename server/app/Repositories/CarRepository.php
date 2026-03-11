<?php

namespace App\Repositories;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;

class CarRepository extends BaseRepository implements CarRepositoryInterface
{
    public function __construct(Car $model)
    {
        parent::__construct($model);
    }

    public function getAllWithAnalytics(
        array $columns = ['*'],
        array $relations = [],
        ?int $perPage = null,
        array $filters = [],
        array $orderBy = []
    ): mixed {
        $query = $this->model->select($columns);

        if (!empty($relations)) {
            $query->with($relations);
        }

        $query->withCount([
            'views',
            'leads',
            'interactions',
        ]);

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value) && isset($value['like'])) {
                $query->where($field, 'LIKE', '%' . $value['like'] . '%');
                continue;
            }

            if (is_array($value) && isset($value['between']) && is_array($value['between'])) {
                $query->whereBetween($field, $value['between']);
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($field, $value);
                continue;
            }

            $query->where($field, $value);
        }

        if (!empty($orderBy)) {
            foreach ($orderBy as $field => $direction) {
                $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                if ($field === 'views') {
                    $query->orderBy('views_count', $direction);
                    continue;
                }

                if ($field === 'leads') {
                    $query->orderBy('leads_count', $direction);
                    continue;
                }

                if ($field === 'interactions') {
                    $query->orderBy('interactions_count', $direction);
                    continue;
                }

                if ($field === 'brand') {
                    $query
                        ->leftJoin('car_brands', 'cars.car_brand_id', '=', 'car_brands.id')
                        ->orderBy('car_brands.name', $direction)
                        ->select('cars.*');
                    continue;
                }

                $query->orderBy($field, $direction);
            }
        }

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    public function updateFromCarmine(int $id, array $data): mixed
    {
        $car = $this->model->findOrFail($id);

        $car->fill($data);

        if (isset($data['created_at'])) {
            $car->created_at = $data['created_at'];
        }

        if (isset($data['updated_at'])) {
            $car->updated_at = $data['updated_at'];
        }

        $car->save();

        return $car;
    }
}
