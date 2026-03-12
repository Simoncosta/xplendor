<?php

namespace App\Repositories;

use App\Models\Car;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use Illuminate\Support\Facades\DB;

class DashboardRepository extends BaseRepository implements DashboardRepositoryInterface
{
    public function __construct(Car $model)
    {
        parent::__construct($model);
    }

    /**
     * Resumo do inventário
     */
    public function getSummary(int $companyId): array
    {
        $summary = $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->selectRaw("
                COUNT(*) as total_cars,
                SUM(CASE WHEN is_resume = 0 THEN 1 ELSE 0 END) as own_stock,
                SUM(CASE WHEN is_resume = 1 THEN 1 ELSE 0 END) as trade_ins,
                AVG(price_gross) as avg_price,
                AVG(mileage_km) as avg_km,
                AVG(DATEDIFF(NOW(), created_at)) as avg_days_in_stock
            ")
            ->first();

        return [
            'total_cars' => (int) $summary->total_cars,
            'own_stock' => (int) $summary->own_stock,
            'trade_ins' => (int) $summary->trade_ins,
            'avg_price' => round($summary->avg_price ?? 0, 2),
            'avg_km' => (int) ($summary->avg_km ?? 0),
            'avg_days_in_stock' => round($summary->avg_days_in_stock ?? 0),
        ];
    }

    /**
     * Carros com mais interesse
     */
    public function getTopInterestCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at'
            ])
            ->with([
                'brand:id,name',
                'model:id,name'
            ])
            ->withCount([
                'views',
                'leads'
            ])
            ->orderByDesc('views_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Interesse alto / conversão baixa
     */
    public function getLowLeadCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->withCount([
                'views',
                'leads'
            ])
            ->with([
                'brand:id,name',
                'model:id,name'
            ])
            ->having('views_count', '>', 20)
            ->orderBy('leads_count')
            ->orderByDesc('views_count')
            ->limit($limit)
            ->get([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at'
            ]);
    }

    /**
     * Carros mais antigos em stock
     */
    public function getOldestCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', false)
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at',
                DB::raw('DATEDIFF(NOW(), created_at) as days_in_stock')
            ])
            ->with([
                'brand:id,name',
                'model:id,name'
            ])
            ->orderByDesc('days_in_stock')
            ->limit($limit)
            ->get();
    }

    /**
     * Melhor taxa de interesse
     */
    public function getBestInterestRateCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
            ])
            ->with([
                'brand:id,name',
                'model:id,name'
            ])
            ->withCount([
                'views',
                'interactions'
            ])
            ->having('views_count', '>=', 10)
            ->orderByRaw("
            CASE
                WHEN views_count = 0 THEN 0
                ELSE (interactions_count / views_count) * 100
            END DESC
        ")
            ->limit($limit)
            ->get()
            ->map(function ($car) {
                $car->interest_rate = $car->views_count > 0
                    ? round(($car->interactions_count / $car->views_count) * 100, 2)
                    : 0;

                return $car;
            });
    }

    public function getCapitalSummary(int $companyId): array
    {
        $result = $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->selectRaw("
            COALESCE(SUM(price_gross), 0) as total_capital,
            COALESCE(SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 60 THEN price_gross ELSE 0 END), 0) as stuck_capital_over_60_days
        ")
            ->first();

        return [
            'total_capital' => (float) ($result->total_capital ?? 0),
            'stuck_capital_over_60_days' => (float) ($result->stuck_capital_over_60_days ?? 0),
        ];
    }

    public function getHighestStuckCapitalCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->whereRaw('DATEDIFF(NOW(), created_at) > 60')
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at',
                DB::raw('DATEDIFF(NOW(), created_at) as days_in_stock'),
            ])
            ->with([
                'brand:id,name',
                'model:id,name',
            ])
            ->orderByDesc('price_gross')
            ->limit($limit)
            ->get()
            ->map(function ($car) {
                $car->brand_name = $car->brand?->name;
                $car->model_name = $car->model?->name;

                return $car;
            });
    }

    public function getUrgentActionCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at',
                DB::raw('DATEDIFF(NOW(), created_at) as days_in_stock'),
            ])
            ->with([
                'brand:id,name',
                'model:id,name',
            ])
            ->withCount([
                'views',
                'leads',
            ])
            ->get()
            ->map(function ($car) {
                $car->brand_name = $car->brand?->name;
                $car->model_name = $car->model?->name;

                $reason = null;
                $priority = 0;

                if ($car->views_count >= 100 && $car->leads_count === 0) {
                    $reason = "{$car->views_count} views / 0 leads";
                    $priority = 3;
                } elseif ($car->days_in_stock >= 60) {
                    $reason = "{$car->days_in_stock} dias em stock";
                    $priority = 2;
                } elseif ($car->views_count >= 30 && $car->leads_count <= 1) {
                    $reason = "Conversão baixa";
                    $priority = 1;
                }

                if (!$reason) {
                    return null;
                }

                $car->reason = $reason;
                $car->priority = $priority;

                return $car;
            })
            ->filter()
            ->sortByDesc('priority')
            ->take($limit)
            ->values();
    }

    public function getHighDemandOpportunityCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at',
            ])
            ->with([
                'brand:id,name',
                'model:id,name',
            ])
            ->withCount([
                'views',
                'leads',
                'interactions',
            ])
            ->get()
            ->map(function ($car) {
                $car->brand_name = $car->brand?->name;
                $car->model_name = $car->model?->name;

                $interestRate = $car->views_count > 0
                    ? round((($car->leads_count + $car->interactions_count) / $car->views_count) * 100, 2)
                    : 0;

                $leadRate = $car->views_count > 0
                    ? round(($car->leads_count / $car->views_count) * 100, 2)
                    : 0;

                $demandScore = ($car->views_count * 1)
                    + ($car->interactions_count * 3)
                    + ($car->leads_count * 5);

                if ($car->views_count < 50) {
                    return null;
                }

                $suggestion = 'Monitorizar, carro com procura saudável';

                if ($car->views_count >= 150 && $car->leads_count === 0) {
                    $suggestion = 'Rever preço e melhorar fotos';
                } elseif ($car->views_count >= 150 && $car->leads_count >= 2) {
                    $suggestion = 'Aumentar orçamento e destacar anúncio';
                } elseif ($car->interactions_count >= 10 && $car->leads_count === 0) {
                    $suggestion = 'Melhorar descrição e CTA do anúncio';
                } elseif ($leadRate >= 2) {
                    $suggestion = 'Carro com boa conversão, reforçar promoção';
                }

                $car->interest_rate = $interestRate;
                $car->lead_rate = $leadRate;
                $car->demand_score = $demandScore;
                $car->suggestion = $suggestion;

                return $car;
            })
            ->filter()
            ->sortByDesc('demand_score')
            ->take($limit)
            ->values();
    }

    public function getHighInterestLowConversionCars(int $companyId, int $limit = 5)
    {
        return $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'public_version_name',
                'price_gross',
                'created_at',
            ])
            ->with([
                'brand:id,name',
                'model:id,name',
            ])
            ->withCount([
                'views',
                'interactions',
                'leads',
            ])
            ->get()
            ->map(function ($car) {

                $car->brand_name = $car->brand?->name;
                $car->model_name = $car->model?->name;

                if ($car->views_count < 80) {
                    return null;
                }

                $interactionRate = $car->views_count > 0
                    ? $car->interactions_count / $car->views_count
                    : 0;

                $leadRate = $car->views_count > 0
                    ? $car->leads_count / $car->views_count
                    : 0;

                $interestRate = $car->views_count > 0
                    ? round((($car->interactions_count + $car->leads_count) / $car->views_count) * 100, 2)
                    : 0;

                $suggestions = [];

                // Muito interesse mas nenhuma conversão
                if ($car->views_count >= 150 && $car->interactions_count === 0 && $car->leads_count === 0) {
                    $suggestions = [
                        'Rever preço',
                        'Melhorar fotos',
                        'Reforçar copy'
                    ];
                }

                // Pessoas clicam mas não geram lead
                elseif ($car->interactions_count > 5 && $car->leads_count === 0) {
                    $suggestions = [
                        'Melhorar CTA',
                        'Facilitar contacto',
                        'Rever descrição'
                    ];
                }

                // Interesse baixo para o volume de views
                elseif ($interactionRate < 0.02) {
                    $suggestions = [
                        'Melhorar fotos',
                        'Rever título do anúncio'
                    ];
                }

                if (empty($suggestions)) {
                    return null;
                }

                $car->interest_rate = $interestRate;
                $car->interaction_rate = round($interactionRate * 100, 2);
                $car->lead_rate = round($leadRate * 100, 2);
                $car->suggestions = $suggestions;

                return $car;
            })
            ->filter()
            ->sortByDesc('views_count')
            ->take($limit)
            ->values();
    }

    public function getMarketingPerformance(int $companyId): array
    {
        $since = now()->subDays(7);

        $views = DB::table('car_views')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->count();

        $leads = DB::table('car_leads')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->count();

        $interactions = DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->count();

        $interestRate = $views > 0
            ? round((($interactions + $leads) / $views) * 100, 2)
            : 0;

        $rawSources = DB::table('car_views')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->select(
                'channel',
                'utm_source',
                'utm_medium',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('channel', 'utm_source', 'utm_medium')
            ->get();

        $distribution = [
            'meta_ads' => 0,
            'google' => 0,
            'organic' => 0,
        ];

        $totalTraffic = $rawSources->sum('total');

        foreach ($rawSources as $source) {
            if ($totalTraffic === 0) {
                continue;
            }

            $bucket = 'organic';

            $utmSource = strtolower($source->utm_source ?? '');
            $utmMedium = strtolower($source->utm_medium ?? '');
            $channel   = strtolower($source->channel ?? '');

            if (
                in_array($utmSource, ['facebook', 'instagram', 'meta'], true) ||
                in_array($utmMedium, ['paid_social', 'cpc', 'ppc'], true) ||
                $channel === 'paid'
            ) {
                $bucket = 'meta_ads';
            } elseif (
                $utmSource === 'google' ||
                in_array($utmMedium, ['search', 'paid_search', 'google_ads'], true) ||
                $channel === 'organic_search'
            ) {
                $bucket = 'google';
            }

            $distribution[$bucket] += $source->total;
        }

        $distribution = [
            'meta_ads' => $totalTraffic > 0 ? round(($distribution['meta_ads'] / $totalTraffic) * 100, 1) : 0,
            'google'   => $totalTraffic > 0 ? round(($distribution['google'] / $totalTraffic) * 100, 1) : 0,
            'organic'  => $totalTraffic > 0 ? round(($distribution['organic'] / $totalTraffic) * 100, 1) : 0,
        ];

        return [
            'views_last_7_days' => $views,
            'leads_last_7_days' => $leads,
            'interactions_last_7_days' => $interactions,
            'interest_rate' => $interestRate,
            'traffic_distribution' => $distribution,
        ];
    }

    public function getCompanyInsights(int $companyId): array
    {
        $since = now()->subDays(7);

        $insights = [];

        // ---------------------------------------------------------
        // 1. Segment performance
        // ---------------------------------------------------------
        $segmentData = DB::table('cars')
            ->leftJoin('car_views', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_views.car_id')
                    ->where('car_views.created_at', '>=', $since);
            })
            ->leftJoin('car_leads', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_leads.car_id')
                    ->where('car_leads.created_at', '>=', $since);
            })
            ->where('cars.company_id', $companyId)
            ->where('cars.status', 'active')
            ->whereNotNull('cars.segment')
            ->groupBy('cars.segment')
            ->selectRaw('
            cars.segment as label,
            COUNT(DISTINCT car_views.id) as views_count,
            COUNT(DISTINCT car_leads.id) as leads_count
        ')
            ->get()
            ->map(function ($item) {
                $item->conversion_rate = $item->views_count > 0
                    ? $item->leads_count / $item->views_count
                    : 0;

                return $item;
            })
            ->sortByDesc('leads_count')
            ->values();

        $totalSegmentLeads = $segmentData->sum('leads_count');

        if ($segmentData->count() > 0 && $totalSegmentLeads > 0) {
            $topSegment = $segmentData->first();
            $segmentLeadShare = round(($topSegment->leads_count / $totalSegmentLeads) * 100, 1);

            $insights[] = [
                'type' => 'segment_performance',
                'title' => 'Segmento com melhor performance',
                'text' => strtoupper((string) $topSegment->label) . " gera {$segmentLeadShare}% das leads nos últimos 7 dias.",
                'meta' => [
                    'label' => $topSegment->label,
                    'views_count' => (int) $topSegment->views_count,
                    'leads_count' => (int) $topSegment->leads_count,
                    'conversion_rate' => round($topSegment->conversion_rate * 100, 2),
                ],
            ];
        }

        // ---------------------------------------------------------
        // 2. Fuel performance
        // ---------------------------------------------------------
        $fuelData = DB::table('cars')
            ->leftJoin('car_views', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_views.car_id')
                    ->where('car_views.created_at', '>=', $since);
            })
            ->leftJoin('car_leads', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_leads.car_id')
                    ->where('car_leads.created_at', '>=', $since);
            })
            ->where('cars.company_id', $companyId)
            ->where('cars.status', 'active')
            ->whereNotNull('cars.fuel_type')
            ->groupBy('cars.fuel_type')
            ->selectRaw('
            cars.fuel_type as label,
            COUNT(DISTINCT car_views.id) as views_count,
            COUNT(DISTINCT car_leads.id) as leads_count
        ')
            ->get()
            ->map(function ($item) {
                $item->conversion_rate = $item->views_count > 0
                    ? $item->leads_count / $item->views_count
                    : 0;

                return $item;
            })
            ->sortByDesc('conversion_rate')
            ->values();

        if ($fuelData->count() >= 2) {
            $bestFuel = $fuelData->first();
            $secondFuel = $fuelData->skip(1)->first();

            if ($bestFuel && $secondFuel && $bestFuel->conversion_rate > 0 && $secondFuel->conversion_rate > 0) {
                $multiplier = round($bestFuel->conversion_rate / $secondFuel->conversion_rate, 1);

                $insights[] = [
                    'type' => 'fuel_performance',
                    'title' => 'Combustível com melhor conversão',
                    'text' => strtoupper((string) $bestFuel->label) . " converte {$multiplier}x melhor que " . strtoupper((string) $secondFuel->label) . " nos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestFuel->label,
                        'best_conversion_rate' => round($bestFuel->conversion_rate * 100, 2),
                        'second_label' => $secondFuel->label,
                        'second_conversion_rate' => round($secondFuel->conversion_rate * 100, 2),
                    ],
                ];
            }
        }

        // ---------------------------------------------------------
        // 3. Brand performance
        // ---------------------------------------------------------
        $brandData = DB::table('cars')
            ->join('car_brands', 'cars.car_brand_id', '=', 'car_brands.id')
            ->leftJoin('car_views', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_views.car_id')
                    ->where('car_views.created_at', '>=', $since);
            })
            ->leftJoin('car_leads', function ($join) use ($since) {
                $join->on('cars.id', '=', 'car_leads.car_id')
                    ->where('car_leads.created_at', '>=', $since);
            })
            ->where('cars.company_id', $companyId)
            ->where('cars.status', 'active')
            ->groupBy('car_brands.name')
            ->selectRaw('
            car_brands.name as label,
            COUNT(DISTINCT car_views.id) as views_count,
            COUNT(DISTINCT car_leads.id) as leads_count
        ')
            ->get()
            ->map(function ($item) {
                $item->conversion_rate = $item->views_count > 0
                    ? $item->leads_count / $item->views_count
                    : 0;

                return $item;
            })
            ->sortByDesc('conversion_rate')
            ->values();

        if ($brandData->count() >= 2) {
            $bestBrand = $brandData->first();
            $secondBrand = $brandData->skip(1)->first();

            if ($bestBrand && $secondBrand && $bestBrand->conversion_rate > 0 && $secondBrand->conversion_rate >= 0) {
                $insights[] = [
                    'type' => 'brand_performance',
                    'title' => 'Marca com melhor conversão',
                    'text' => "{$bestBrand->label} converte melhor que {$secondBrand->label} no stock dos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestBrand->label,
                        'best_conversion_rate' => round($bestBrand->conversion_rate * 100, 2),
                        'second_label' => $secondBrand->label,
                        'second_conversion_rate' => round($secondBrand->conversion_rate * 100, 2),
                    ],
                ];
            }
        }

        return $insights;
    }
}
