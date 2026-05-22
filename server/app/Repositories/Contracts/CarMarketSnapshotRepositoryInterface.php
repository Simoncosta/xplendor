<?php

namespace App\Repositories\Contracts;

use App\Models\Car;
use Illuminate\Support\Collection;

interface CarMarketSnapshotRepositoryInterface extends BaseRepositoryInterface
{
    public function upsertSnapshots(array $snapshots): void;
    public function getComparableSnapshots(Car $car): Collection;
    public function getComparableSnapshotsWide(Car $car, int $yearWindow): Collection;
    public function getComparableSnapshotsLoose(Car $car, int $yearWindow): Collection;
    public function getSegmentSnapshotStats(array $filters): array;
}
