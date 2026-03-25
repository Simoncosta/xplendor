<?php

namespace App\Services;

use App\Repositories\Contracts\CarExternalImageRepositoryInterface;

class CarExternalImageService extends BaseService
{
    public function __construct(
        protected CarExternalImageRepositoryInterface $carExternalImageRepository
    ) {
        parent::__construct($carExternalImageRepository);
    }

    public function syncForCar(int $carId, int $companyId, string $source, array $images): void
    {
        $normalized = collect($images)
            ->map(function ($image, $index) {
                $url = trim((string) ($image['external_url'] ?? ''));

                if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                    return null;
                }

                return [
                    'external_url' => $url,
                    'external_index' => isset($image['external_index']) && $image['external_index'] !== ''
                        ? (int) $image['external_index']
                        : null,
                    'is_primary' => (bool) ($image['is_primary'] ?? false),
                    'sort_order' => isset($image['sort_order']) && $image['sort_order'] !== ''
                        ? (int) $image['sort_order']
                        : $index + 1,
                ];
            })
            ->filter()
            ->unique('external_url')
            ->sortBy([
                ['sort_order', 'asc'],
                ['external_index', 'asc'],
            ])
            ->values();

        if ($normalized->isNotEmpty() && !$normalized->contains(fn($image) => $image['is_primary'] === true)) {
            $normalized[0]['is_primary'] = true;
        }

        if ($normalized->isNotEmpty()) {
            $primaryUrl = $normalized->firstWhere('is_primary', true)['external_url'] ?? null;

            $normalized = $normalized->map(function ($image) use ($primaryUrl) {
                $image['is_primary'] = $primaryUrl !== null && $image['external_url'] === $primaryUrl;
                return $image;
            })->values();
        }

        $this->carExternalImageRepository->replaceForCar($carId, $companyId, $source, $normalized->all());
    }
}
