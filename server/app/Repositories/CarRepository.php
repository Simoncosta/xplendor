<?php

namespace App\Repositories;

use App\Models\Car;
use App\Models\CarMarketingIdea;
use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarRepository extends BaseRepository implements CarRepositoryInterface
{
    public function __construct(Car $model)
    {
        parent::__construct($model);
    }

    public function getAllWithAnalytics(
        array $columns = ['*'],
        array $relations = [],
        ?int $perPage = null,
        array $filters = [],
        array $orderBy = []
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
                $query->where($field, 'LIKE', '%' . $value['like'] . '%');
                continue;
            }

            if (is_array($value) && isset($value['between']) && is_array($value['between'])) {
                $query->whereBetween($field, $value['between']);
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($field, $value);
                continue;
            }

            $query->where($field, $value);
        }

        if (!empty($orderBy)) {
            foreach ($orderBy as $field => $direction) {
                $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                if ($field === 'views') {
                    $query->orderBy('views_count', $direction);
                    continue;
                }

                if ($field === 'leads') {
                    $query->orderBy('leads_count', $direction);
                    continue;
                }

                if ($field === 'interactions') {
                    $query->orderBy('interactions_count', $direction);
                    continue;
                }

                if ($field === 'brand') {
                    $query
                        ->leftJoin('car_brands', 'cars.car_brand_id', '=', 'car_brands.id')
                        ->orderBy('car_brands.name', $direction)
                        ->select('cars.*');
                    continue;
                }

                $query->orderBy($field, $direction);
            }
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
            'days_in_stock' => (int) $car->created_at->diffInDays(now()),
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

    public function getMarketingIdeasData(int $carId, int $companyId): Collection
    {
        return CarMarketingIdea::query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->orderByDesc('week_ref')
            ->orderByDesc('id')
            ->get();
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
