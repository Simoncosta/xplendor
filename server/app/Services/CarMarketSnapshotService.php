<?php

namespace App\Services;

use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use Carbon\Carbon;

class CarMarketSnapshotService extends BaseService
{
    public function __construct(
        protected CarMarketSnapshotRepositoryInterface $carMarketSnapshotRepository
    ) {
        parent::__construct($carMarketSnapshotRepository);
    }

    public function persistSnapshots(array $snapshots): void
    {
        if (empty($snapshots)) {
            return;
        }

        $now = now();

        $normalized = collect($snapshots)
            ->map(fn(array $snapshot) => $this->normalizeSnapshot($snapshot, $now))
            ->filter()
            ->values()
            ->all();

        foreach (array_chunk($normalized, 500) as $chunk) {
            $this->carMarketSnapshotRepository->upsertSnapshots($chunk);
        }
    }

    private function normalizeSnapshot(array $snapshot, Carbon $now): ?array
    {
        $externalId = trim((string) ($snapshot['external_id'] ?? ''));
        $source = trim((string) ($snapshot['source'] ?? ''));
        $title = trim((string) ($snapshot['title'] ?? ''));
        $url = trim((string) ($snapshot['url'] ?? ''));

        if ($externalId === '' || $source === '' || $title === '' || $url === '') {
            return null;
        }

        $scrapedAt = !empty($snapshot['scraped_at'])
            ? Carbon::parse($snapshot['scraped_at'])
            : $now;

        return [
            'external_id' => $externalId,
            'source' => $source,
            'brand' => $this->nullableString($snapshot['brand'] ?? null),
            'model' => $this->nullableString($snapshot['model'] ?? null),
            'year' => $this->nullableInt($snapshot['year'] ?? null),
            'title' => $title,
            'url' => $url,
            'category' => $this->nullableString($snapshot['category'] ?? null),
            'region' => $this->nullableString($snapshot['region'] ?? null),
            'price' => $this->nullableFloat($snapshot['price'] ?? null),
            'price_currency' => $this->nullableString($snapshot['price_currency'] ?? null),
            'price_evaluation' => $this->nullableString($snapshot['price_evaluation'] ?? null),
            'km' => $this->nullableInt($snapshot['km'] ?? null),
            'fuel' => $this->nullableString($snapshot['fuel'] ?? null),
            'gearbox' => $this->nullableString($snapshot['gearbox'] ?? null),
            'power_hp' => $this->nullableInt($snapshot['power_hp'] ?? null),
            'color' => $this->nullableString($snapshot['color'] ?? null),
            'doors' => $this->nullableInt($snapshot['doors'] ?? null),
            'scraped_at' => $scrapedAt,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $string = is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : null);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
