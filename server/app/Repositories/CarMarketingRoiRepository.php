<?php

namespace App\Repositories;

use App\Models\CarPerformanceMetric;
use App\Repositories\Contracts\CarMarketingRoiRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarMarketingRoiRepository extends BaseRepository implements CarMarketingRoiRepositoryInterface
{
    public function __construct(CarPerformanceMetric $model)
    {
        parent::__construct($model);
    }

    public function getMetricsByChannel(int $companyId, string $from, string $to): Collection
    {
        $rows = $this->applyPeriodOverlap(
            $this->model->newQuery()->where('company_id', $companyId),
            $from,
            $to
        )
            ->selectRaw('
                channel,
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                SUM(sessions) as sessions,
                SUM(leads_count) as leads,
                0 as spend
            ')
            ->groupBy('channel')
            ->orderByDesc('leads')
            ->orderByDesc('sessions')
            ->get();

        $metaSpendSummary = $this->getMetaSpendSummary($companyId, $from, $to);
        $metaSpend = (float) ($metaSpendSummary->total_spend ?? 0);

        $paidRow = $rows->firstWhere('channel', 'paid');

        if ($paidRow) {
            $paidRow->spend = round($metaSpend, 2);
            return $rows;
        }

        if ($metaSpend > 0) {
            $rows->push((object) [
                'channel' => 'paid',
                'impressions' => 0,
                'clicks' => 0,
                'sessions' => 0,
                'leads' => 0,
                'spend' => round($metaSpend, 2),
            ]);
        }

        return $rows;
    }

    public function getCampaignPerformance(int $companyId, string $from, string $to): Collection
    {
        $campaignMappings = DB::table('car_ad_campaigns')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('platform', 'meta')
            ->groupBy('company_id', 'car_id', 'platform', 'campaign_id')
            ->selectRaw('
                company_id,
                car_id,
                platform,
                campaign_id,
                MAX(NULLIF(campaign_name, "")) as campaign_name,
                SUM(spend_split_pct) as campaign_weight
            ');

        $campaignMappingTotals = DB::table('car_ad_campaigns')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('platform', 'meta')
            ->groupBy('company_id', 'car_id', 'platform')
            ->selectRaw('
                company_id,
                car_id,
                platform,
                SUM(spend_split_pct) as total_weight
            ');

        $paidMetricsByCar = $this->applyPeriodOverlap(
            $this->model->newQuery()
                ->where('company_id', $companyId)
                ->where('channel', 'paid'),
            $from,
            $to
        )
            ->selectRaw('
                car_id,
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                SUM(sessions) as sessions,
                SUM(leads_count) as leads
            ')
            ->groupBy('car_id');

        $metaSpendByCar = $this->buildMetaSpendByCarQuery($companyId, $from, $to);

        return DB::query()
            ->fromSub($campaignMappings, 'campaign_mappings')
            ->joinSub($campaignMappingTotals, 'campaign_mapping_totals', function ($join) {
                $join->on('campaign_mapping_totals.company_id', '=', 'campaign_mappings.company_id')
                    ->on('campaign_mapping_totals.car_id', '=', 'campaign_mappings.car_id')
                    ->on('campaign_mapping_totals.platform', '=', 'campaign_mappings.platform');
            })
            ->leftJoinSub($paidMetricsByCar, 'paid_metrics', function ($join) {
                $join->on('paid_metrics.car_id', '=', 'campaign_mappings.car_id');
            })
            ->leftJoinSub($metaSpendByCar, 'meta_spend', function ($join) {
                $join->on('meta_spend.car_id', '=', 'campaign_mappings.car_id');
            })
            ->selectRaw('
                campaign_mappings.campaign_id,
                COALESCE(MAX(campaign_mappings.campaign_name), CONCAT("Campaign ", campaign_mappings.campaign_id)) as campaign_name,
                campaign_mappings.platform,
                ROUND(SUM(COALESCE(paid_metrics.impressions, 0) * (campaign_mappings.campaign_weight / NULLIF(campaign_mapping_totals.total_weight, 0))), 0) as impressions,
                ROUND(SUM(COALESCE(paid_metrics.clicks, 0) * (campaign_mappings.campaign_weight / NULLIF(campaign_mapping_totals.total_weight, 0))), 0) as clicks,
                ROUND(SUM(COALESCE(paid_metrics.sessions, 0) * (campaign_mappings.campaign_weight / NULLIF(campaign_mapping_totals.total_weight, 0))), 0) as sessions,
                ROUND(SUM(COALESCE(paid_metrics.leads, 0) * (campaign_mappings.campaign_weight / NULLIF(campaign_mapping_totals.total_weight, 0))), 0) as leads,
                ROUND(SUM(COALESCE(meta_spend.spend, 0) * (campaign_mappings.campaign_weight / NULLIF(campaign_mapping_totals.total_weight, 0))), 2) as spend
            ')
            ->groupBy('campaign_mappings.campaign_id', 'campaign_mappings.platform')
            ->orderByDesc('leads')
            ->orderBy('spend')
            ->get();
    }

    public function getCarPerformance(int $companyId, string $from, string $to): Collection
    {
        $latestIpsSubquery = DB::table('car_sale_potential_scores as csps')
            ->selectRaw('
                csps.car_id,
                csps.company_id,
                csps.score,
                csps.classification
            ')
            ->where('csps.company_id', $companyId)
            ->whereIn('csps.id', function ($query) use ($companyId) {
                $query->selectRaw('MAX(id)')
                    ->from('car_sale_potential_scores')
                    ->where('company_id', $companyId)
                    ->groupBy('car_id');
            });

        $metaSpendByCar = $this->buildMetaSpendByCarQuery($companyId, $from, $to);

        return DB::table('cars')
            ->leftJoin('car_performance_metrics as metrics', function ($join) use ($companyId, $from, $to) {
                $join->on('metrics.car_id', '=', 'cars.id')
                    ->where('metrics.company_id', '=', $companyId)
                    ->whereDate('metrics.period_start', '<=', $to)
                    ->whereDate('metrics.period_end', '>=', $from);
            })
            ->leftJoinSub($metaSpendByCar, 'meta_spend', function ($join) {
                $join->on('meta_spend.car_id', '=', 'cars.id');
            })
            ->leftJoin('car_brands', 'car_brands.id', '=', 'cars.car_brand_id')
            ->leftJoin('car_models', 'car_models.id', '=', 'cars.car_model_id')
            ->leftJoinSub($latestIpsSubquery, 'latest_ips', function ($join) {
                $join->on('latest_ips.car_id', '=', 'cars.id')
                    ->on('latest_ips.company_id', '=', 'cars.company_id');
            })
            ->where('cars.company_id', $companyId)
            ->where('cars.status', 'active')
            ->selectRaw('
                cars.id as car_id,
                CONCAT(
                    COALESCE(car_brands.name, ""),
                    CASE WHEN car_brands.name IS NOT NULL AND car_models.name IS NOT NULL THEN " " ELSE "" END,
                    COALESCE(car_models.name, ""),
                    CASE WHEN cars.version IS NOT NULL AND cars.version != "" THEN CONCAT(" ", cars.version) ELSE "" END
                ) as car_name,
                COALESCE(latest_ips.score, 0) as ips_score,
                latest_ips.classification as ips_classification,
                SUM(COALESCE(metrics.sessions, 0)) as views,
                SUM(COALESCE(metrics.leads_count, 0)) as leads,
                COALESCE(MAX(meta_spend.spend), 0) as spend
            ')
            ->groupBy('cars.id', 'car_brands.name', 'car_models.name', 'cars.version', 'latest_ips.score', 'latest_ips.classification')
            ->havingRaw('SUM(COALESCE(metrics.sessions, 0)) > 0 OR SUM(COALESCE(metrics.leads_count, 0)) > 0 OR COALESCE(MAX(meta_spend.spend), 0) > 0')
            ->orderByDesc('ips_score')
            ->orderByDesc('leads')
            ->get();
    }

    public function getMetaSpendSummary(int $companyId, string $from, string $to): object
    {
        return $this->applyPeriodOverlap(
            DB::table('meta_audience_insights')->where('company_id', $companyId),
            $from,
            $to
        )
            ->selectRaw('
                COALESCE(SUM(spend), 0) as total_spend,
                COALESCE(SUM(reach), 0) as total_reach,
                COALESCE(SUM(clicks), 0) as total_clicks
            ')
            ->first();
    }

    public function getMetaSpendByCar(int $companyId, string $from, string $to): Collection
    {
        return $this->buildMetaSpendByCarQuery($companyId, $from, $to)->get();
    }

    private function applyPeriodOverlap($query, string $from, string $to)
    {
        return $query
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from);
    }

    private function buildMetaSpendByCarQuery(int $companyId, string $from, string $to)
    {
        return $this->applyPeriodOverlap(
            DB::table('meta_audience_insights')->where('company_id', $companyId),
            $from,
            $to
        )
            ->selectRaw('
                car_id,
                COALESCE(SUM(spend), 0) as spend,
                COALESCE(SUM(reach), 0) as reach,
                COALESCE(SUM(clicks), 0) as audience_clicks
            ')
            ->groupBy('car_id');
    }
}
