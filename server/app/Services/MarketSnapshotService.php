<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarMarketAggregate;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use Illuminate\Support\Collection;

class MarketSnapshotService
{
    private const SCRAPER_SUPPORTED_TYPES = ['car', 'motorhome'];

    /** Faixa de preço (±) para o fallback de autocaravanas por marca+preço.
     *  Calibração afinável — ±35% porque a gama de preços de autocaravanas da
     *  mesma marca é muito dispersa (ex: McLouis vai de ~€26k a ~€124k). */
    private const MOTORHOME_PRICE_BAND = 0.35;

    /** Limiar mínimo de comparáveis para o degrau categoria curto-circuitar
     *  a cascata. Abaixo disto, cai para marca+preço. Afinável. */
    private const MIN_CATEGORY_COMPARABLES = 2;

    /** Mapa categoria interna (car_categories.slug) → body_type do Standvirtual.
     *  Validado empiricamente no browser em 2026-05-30. Atenção: 'perfilada'
     *  interno é singular, no Standvirtual é 'perfiladas'; 'campervan' interno
     *  é 'furgao' no Standvirtual. Se o Standvirtual mudar a taxonomia, testar
     *  primeiro um URL real no browser antes de alterar. */
    public const MOTORHOME_CATEGORY_BODY_TYPE_MAP = [
        'capucino'  => 'capucine',
        'integral'  => 'integral',
        'perfilada' => 'perfiladas',
        'campervan' => 'furgao',
    ];

    /** Resolve o slug do body_type do Standvirtual a partir do slug da
     *  categoria interna do car_categories. Devolve null se não houver mapa. */
    public static function bodyTypeFor(?string $categorySlug): ?string
    {
        if ($categorySlug === null || $categorySlug === '') {
            return null;
        }
        return self::MOTORHOME_CATEGORY_BODY_TYPE_MAP[$categorySlug] ?? null;
    }

    public function __construct(
        private readonly CarMarketSnapshotRepositoryInterface $snapshotRepo,
    ) {}

    /**
     * Creates an aggregate record and dispatches a scrape job.
     * Called by CarObserver::created().
     * Returns null for unsupported vehicle types (e.g. caravan).
     */
    public function snapshotForCar(Car $car): ?CarMarketAggregate
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        if (!\in_array($car->vehicle_type, self::SCRAPER_SUPPORTED_TYPES, true)) {
            return null;
        }

        // Promo price is valid only when set, positive, and lower than gross price
        $promoPrice = ($car->promo_price_gross > 0 && $car->promo_price_gross < $car->price_gross)
            ? $car->promo_price_gross
            : null;

        $searchUrl = $this->buildSearchUrl($car);

        // Insufficient data — record failure without invoking the scraper
        if (!$car->brand?->name || !$car->model?->name || !$car->registration_year) {
            return CarMarketAggregate::create([
                'car_id'            => $car->id,
                'vehicle_type'      => $car->vehicle_type ?? 'car',
                'car_price_gross'   => $car->price_gross,
                'promo_price_gross' => $promoPrice,
                'search_url'        => $searchUrl,
                'status'            => 'failed',
                'confidence'        => 'none',
                'comparables_count' => 0,
                'fallback_used'     => false,
            ]);
        }

        $aggregate = CarMarketAggregate::create([
            'car_id'            => $car->id,
            'vehicle_type'      => $car->vehicle_type ?? 'car',
            'car_price_gross'   => $car->price_gross,
            'promo_price_gross' => $promoPrice,
            'search_url'        => $searchUrl,
            'status'            => 'pending',
            'confidence'        => 'none',
            'comparables_count' => 0,
            'fallback_used'     => false,
        ]);

        ScrapeMarketSnapshotJob::dispatch($car->id, $aggregate->id);

