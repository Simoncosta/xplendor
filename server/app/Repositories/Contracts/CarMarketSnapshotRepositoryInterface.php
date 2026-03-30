<?php

namespace App\Repositories\Contracts;

interface CarMarketSnapshotRepositoryInterface extends BaseRepositoryInterface
{
    public function upsertSnapshots(array $snapshots): void;
}
