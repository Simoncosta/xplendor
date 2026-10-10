<?php

namespace App\Repositories;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Repositories\CarAdSpendRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarRepository extends BaseRepository implements CarRepositoryInterface
{
    public function __construct(Car $model)
    {
        parent::__construct($model);
    }

    /**
     * Ordenações permitidas da lista de viaturas (?sort=, via App\Support\ListSort): chave → coluna
     * escrita aqui, ou função. Views, leads e interações são as contagens do withCount (alias);
     * a conversão é leads ÷ views (0 quando não há views, como no ecrã).
     *
     * @return array<string, string|\Closure>
     */
    public static function sorts(): array
    {
        return [
            'car' => fn ($q, string $dir) => $q
                ->orderBy(CarBrand::select('name')->whereColumn('car_brands.id', 'cars.car_brand_id'), $dir)
                ->orderBy(CarModel::select('name')->whereColumn('car_models.id', 'cars.car_model_id'), $dir)
                ->orderBy('cars.version', $dir),
            'price'        => 'cars.price_gross',
            'views'        => 'views_count',
            'leads'        => 'leads_count',
            'interactions' => 'interactions_count',
            'conversion'   => fn ($q, string $dir) => $q->orderByRaw('COALESCE((leads_count * 1.0) / NULLIF(views_count, 0), 0) ' . ($dir === 'desc' ? 'desc' : 'asc')),
        ];
    }

    public function getAllWithAnalytics(
        array $columns = ['*'],
        array $relations = [],
        ?int $perPage = null,
        array $filters = [],
        ?\Closure $sort = null
    ): mixed {
        $query = $this->model->select($columns);

        if (!empty($relations)) {
            $query->with($relations);
        }

        $query->withCount([
            'views',
            'leads',
            'interactions',
        ]);

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($field === 'has_active_campaign') {
                if ((bool) $value) {
                    $query->whereHas('adCampaigns', function ($campaignQuery) {
                        $campaignQuery->where('is_active', 1);
                    });
                }

                continue;
            }

            if (is_array($value) && isset($value['like'])) {
                $query->where(self::column($field), 'LIKE', '%' . $value['like'] . '%');
                continue;
            }

            if (is_array($value) && isset($value['between']) && is_array($value['between'])) {
                $query->whereBetween(self::column($field), $value['between']);
                continue;
            }

            if (is_array($value)) {
                $query->whereIn(self::column($field), $value);
                continue;
            }

            $query->where(self::column($field), $value);
        }

        if ($sort) {
            $sort($query);
        }

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    public function getSmartAdsContext(int $carId, int $companyId): array
    {
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $car = $this->model->query()
            ->where('company_id', $companyId)
            ->where('id', $carId)
            ->firstOrFail();

        $performance = DB::table('car_performance_metrics')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->selectRaw('
                COALESCE(SUM(sessions), 0) as views,
                COALESCE(SUM(leads_count), 0) as leads,
                COALESCE(SUM(spend_amount), 0) as fallback_spend
            ')
            ->first();

        $metaSpend = (float) DB::table('meta_audience_insights')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->sum('spend');

        $ips = DB::table('car_sale_potential_scores')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first(['score']);

        $views = (int) ($performance->views ?? 0);
        $leads = (int) ($performance->leads ?? 0);
        $fallbackSpend = round((float) ($performance->fallback_spend ?? 0), 2);
        $spend = round($metaSpend > 0 ? $metaSpend : $fallbackSpend, 2);

        // Empresa com tags [id:N] nos anúncios: o gasto vem da fonte única (as cópias
        // do pipeline antigo deixam de ser actualizadas para ela).
        $spendRepo = app(CarAdSpendRepository::class);
        if ($spendRepo->usesTags($companyId)) {
            $spend = $spendRepo->totalsForCar($companyId, $carId, $from, $to)['spend'];
        }
        $promo = $this->promotionMetrics($car);

        return [
            'status' => $car->status,
            'price_gross' => $car->price_gross,
            'promo_price_gross' => $car->promo_price_gross,
            'promo_discount_value' => $promo['promo_discount_value'],
            'promo_discount_pct' => $promo['promo_discount_pct'],
            'views' => $views,
            'leads' => $leads,
            'spend' => $spend,
            'conversion_rate' => $views > 0 ? round(($leads / $views) * 100, 2) : 0.0,
            'cost_per_lead' => $leads > 0 ? round($spend / $leads, 2) : null,
            'ips_score' => (int) round((float) ($ips->score ?? 0)),
            'days_in_stock' => (int) ($car->daysInStock() ?? 0),
        ];
    }

    public function getAiAnalysisData(int $carId, int $companyId): ?array
    {
        $car = $this->model->query()
            ->with('analyses')
            ->where('company_id', $companyId)
            ->where('id', $carId)
            ->first();

        $analysis = $car?->analyses?->analysis;
        if (!$analysis) {
            return null;
        }

        $recommendedChannel = $this->normalizeRecommendedChannel($analysis['canal_principal']['canal'] ?? null);

        return [
            'recommended_channel' => $recommendedChannel,
            'recommended_channel_label' => $analysis['canal_principal']['canal'] ?? null,
            'recommended_channel_reason' => $analysis['canal_principal']['justificacao'] ?? null,
            'recommended_action' => $analysis['recomendacao_urgencia']['acao_recomendada'] ?? null,
            'probability_30d' => $analysis['previsao']['probabilidade_venda_30d'] ?? null,
            'score_justification' => $analysis['score_conversao']['justificacao'] ?? null,
            'urgency_level' => $car?->analyses?->urgency_level,
            'source' => 'ai_analysis',
        ];
    }

    protected function normalizeRecommendedChannel(?string $channel): ?string
    {
        $value = strtoupper(trim((string) $channel));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, 'GOOGLE')) {
            return 'google';
        }

        if (str_contains($value, 'META')) {
            return 'meta';
        }

        return null;
    }

    protected function promotionMetrics(Car $car): array
    {
        return [
            'promo_discount_value' => $car->promo_discount_value,
            'promo_discount_pct' => $car->promo_discount_pct,
        ];
    }
}