        return $aggregate;
    }

    /**
     * Builds the scraper filter array for a car (testable without docker exec).
     */
    public function buildFilters(Car $car): array
    {
        $filters = [
            'vehicle_type' => $car->vehicle_type ?? 'car',
            'brand'        => $car->brand?->name,
            'model'        => $car->model?->name,
            'max_results'  => 10,
        ];

        if ($car->registration_year) {
            $filters['year_from'] = $car->registration_year - 1;
            $filters['year_to']   = $car->registration_year + 1;
        }

        if ($car->fuel_type) {
            $filters['fuel'] = $car->fuel_type;
        }

        return $filters;
    }

    /**
     * Implements the three-attempt fallback chain. Called by the job after scraping.
     *
     * @return array{snapshots: Collection, fallback_used: bool}
     */
    public function getComparables(Car $car): array
    {
        // Attempt 1: strict — year ±1, fuel, gearbox, power ±25
        $snapshots = $this->snapshotRepo->getComparableSnapshots($car);
        if ($snapshots->count() >= 3) {
            return ['snapshots' => $snapshots, 'fallback_used' => false];
        }

        // Attempt 2: widen year window (car ±3, motorhome ±5)
        $yearWindow = ($car->vehicle_type === 'motorhome') ? 5 : 3;
        $snapshots  = $this->snapshotRepo->getComparableSnapshotsWide($car, $yearWindow);
        if ($snapshots->isNotEmpty()) {
            return ['snapshots' => $snapshots, 'fallback_used' => true];
        }

        // Attempt 3 (cars only): drop fuel/gearbox/power — brand + model + year only
        if (($car->vehicle_type ?? 'car') === 'car') {
            $snapshots = $this->snapshotRepo->getComparableSnapshotsLoose($car, $yearWindow);
            if ($snapshots->isNotEmpty()) {
                return ['snapshots' => $snapshots, 'fallback_used' => true];
            }
        }

        // Attempt 4 (motorhome only): categoria (body_type) + ano.
        // O mercado de autocaravanas compara melhor por categoria (integral,
        // perfilada, capucine, campervan) do que por marca — McLouis Menfys Van
        // (campervan) e McLouis Nevis (integral) não são comparáveis. Só corre
        // se o car tiver categoria mapeada (ver MOTORHOME_CATEGORY_BODY_TYPE_MAP).
        if (($car->vehicle_type ?? null) === 'motorhome') {
            $car->loadMissing('category:id,slug');
            $bodyType = self::bodyTypeFor($car->category?->slug);

            if ($bodyType !== null) {
                $snapshots = $this->snapshotRepo->getComparableSnapshotsByCategory($car, $bodyType, $yearWindow);
                if ($snapshots->count() >= self::MIN_CATEGORY_COMPARABLES) {
                    return ['snapshots' => $snapshots, 'fallback_used' => true];
                }
            }
        }

        // Attempt 5 (motorhome only): brand + price band + year, NO model.
        // Motorhome models are too fragmented for exact-model match — compare
        // by brand within ±MOTORHOME_PRICE_BAND of the effective price instead.
        if (($car->vehicle_type ?? null) === 'motorhome') {
            $effectivePrice = $this->effectivePriceFor($car);
            if ($effectivePrice > 0.0) {
                $min       = $effectivePrice * (1 - self::MOTORHOME_PRICE_BAND);
                $max       = $effectivePrice * (1 + self::MOTORHOME_PRICE_BAND);
                $snapshots = $this->snapshotRepo->getComparableSnapshotsByBrandPrice($car, $yearWindow, $min, $max);
                if ($snapshots->isNotEmpty()) {
                    return ['snapshots' => $snapshots, 'fallback_used' => true];
                }
            }
        }

        return ['snapshots' => collect(), 'fallback_used' => false];
    }

    /** Effective price: promo when valid (positive and below gross), else gross. */
    private function effectivePriceFor(Car $car): float
    {
        $gross = (float) ($car->price_gross ?? 0);
        $promo = (float) ($car->promo_price_gross ?? 0);

        return ($promo > 0.0 && $promo < $gross) ? $promo : $gross;
    }

    /**
     * Pure computation — no DB writes. Returns the data array ready for persistence.
     */
    public function computeAggregateData(Collection $comparables, bool $fallbackUsed = false): array
    {
        $prices = $comparables
            ->pluck('price')
            ->map(fn ($p) => (float) $p)
            ->filter(fn ($p) => $p > 0.0)
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return [
                'status'            => 'none',
                'confidence'        => 'none',
                'comparables_count' => 0,
                'fallback_used'     => $fallbackUsed,
                'top_comparables'   => null,
            ];
        }

        $count  = $prices->count();
        $median = $this->computeMedian($prices);
        $avg    = round($prices->avg(), 2);
        $stdDev = $this->computeStdDev($prices, $avg);

        return [
            'status'            => 'success',
            'confidence'        => $this->deriveConfidence($count),
            'comparables_count' => $count,
            'median_price'      => $median,
            'min_price'         => round((float) $prices->min(), 2),
            'max_price'         => round((float) $prices->max(), 2),
            'avg_price'         => $avg,
            'std_dev'           => $stdDev,
            'fallback_used'     => $fallbackUsed,
            'top_comparables'   => $this->selectTop5($comparables, $median),
        ];
    }

    /**
     * Computes the aggregate and persists it to the given aggregate record.
     * Called by ScrapeMarketSnapshotJob after a successful scrape.
     */
    public function computeAndPersistAggregate(
        Car $car,
        int $aggregateId,
        Collection $comparables,
        bool $fallbackUsed = false,
    ): void {
        $data = $this->computeAggregateData($comparables, $fallbackUsed);

        if (isset($data['top_comparables']) && \is_array($data['top_comparables'])) {
            $data['top_comparables'] = json_encode($data['top_comparables']);
        }

        $data['updated_at'] = now();

        CarMarketAggregate::where('id', $aggregateId)->update($data);
    }

    /** Updates only the status column (used by job on error/block). */
    public function persistAggregateStatus(int $aggregateId, string $status): void
    {
        CarMarketAggregate::where('id', $aggregateId)->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function computeMedian(Collection $sortedPrices): float
    {
        $count = $sortedPrices->count();

        if ($count % 2 === 0) {
            return round(((float) $sortedPrices[$count / 2 - 1] + (float) $sortedPrices[$count / 2]) / 2.0, 2);
        }

        return round((float) $sortedPrices[(int) ($count / 2)], 2);
    }

    private function computeStdDev(Collection $prices, float $avg): float
    {
        $variance = $prices->map(fn ($p) => ($p - $avg) ** 2)->avg();

        return round(sqrt((float) $variance), 2);
    }

    private function deriveConfidence(int $count): string
    {
        return match (true) {
            $count >= 5 => 'high',
            $count >= 3 => 'medium',
            $count >= 1 => 'low',
            default     => 'none',
        };
    }

    private function selectTop5(Collection $comparables, float $median): array
    {
        return $comparables
            ->sortBy(fn ($s) => abs((float) $s->price - $median))
            ->take(5)
            ->map(fn ($s) => [
                'external_id' => $s->external_id,
                'source'      => $s->source,
                'title'       => $s->title ?? "{$s->brand} {$s->model} {$s->year}",
                'url'         => $s->url,
                'price'       => (float) $s->price,
                'year'        => $s->year,
                'fuel'        => $s->fuel,
                'gearbox'     => $s->gearbox,
                'region'      => $s->region,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Builds a Standvirtual search URL for the car's brand/year/fuel.
     * Stored in the aggregate at creation time so it's always available as a fallback
     * when a specific listing URL is no longer valid.
     *
     * Formato de URL do Standvirtual validado em 2026-05-28. O Standvirtual usa
     * URLs path-based: /{categoria}/{marca}/desde-{ano} com fuel e year:to em
     * query (sem índice [0]). O modelo é abandonado no URL (a precisão vem da
     * filtragem por modelo/preço no processamento). Se a Posição no Mercado
     * voltar a falhar, testar primeiro um URL real no browser (este formato muda
     * periodicamente) antes de alterar. Tem de ficar consistente com o
     * construtor de URL do scraper Python (scraper/config.py + scraper.py).
     */
    private function buildSearchUrl(Car $car): string
    {
        $paths = [
            'car'       => '/carros',
            'motorhome' => '/autocaravanas',
        ];
        $vehicleType = $car->vehicle_type ?? 'car';
        $path        = $paths[$vehicleType] ?? '/carros';

        $url = 'https://www.standvirtual.com' . $path;

        // Marca e ano "desde" vão no PATH (formato path-based).
        if ($car->brand?->name) {
            $url .= '/' . $this->slugifySearchValue($car->brand->name);
            if ($car->registration_year) {
                $url .= '/desde-' . ($car->registration_year - 1);
            }
        }

        // Combustível e limite superior do ano em query (fuel SEM índice [0]).
        // Combustível: omitir se não houver slug validado para o vertical em
        // causa (slug errado = 0 resultados silenciosos, MS1.a 2026-06-10).
        $query = [];
        if ($car->fuel_type) {
            $fuelSlug = $this->normalizeFuelForSearch($car->fuel_type, $vehicleType);
            if ($fuelSlug !== null) {
                $query['search[filter_enum_fuel_type]'] = $fuelSlug;
            }
        }
        if ($car->registration_year) {
            $query['search[filter_float_first_registration_year:to]'] = $car->registration_year + 1;
        }

        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    private function slugifySearchValue(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $cleaned        = strtolower(trim(str_replace(['/', '_'], ' ', $transliterated)));

        return implode('-', array_filter(explode(' ', $cleaned)));
    }

    /*
    |--------------------------------------------------------------------------
    | Mapa de slugs de combustível por vertical do Standvirtual (MS1.a)
    |--------------------------------------------------------------------------
    |
    | O Standvirtual usa DICIONÁRIOS DIFERENTES por secção:
    |   /carros        — slugs herdados da plataforma OLX/Otomoto (gaz, lpg, ...)
    |   /autocaravanas — slugs em português (gasolina, ...)
    |
    | Validação empírica em 2026-06-10 (curl ao live, contagem de "N anúncios"):
    |
    |   /autocaravanas?...=gasolina  → 2 anúncios   ✅ válido
    |   /autocaravanas?...=diesel    → 321 anúncios ✅ válido (88% do mercado)
    |   /autocaravanas?...=gaz       → 0 anúncios   ❌ inválido (controlo negativo)
    |   /carros?...=gaz              → 984 anúncios ✅ mantém-se (regressão)
    |
    | Inconclusivos (0 resultados em 364 anúncios → ambíguo entre "slug inválido"
    | e "mercado sem stock"): electric, eletrico, hibrido, hibride-gaz,
    | hibride-diesel, hybrid, plug-in-hybrid, plugin-hybrid, gpl, lpg.
    | → Estes ficam FORA do mapa motorhome. Regra: slug ausente = OMITIR o
    |   parâmetro (omissão > slug errado → devolve mercado da marca/ano sem
    |   filtro de fuel, melhor que zerar).
    | → MS1.b (widen-on-empty) é a 2.ª rede de segurança se um slug futuro estiver
    |   errado: scraper detecta 0 anúncios e repete sem fuel.
    |
    | TEM DE FICAR CONSISTENTE com scraper/config.py FUEL_SLUGS_BY_VERTICAL.
    | Mudança aqui → mudança no Python no mesmo PR (item 42 dívida técnica).
    |
    | Híbrido em /carros: o Standvirtual NÃO tem slug genérico, separa em
    | 'hibride-gaz' e 'hibride-diesel'. Como a BD não distingue o base, usamos
    | 'hibride-gaz' como fallback (mais comum em PT).
    */
    private const FUEL_SLUGS_BY_VERTICAL = [
        'car' => [
            'gasolina'         => 'gaz',
            'petrol'           => 'gaz',
            'gasoline'         => 'gaz',
            'diesel'           => 'diesel',
            'eletrico'         => 'electric',
            'electrico'        => 'electric',
            'electric'         => 'electric',
            'hibrido'          => 'hibride-gaz',
            'hybrid'           => 'hibride-gaz',
            'hibrido-plug-in'  => 'plugin-hybrid',
            'plug-in-hybrid'   => 'plugin-hybrid',
            'plugin-hybrid'    => 'plugin-hybrid',
            'gpl'              => 'gpl',
            'lpg'              => 'gpl',
        ],
        'motorhome' => [
            'gasolina'  => 'gasolina',
            'petrol'    => 'gasolina',
            'gasoline'  => 'gasolina',
            'diesel'    => 'diesel',
        ],
    ];

    /**
     * Devolve o slug de combustível para a vertical, ou null se não houver
     * mapeamento validado (caller omite o parâmetro).
     */
    private function normalizeFuelForSearch(string $fuel, string $vehicleType): ?string
    {
        $slug = $this->slugifySearchValue($fuel);

        return self::FUEL_SLUGS_BY_VERTICAL[$vehicleType][$slug] ?? null;
    }
}
