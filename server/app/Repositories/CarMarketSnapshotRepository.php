<?php

namespace App\Repositories;

use App\Models\CarMarketSnapshot;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;

class CarMarketSnapshotRepository extends BaseRepository implements CarMarketSnapshotRepositoryInterface
{
    public function __construct(CarMarketSnapshot $model)
    {
        parent::__construct($model);
    }

    public function upsertSnapshots(array $snapshots): void
    {
        if (empty($snapshots)) {
            return;
        }

        $this->model->newQuery()->upsert(
            $snapshots,
            ['source', 'external_id'],
            [
                'brand',
                'model',
                'year',
                'title',
                'url',
                'category',
                'region',
                'price',
                'price_currency',
                'price_evaluation',
                'km',
                'fuel',
                'gearbox',
                'power_hp',
                'color',
                'doors',
                'scraped_at',
                'updated_at',
            ]
        );
    }
}
