<?php

namespace App\Repositories;

use App\Models\CarExternalImage;
use App\Repositories\Contracts\CarExternalImageRepositoryInterface;

class CarExternalImageRepository extends BaseRepository implements CarExternalImageRepositoryInterface
{
    public function __construct(CarExternalImage $model)
    {
        parent::__construct($model);
    }

    public function replaceForCar(int $carId, int $companyId, string $source, array $images): void
    {
        $this->model
            ->where('car_id', $carId)
            ->where('company_id', $companyId)
            ->where('source', $source)
            ->delete();

        if (empty($images)) {
            return;
        }

        $payload = array_map(function (array $image) use ($carId, $companyId, $source) {
            return [
                'car_id' => $carId,
                'company_id' => $companyId,
                'source' => $source,
                'external_url' => $image['external_url'],
                'external_index' => $image['external_index'] ?? null,
                'is_primary' => (bool) ($image['is_primary'] ?? false),
                'sort_order' => $image['sort_order'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $images);

        $this->model->insert($payload);
    }
}
