<?php

namespace App\Services;

use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CarMarketSnapshotService extends BaseService
{
    /**
     * Ordem determinística de fontes para dedup (MS2.d).
     * O primeiro hash visto fica; logo o Standvirtual ganha sobre o CustoJusto
     * em colisões cross-fonte. Standvirtual tem campos mais ricos (fuel/gearbox/
     * power_hp/category numérica) e é a fonte canónica do mercado, daí prioridade.
     */
    private const SOURCE_PRIORITY = [
        'standvirtual' => 0,
        'custojusto'   => 1,
    ];

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

        // 1. Contagem por fonte ANTES do merge (visibilidade no log — útil
        //    para diagnóstico de bloqueios/dedup, e para MS2.e construir
        //    sources_breakdown a partir desta info).
        $countsBeforeDedup = [];
        foreach ($snapshots as $s) {
            $src = (string) ($s['source'] ?? 'unknown');
            $countsBeforeDedup[$src] = ($countsBeforeDedup[$src] ?? 0) + 1;
        }

        // 2. Ordenação determinística cross-fonte. PHP usort é stable em 8.0+,
        //    logo a ordem intra-fonte mantém-se. Vence quem aparece primeiro.
        usort($snapshots, function (array $a, array $b): int {
            $pa = self::SOURCE_PRIORITY[(string) ($a['source'] ?? '')] ?? PHP_INT_MAX;
            $pb = self::SOURCE_PRIORITY[(string) ($b['source'] ?? '')] ?? PHP_INT_MAX;
            return $pa <=> $pb;
        });

        // 3. Dedup por hash com tracking de skips por fonte.
        $seenHashes        = [];
        $deduped           = [];
        $duplicatesBySource = [];
        foreach ($snapshots as $snapshot) {
            $hash = $this->computeDedupHash(
                (string) ($snapshot['title'] ?? ''),
                $snapshot['year'] ?? null,
                $snapshot['price'] ?? null,
            );

            if (isset($seenHashes[$hash])) {
                $src = (string) ($snapshot['source'] ?? 'unknown');
                $duplicatesBySource[$src] = ($duplicatesBySource[$src] ?? 0) + 1;
                continue;
            }
            $seenHashes[$hash] = true;
            // O hash propaga-se ao upsert via normalizeSnapshot.
            $snapshot['dedup_hash'] = $hash;
            $deduped[] = $snapshot;
        }

        Log::info('[market-snapshots] dedup completed', [
            'before_dedup'        => $countsBeforeDedup,
            'after_dedup'         => count($deduped),
            'duplicates_skipped'  => $duplicatesBySource,
        ]);

        // 4. Normaliza + upsert em chunks (preserva tudo o que existia antes).
        $normalized = collect($deduped)
            ->map(fn(array $snapshot) => $this->normalizeSnapshot($snapshot, $now))
            ->filter()
            ->values()
            ->all();

        foreach (array_chunk($normalized, 500) as $chunk) {
            $this->carMarketSnapshotRepository->upsertSnapshots($chunk);
        }
    }

    /**
     * Dedup hash determinístico para detectar o mesmo anúncio cross-fonte
     * (Standvirtual + CustoJusto cross-postam regularmente entre profissionais).
     *
     * sha1( normalize(title) | year | price_bucket_100 )
     *   - title:  lowercase, sem acentos, [^a-z0-9]+ → "_", trim de "_"
     *   - year:   exacto (null → token "NA" para não colidir com year=0 anómalo)
     *   - price:  bucket de €100 (67900/67950 colidem; 67999/68001 não)
     *
     * Ordem cross-fonte é tratada PELO CALLER (usort por SOURCE_PRIORITY antes
     * do loop de dedup). O hash em si é simétrico — o que decide quem fica é
     * a ordem de iteração.
     */
    public function computeDedupHash(string $title, $year, $price): string
    {
        $normalizedTitle = $this->normalizeTitleForDedup($title);
        $yearToken       = $year !== null && $year !== '' ? (string) (int) $year : 'NA';
        $bucket          = $this->priceBucket100($price);

        return sha1($normalizedTitle . '|' . $yearToken . '|' . $bucket);
    }

    private function normalizeTitleForDedup(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $ascii = $ascii !== false ? $ascii : $title;
        $lower = strtolower($ascii);
        $cleaned = preg_replace('/[^a-z0-9]+/', '_', $lower) ?? '';
        return trim($cleaned, '_');
    }

    private function priceBucket100($price): string
    {
        if (!is_numeric($price) || (float) $price <= 0.0) {
            return '0';
        }
        return (string) (int) (round(((float) $price) / 100.0) * 100);
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
            'vehicle_type' => $this->nullableString($snapshot['vehicle_type'] ?? null),
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
            // MS2.a — placeholder. dedup_hash será calculado na ingestão (MS2.d)
            // a partir do título normalizado + ano + bucket de preço. Por agora
            // mantém-se null para snapshots existentes — o índice tolera nulls.
            'dedup_hash' => $this->nullableString($snapshot['dedup_hash'] ?? null),
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
