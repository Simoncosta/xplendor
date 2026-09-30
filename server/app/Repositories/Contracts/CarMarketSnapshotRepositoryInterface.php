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
    public function getComparableSnapshotsByCategory(Car $car, string $bodyType, int $yearWindow): Collection;
    public function getComparableSnapshotsByBrandPrice(Car $car, int $yearWindow, float $priceMin, float $priceMax): Collection;

    /**
     * FASE 1 (motor de similaridade de autocaravanas) — pool de elegibilidade
     * por tipologia+ano com funil de contadores.
     *
     * @return array{pool: Collection, funnel: array<string, int>}
     */
    public function getMotorhomeSimilarityPool(string $layout, int $targetYear): array;

    public function getSegmentSnapshotStats(array $filters): array;
}
