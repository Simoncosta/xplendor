<?php

namespace App\Repositories;

use App\Repositories\Contracts\SilentBuyerDetectionRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SilentBuyerDetectionRepository implements SilentBuyerDetectionRepositoryInterface
{
    public function detectByCompany(int $companyId, int $days = 30, int $limit = 100): Collection
    {
        return $this->baseDetectionQuery($companyId, now()->subDays($days))
            ->orderByDesc('strong_interactions_count')
            ->orderByDesc('interactions_count')
            ->orderByDesc('total_view_duration_seconds')
            ->orderByDesc('views_count')
            ->limit($limit)
            ->get();
    }

    public function detectByCar(int $companyId, int $carId, int $days = 30, int $limit = 50): Collection
    {
        return $this->baseDetectionQuery($companyId, now()->subDays($days), $carId)
            ->orderByDesc('strong_interactions_count')
            ->orderByDesc('interactions_count')
            ->orderByDesc('total_view_duration_seconds')
            ->orderByDesc('views_count')
            ->limit($limit)
            ->get();
    }

    protected function baseDetectionQuery(int $companyId, CarbonInterface $since, ?int $carId = null)
    {
        $pairs = $this->buildSignalPairsSubquery($companyId, $since, $carId);
        $views = $this->buildViewsSubquery($companyId, $since, $carId);
        $interactions = $this->buildInteractionsSubquery($companyId, $since, $carId);
        $leads = $this->buildLeadsSubquery($companyId, $since, $carId);

        return DB::query()
            ->fromSub($pairs, 'pairs')
            ->leftJoinSub($views, 'views_agg', function ($join) {
                $join
                    ->on('views_agg.company_id', '=', 'pairs.company_id')
                    ->on('views_agg.car_id', '=', 'pairs.car_id')
                    ->on('views_agg.visitor_id', '=', 'pairs.visitor_id');
            })
            ->leftJoinSub($interactions, 'interactions_agg', function ($join) {
                $join
                    ->on('interactions_agg.company_id', '=', 'pairs.company_id')
                    ->on('interactions_agg.car_id', '=', 'pairs.car_id')
                    ->on('interactions_agg.visitor_id', '=', 'pairs.visitor_id');
            })
            ->leftJoinSub($leads, 'leads_agg', function ($join) {
                $join
                    ->on('leads_agg.company_id', '=', 'pairs.company_id')
                    ->on('leads_agg.car_id', '=', 'pairs.car_id')
                    ->on('leads_agg.visitor_id', '=', 'pairs.visitor_id');
            })
            ->join('cars', 'cars.id', '=', 'pairs.car_id')
            ->leftJoin('car_brands', 'car_brands.id', '=', 'cars.car_brand_id')
            ->leftJoin('car_models', 'car_models.id', '=', 'cars.car_model_id')
            ->whereRaw('COALESCE(views_agg.views_count, 0) >= 3')
            ->whereRaw('COALESCE(views_agg.sessions_count, 0) >= 2')
            ->whereRaw('COALESCE(interactions_agg.interactions_count, 0) >= 1')
            ->whereRaw('COALESCE(leads_agg.leads_count, 0) = 0')
            ->selectRaw('
                pairs.company_id,
                pairs.car_id,
                pairs.visitor_id,
                cars.price_gross,
                cars.status,
                cars.is_resume,
                car_brands.name as brand_name,
                car_models.name as model_name,
                cars.version,
                COALESCE(views_agg.views_count, 0) as views_count,
                COALESCE(views_agg.sessions_count, 0) as sessions_count,
                COALESCE(views_agg.total_view_duration_seconds, 0) as total_view_duration_seconds,
                COALESCE(views_agg.avg_view_duration_seconds, 0) as avg_view_duration_seconds,
                views_agg.first_view_at,
                views_agg.last_view_at,
                COALESCE(interactions_agg.interactions_count, 0) as interactions_count,
                COALESCE(interactions_agg.strong_interactions_count, 0) as strong_interactions_count,
                COALESCE(interactions_agg.contact_intent_count, 0) as contact_intent_count,
                COALESCE(interactions_agg.interaction_types, "") as interaction_types,
                interactions_agg.last_interaction_at,
                COALESCE(leads_agg.leads_count, 0) as leads_count,
                CASE WHEN COALESCE(leads_agg.leads_count, 0) > 0 THEN 1 ELSE 0 END as has_lead
            ');
    }

    protected function buildSignalPairsSubquery(int $companyId, CarbonInterface $since, ?int $carId = null)
    {
        $viewPairs = DB::table('car_views')
            ->select('company_id', 'car_id', 'visitor_id')
            ->where('company_id', $companyId)
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->where('created_at', '>=', $since);

        $interactionPairs = DB::table('car_interactions')
            ->select('company_id', 'car_id', 'visitor_id')
            ->where('company_id', $companyId)
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->where('created_at', '>=', $since);

        if ($carId) {
            $viewPairs->where('car_id', $carId);
            $interactionPairs->where('car_id', $carId);
        }

        return DB::query()
            ->fromSub($viewPairs->union($interactionPairs), 'signal_pairs')
            ->select('company_id', 'car_id', 'visitor_id')
            ->distinct();
    }

    protected function buildViewsSubquery(int $companyId, CarbonInterface $since, ?int $carId = null)
    {
        $query = DB::table('car_views')
            ->selectRaw('
                company_id,
                car_id,
                visitor_id,
                COUNT(*) as views_count,
                COUNT(DISTINCT session_id) as sessions_count,
                COALESCE(SUM(view_duration_seconds), 0) as total_view_duration_seconds,
                COALESCE(AVG(COALESCE(view_duration_seconds, 0)), 0) as avg_view_duration_seconds,
                MIN(created_at) as first_view_at,
                MAX(created_at) as last_view_at
            ')
            ->where('company_id', $companyId)
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->where('created_at', '>=', $since)
            ->groupBy('company_id', 'car_id', 'visitor_id');

        if ($carId) {
            $query->where('car_id', $carId);
        }

        return $query;
    }

    protected function buildInteractionsSubquery(int $companyId, CarbonInterface $since, ?int $carId = null)
    {
        $strongTypes = [
            'whatsapp_click',
            'call_click',
            'show_phone',
            'copy_phone',
            'form_open',
            'form_start',
            'location_view',
            'contact',
            'contact_click',
        ];

        $contactIntentTypes = [
            'whatsapp_click',
            'call_click',
            'show_phone',
            'copy_phone',
            'contact',
            'contact_click',
        ];

        $query = DB::table('car_interactions')
            ->selectRaw(
                '
                company_id,
                car_id,
                visitor_id,
                COUNT(*) as interactions_count,
                SUM(CASE WHEN interaction_type IN (' . $this->quoteList($strongTypes) . ') THEN 1 ELSE 0 END) as strong_interactions_count,
                SUM(CASE WHEN interaction_type IN (' . $this->quoteList($contactIntentTypes) . ') THEN 1 ELSE 0 END) as contact_intent_count,
                GROUP_CONCAT(DISTINCT interaction_type ORDER BY interaction_type SEPARATOR ",") as interaction_types,
                MAX(created_at) as last_interaction_at
            '
            )
            ->where('company_id', $companyId)
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->where('created_at', '>=', $since)
            ->groupBy('company_id', 'car_id', 'visitor_id');

        if ($carId) {
            $query->where('car_id', $carId);
        }

        return $query;
    }

    protected function buildLeadsSubquery(int $companyId, CarbonInterface $since, ?int $carId = null)
    {
        $query = DB::table('car_leads')
            ->selectRaw('company_id, car_id, visitor_id, COUNT(*) as leads_count')
            ->where('company_id', $companyId)
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->where('created_at', '>=', $since)
            ->groupBy('company_id', 'car_id', 'visitor_id');

        if ($carId) {
            $query->where('car_id', $carId);
        }

        return $query;
    }

    protected function quoteList(array $values): string
    {
        return collect($values)
            ->map(fn(string $value) => "'" . str_replace("'", "\\'", $value) . "'")
            ->implode(', ');
    }
}
