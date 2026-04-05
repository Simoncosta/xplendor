<?php

namespace App\Services\Meta;

use App\Models\AdInterest;
use App\Models\CompanyIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaTargetingService
{
    private const GRAPH_URL = 'https://graph.facebook.com/v25.0';
    private const CACHE_TTL_DAYS = 7;

    protected ?int $companyId = null;

    public function forCompany(int $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function searchInterests(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $integration = $this->resolveMetaIntegration();

        if (!$integration || empty($integration->account_id) || empty($integration->access_token)) {
            Log::warning('MetaTargetingService: active Meta integration not available for interest search', [
                'company_id' => $this->companyId,
                'query' => $query,
            ]);

            return [];
        }

        $response = Http::timeout(20)
            ->retry(2, 300)
            ->get(self::GRAPH_URL . "/act_{$integration->account_id}/targetingsearch", [
                'access_token' => $integration->access_token,
                'q' => $query,
                'type' => 'adinterest',
                'limit' => 10,
            ]);

        if ($response->failed()) {
            Log::error('MetaTargetingService: targetingsearch failed', [
                'company_id' => $this->companyId,
                'account_id' => $integration->account_id,
                'query' => $query,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $results = collect($response->json('data', []))
            ->map(fn(array $item) => $this->mapMetaInterest($item))
            ->filter()
            ->values();

        $results->each(fn(array $interest) => $this->upsertInterest($interest));

        return $results->all();
    }

    public function findOrCreateByName(string $name): ?AdInterest
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $local = $this->findLocalInterest($name);

        if ($local && $this->isFresh($local)) {
            return $local;
        }

        $results = $this->searchInterests($name);
        $bestMatch = $this->selectBestMatch($name, $results);

        if ($bestMatch) {
            return $this->upsertInterest($bestMatch);
        }

        return $local;
    }

    public function bulkResolve(array $names): array
    {
        return collect($names)
            ->filter(fn($name) => is_string($name) && trim($name) !== '')
            ->map(fn(string $name) => trim($name))
            ->unique()
            ->map(fn(string $name) => $this->findOrCreateByName($name))
            ->filter(fn($interest) => $interest instanceof AdInterest && !empty($interest->meta_id))
            ->map(fn(AdInterest $interest) => [
                'id' => $interest->id,
                'meta_id' => $interest->meta_id,
                'name' => $interest->name,
                'audience_size' => $interest->audience_size,
                'topic' => $interest->topic,
                'path' => $interest->path,
            ])
            ->values()
            ->all();
    }

    protected function resolveMetaIntegration(): ?CompanyIntegration
    {
        if (!$this->companyId) {
            return null;
        }

        return CompanyIntegration::query()
            ->active()
            ->platform('meta')
            ->where('company_id', $this->companyId)
            ->latest('updated_at')
            ->first();
    }

    protected function findLocalInterest(string $name): ?AdInterest
    {
        $normalized = mb_strtolower(trim($name));

        return AdInterest::query()
            ->where('is_active', true)
            ->where(function ($query) use ($name, $normalized) {
                $query->whereRaw('LOWER(name) = ?', [$normalized])
                    ->orWhere('name', 'LIKE', $name . '%')
                    ->orWhere('name', 'LIKE', '%' . $name . '%');
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [$normalized])
            ->orderByDesc('last_synced_at')
            ->first();
    }

    protected function isFresh(AdInterest $interest): bool
    {
        return $interest->last_synced_at !== null
            && $interest->last_synced_at->greaterThanOrEqualTo(now()->subDays(self::CACHE_TTL_DAYS));
    }

    protected function selectBestMatch(string $name, array $results): ?array
    {
        $normalized = mb_strtolower(trim($name));
        $collection = collect($results);

        return $collection->first(
            fn(array $item) => mb_strtolower((string) ($item['name'] ?? '')) === $normalized
        ) ?? $collection->first(
            fn(array $item) => str_starts_with(
                mb_strtolower((string) ($item['name'] ?? '')),
                $normalized
            )
        ) ?? $collection->first();
    }

    protected function mapMetaInterest(array $item): ?array
    {
        $metaId = trim((string) ($item['id'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));

        if ($metaId === '' || $name === '') {
            return null;
        }

        $path = $item['path'] ?? null;

        return [
            'meta_id' => $metaId,
            'name' => $name,
            'audience_size' => isset($item['audience_size']) ? (int) $item['audience_size'] : null,
            'topic' => !empty($item['topic']) ? (string) $item['topic'] : null,
            'path' => is_array($path) ? array_values($path) : (is_string($path) && $path !== '' ? [$path] : null),
        ];
    }

    protected function upsertInterest(array $payload): AdInterest
    {
        /** @var AdInterest $interest */
        $interest = AdInterest::query()->updateOrCreate(
            ['meta_id' => $payload['meta_id']],
            [
                'name' => $payload['name'],
                'audience_size' => $payload['audience_size'] ?? null,
                'topic' => $payload['topic'] ?? null,
                'path' => $payload['path'] ?? null,
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        return $interest;
    }
}
