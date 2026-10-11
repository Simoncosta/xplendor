<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Models\CarMarketAggregate;
use App\Models\CompassPostSubject;
use App\Repositories\CarAdSpendRepository;
use App\Support\AutomotivePriceComparison;
use App\Support\PricePosition;
use App\Support\StockAge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bússola do stand: os factos de cada veículo do stock próprio, a partir do que já existe
 * (StockAge e StockThresholds, PricePosition com a regra da comparação exata, o gasto da fonte
 * única CarAdSpendRepository, o funil car_funnel_metrics_daily e as publicações criadas pela
 * Bússola). Só contagens e números: nunca os nomes, telefones ou e-mails dos contactos.
 *
 * "Em stock" é o stock próprio (Car::IN_STOCK_STATUSES, sem retomas), como o dashboard; "à
 * venda" é o estado active. Os tipos: "car" (Carros) e "motorhome" (Autocaravanas, com as
 * caravanas, o mesmo limiar de 120 dias); as motos só contam em "Todos".
 */
final class StandFacts
{
    public const WINDOW_DAYS = 28;      // as últimas 4 semanas
    public const STAGE_DAYS = 30;       // "sem anúncio nem publicação nos últimos 30 dias"
    public const FORGOTTEN_DAYS = 30;   // "à venda há mais de 30 dias"
    public const TYPE_GROUPS = ['car' => ['car'], 'motorhome' => ['motorhome', 'caravan']];

    public function __construct(private readonly CarAdSpendRepository $spend) {}

    /** O grupo do seletor ("car", "motorhome") de um tipo de veículo; null = só em "Todos". */
    public static function typeGroup(?string $vehicleType): ?string
    {
        foreach (self::TYPE_GROUPS as $group => $types) {
            if (in_array($vehicleType, $types, true)) {
                return $group;
            }
        }

        return null;
    }

    /** O veículo em texto: "Peugeot 208 (2019)", sem dados pessoais. */
    public static function label(Car $car): string
    {
        $name = trim(($car->brand?->name ?? '') . ' ' . ($car->model?->name ?? '')) ?: "Veículo {$car->id}";

        return $car->registration_year ? "{$name} ({$car->registration_year})" : $name;
    }

    /**
     * Os factos do stock próprio em stock, por veículo.
     *
     * @return array<int, array> [car_id => factos]
     */
    public function forCompany(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $cars = Car::with(['brand', 'model', 'category'])->where('company_id', $companyId)->where('is_resume', false)
            ->whereIn('status', Car::IN_STOCK_STATUSES)->orderBy('id')->get();
        if ($cars->isEmpty()) {
            return [];
        }
        $ids = $cars->pluck('id')->all();
        $aggregates = CarMarketAggregate::whereIn('car_id', $ids)->whereNotNull('median_price')->where('median_price', '>', 0)
            ->orderByDesc('id')->get()->unique('car_id')->keyBy('car_id');
        $from = $today->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $stageFrom = $today->subDays(self::STAGE_DAYS - 1)->toDateString();
        $to = $today->toDateString();
        $spend28 = $this->spend->totalsByCar($companyId, $ids, $from, $to);
        $spend30 = $this->spend->totalsByCar($companyId, $ids, $stageFrom, $to);
        $activeAds = $this->spend->activeCampaignsByCar($companyId, $ids);
        $funnel = DB::table('car_funnel_metrics_daily')->where('company_id', $companyId)->whereIn('car_id', $ids)
            ->whereBetween('date', [$from, $to])->groupBy('car_id')
            ->selectRaw('car_id, COALESCE(SUM(views), 0) as views, COALESCE(SUM(contacts), 0) as contacts, COALESCE(SUM(leads), 0) as leads')
            ->get()->keyBy('car_id');
        $posts = CompassPostSubject::query()->join('editorial_posts as p', 'p.id', '=', 'compass_post_subjects.editorial_post_id')
            ->where('compass_post_subjects.company_id', $companyId)->where('compass_post_subjects.subject_type', 'car')
            ->whereIn('compass_post_subjects.subject_id', $ids)->where('p.publish_date', '>=', $stageFrom)
            ->pluck('compass_post_subjects.subject_id')->map(fn ($id) => (int) $id)->flip()->all();

        $out = [];
        foreach ($cars as $car) {
            $agg = $aggregates->get($car->id);
            $price = AutomotivePriceComparison::fromPosition(PricePosition::compute($car, $agg), $agg);
            $days = StockAge::daysInStock($car); // a mesma regra do dashboard (até agora)
            $exact = $price['comparison'] === AutomotivePriceComparison::EXACT && PricePosition::meetsMinConfidence($price['confidence']);
            $out[$car->id] = [
                'car_id' => $car->id,
                'vehicle_type' => $car->vehicle_type,
                'group' => self::typeGroup($car->vehicle_type),
                'status' => $car->status,
                'for_sale' => $car->status === 'active',
                'label' => self::label($car),
                'brand' => $car->brand?->name,
                'model' => $car->model?->name,
                'year' => $car->registration_year,
                'km' => $car->mileage_km,
                'price' => $price['effective_price'],
                'days' => $days,
                'threshold' => StockThresholds::ageThresholdFor($car->vehicle_type),
                'stale' => StockThresholds::isStale($car->vehicle_type, $days),
                'entry_estimated' => $car->car_created_at === null,
                // A comparação de preço: só exata e com confiança média ou alta conta.
                'price_eligible' => $exact,
                'price_reason' => $exact ? null : self::priceReason($car, $agg, $price),
                'position' => $exact ? $price['position'] : null,
                'diff_pct' => $exact ? $price['difference_pct'] : null,
                'median' => $exact ? $price['median'] : null,
                'comparables' => $exact ? $price['comparables_count'] : null,
                'price_confidence' => $exact ? $price['confidence'] : null,
                'criteria_widened' => $exact && $price['criteria_widened'],
                'active_ads' => (int) ($activeAds[$car->id] ?? 0),
                'spend_28' => (float) ($spend28[$car->id]['spend'] ?? 0),
                'spend_30' => (float) ($spend30[$car->id]['spend'] ?? 0),
                'views_28' => (int) ($funnel->get($car->id)->views ?? 0),
                'contacts_28' => (int) ($funnel->get($car->id)->contacts ?? 0),
                'compass_post_30' => isset($posts[$car->id]),
            ];
        }

        return $out;
    }

    /** Porque fica fora das comparações de preço (para a nota do bloco). */
    private static function priceReason(Car $car, ?CarMarketAggregate $agg, array $price): string
    {
        if ($price['comparison'] === AutomotivePriceComparison::APPROXIMATE) {
            return 'aproximada';
        }
        if ($agg === null) {
            if ($car->vehicle_type === 'caravan') {
                return 'sem_mercado';
            }
            if ($car->vehicle_type === 'motorhome' && \App\Services\MarketSnapshotService::bodyTypeFor($car->category?->slug) === null) {
                return 'sem_tipologia';
            }

            return 'sem_comparaveis';
        }

        return 'confianca_baixa';
    }

    /**
     * O gasto das últimas 4 semanas por veículo, de TODOS os veículos da empresa (também os já
     * vendidos: o dinheiro foi gasto), com os contactos de cada um no mesmo período.
     *
     * @return list<array{car_id: int, label: string, group: ?string, spend: float, contacts: int}>
     */
    public function adRows(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $from = $today->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $to = $today->toDateString();
        $spend = $this->spend->dailyRows($companyId, $from, $to)->whereNotNull('car_id')
            ->selectRaw('car_id, COALESCE(SUM(spend), 0) as spend')->groupBy('car_id')->pluck('spend', 'car_id')
            ->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0)->all();
        if ($spend === []) {
            return [];
        }
        $contacts = DB::table('car_funnel_metrics_daily')->where('company_id', $companyId)->whereIn('car_id', array_keys($spend))
            ->whereBetween('date', [$from, $to])->groupBy('car_id')->selectRaw('car_id, COALESCE(SUM(contacts), 0) as contacts')->pluck('contacts', 'car_id');
        $cars = Car::with(['brand', 'model'])->where('company_id', $companyId)->whereIn('id', array_keys($spend))->get()->keyBy('id');
        $out = [];
        foreach ($spend as $carId => $value) {
            $car = $cars->get((int) $carId);
            if (! $car) {
                continue;
            }
            $out[] = ['car_id' => (int) $carId, 'label' => self::label($car), 'group' => self::typeGroup($car->vehicle_type), 'sold' => $car->status === 'sold',
                'spend' => $value, 'contacts' => (int) ($contacts[$carId] ?? 0)];
        }
        usort($out, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $out;
    }

    /** Só os do grupo escolhido ("all" = todos). */
    public static function filter(array $facts, string $type): array
    {
        return $type === 'all' ? $facts : array_filter($facts, fn ($f) => $f['group'] === $type);
    }
}
