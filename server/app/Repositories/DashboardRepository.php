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
                'version',
                'price_gross',
                'created_at'
            ])
            ->with([
                'brand:id,name',
                'model:id,name'
            ])
            ->withCount([
                'views',
                'leads',
                'interactions',
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
                'version',
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
                'version',
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
                'version',
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
                'version',
                'price_gross',
                'promo_price_gross',
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

                $car->car_name = "{$car->brand_name} {$car->model_name} {$car->version}";
                $car->reason = "Capital parado há {$car->days_in_stock} dias";
                $car->suggestion = "Rever preço ou destacar anúncio";
                $car->priority = 3;

                return $car;
            });
    }

    public function getUrgentActionCars(int $companyId, int $limit = 5)
    {
        $cars = $this->model->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'version',
                'price_gross',
                'promo_price_gross',
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
                'interactions'
            ])
            ->get();

        if ($cars->isEmpty()) {
            return collect();
        }

        $avgViews = $cars->avg('views_count');
        $avgInteractions = $cars->avg('interactions_count');

        return $cars->map(function ($car) use ($avgViews, $avgInteractions) {

            $car->brand_name = $car->brand?->name;
            $car->model_name = $car->model?->name;
            $car->car_name = "{$car->brand_name} {$car->model_name} {$car->version}";

            $reason = null;
            $suggestion = null;
            $priority = 0;

            // 🔥 Alta procura sem conversão
            if ($car->views_count > ($avgViews * 1.6) && $car->leads_count == 0) {

                $reason = "Alta procura sem conversão";
                $suggestion = "Rever preço e melhorar fotos do anúncio";
                $priority = 3;
            }

            // ⚠ Interesse mas sem contacto
            elseif ($car->interactions_count > ($avgInteractions * 1.5) && $car->leads_count == 0) {

                $reason = "Interesse elevado sem contacto";
                $suggestion = "Melhorar CTA e destacar contacto WhatsApp";
                $priority = 2;
            }

            // 📉 Baixa visibilidade
            elseif ($car->views_count < ($avgViews * 0.5) && $car->days_in_stock > 7) {

                $reason = "Baixa visibilidade";
                $suggestion = "Promover anúncio ou publicar nas redes sociais";
                $priority = 1;
            }

            // ⏳ Inventário parado
            elseif ($car->days_in_stock > 60) {

                $reason = "Inventário parado";
                $suggestion = "Rever preço ou criar campanha de destaque";
                $priority = 3;
            }

            if (!$reason) {
                return null;
            }

            $car->reason = $reason;
            $car->suggestion = $suggestion;
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
                'version',
                'price_gross',
                'promo_price_gross',
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

                $demandScore =
                    ($car->views_count * 1)
                    + ($car->interactions_count * 4)
                    + ($car->leads_count * 8);

                if ($car->views_count < 50) {
                    return null;
                }

                $suggestion = 'Monitorizar, carro com procura saudável';

                if ($car->views_count >= 150 && $car->leads_count === 0) {
                    $suggestion = 'Alta procura sem conversão: rever preço ou melhorar anúncio';
                } elseif ($car->views_count >= 150 && $car->leads_count >= 2) {
                    $suggestion = 'Aumentar orçamento e destacar anúncio';
                } elseif ($car->interactions_count >= 10 && $car->leads_count === 0) {
                    $suggestion = 'Interesse alto sem leads: melhorar CTA e contacto';
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
                'version',
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
        $fromDate = $since->toDateString();
        $toDate = now()->toDateString();

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

        $channelSignals = collect();

        $viewChannels = DB::table('car_views')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw("COALESCE(channel, 'direct') as channel"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('channel')
            ->get();

        $leadChannels = DB::table('car_leads')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw("COALESCE(channel, 'direct') as channel"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('channel')
            ->get();

        $interactionChannels = DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw("COALESCE(channel, 'direct') as channel"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('channel')
            ->get();

        $metaSignals = DB::table('meta_audience_insights')
            ->where('company_id', $companyId)
            ->whereDate('period_start', '<=', $toDate)
            ->whereDate('period_end', '>=', $fromDate)
            ->selectRaw('
                COALESCE(SUM(clicks), 0) as total_clicks,
                COALESCE(SUM(reach), 0) as total_reach,
                COALESCE(SUM(spend), 0) as total_spend
            ')
            ->first();

        $signalSources = [
            ['rows' => $viewChannels, 'weight' => 1],
            ['rows' => $interactionChannels, 'weight' => 2],
            ['rows' => $leadChannels, 'weight' => 3],
        ];

        foreach ($signalSources as $source) {
            foreach ($source['rows'] as $row) {
                $channel = strtolower((string) ($row->channel ?? 'direct'));
                $channelSignals[$channel] = ($channelSignals[$channel] ?? 0) + ((int) $row->total * $source['weight']);
            }
        }

        $metaClicks = (int) ($metaSignals->total_clicks ?? 0);
        $metaSpend = (float) ($metaSignals->total_spend ?? 0);

        if ($metaClicks > 0) {
            $channelSignals['paid'] = ($channelSignals['paid'] ?? 0) + $metaClicks;
        } elseif ($metaSpend > 0) {
            $channelSignals['paid'] = ($channelSignals['paid'] ?? 0) + 1;
        }

        $totalSignals = $channelSignals->sum();

        $distribution = $channelSignals
            ->sortDesc()
            ->map(function (int $total, string $channel) use ($totalSignals) {
                return [
                    'channel' => $channel,
                    'label' => $this->normalizeMarketingChannelLabel($channel),
                    'count' => $total,
                    'percentage' => $totalSignals > 0 ? round(($total / $totalSignals) * 100, 1) : 0,
                ];
            })
            ->values()
            ->all();

        return [
            'views_last_7_days' => $views,
            'leads_last_7_days' => $leads,
            'interactions_last_7_days' => $interactions,
            'interest_rate' => $interestRate,
            'meta_clicks_last_7_days' => $metaClicks,
            'meta_reach_last_7_days' => (int) ($metaSignals->total_reach ?? 0),
            'meta_spend_last_7_days' => round($metaSpend, 2),
            'traffic_distribution' => $distribution,
        ];
    }

    private function normalizeMarketingChannelLabel(string $channel): string
    {
        return match ($channel) {
            'paid' => 'Trafego pago',
            'organic_search' => 'Pesquisa organica',
            'organic_social' => 'Social organico',
            'direct' => 'Direto',
            'referral' => 'Referral',
            'email' => 'Email',
            'utm' => 'UTM',
            default => ucfirst(str_replace('_', ' ', $channel)),
        };
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
        $totalSegmentViews = $segmentData->sum('views_count');

        if ($segmentData->count() > 0 && ($totalSegmentLeads > 0 || $totalSegmentViews > 0)) {
            $topSegment = $segmentData->first();
            $segmentLeadShare = $totalSegmentLeads > 0
                ? round(($topSegment->leads_count / $totalSegmentLeads) * 100, 1)
                : 0;
            $segmentViewShare = $totalSegmentViews > 0
                ? round(($topSegment->views_count / $totalSegmentViews) * 100, 1)
                : 0;

            $insights[] = [
                'type' => 'segment_performance',
                'title' => 'Segmento com melhor performance',
                'text' => $totalSegmentLeads > 0
                    ? strtoupper((string) $topSegment->label) . " gera {$segmentLeadShare}% das leads nos últimos 7 dias."
                    : strtoupper((string) $topSegment->label) . " concentra {$segmentViewShare}% das views nos últimos 7 dias.",
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

            if ($bestFuel && $secondFuel) {
                $multiplier = $secondFuel->conversion_rate > 0
                    ? round($bestFuel->conversion_rate / $secondFuel->conversion_rate, 1)
                    : null;

                $insights[] = [
                    'type' => 'fuel_performance',
                    'title' => 'Combustível com melhor conversão',
                    'text' => $multiplier && $bestFuel->conversion_rate > 0
                        ? strtoupper((string) $bestFuel->label) . " converte {$multiplier}x melhor que " . strtoupper((string) $secondFuel->label) . " nos últimos 7 dias."
                        : strtoupper((string) $bestFuel->label) . " lidera a conversão e engagement por combustível nos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestFuel->label,
                        'best_views_count' => (int) $bestFuel->views_count,
                        'best_leads_count' => (int) $bestFuel->leads_count,
                        'best_conversion_rate' => round($bestFuel->conversion_rate * 100, 2),
                        'second_label' => $secondFuel->label,
                        'second_views_count' => (int) $secondFuel->views_count,
                        'second_leads_count' => (int) $secondFuel->leads_count,
                        'second_conversion_rate' => round($secondFuel->conversion_rate * 100, 2),
                    ],
                ];
            }
        } elseif ($fuelData->count() === 1) {
            $bestFuel = $fuelData->first();

            if ($bestFuel) {
                $insights[] = [
                    'type' => 'fuel_performance',
                    'title' => 'Combustível em destaque',
                    'text' => strtoupper((string) $bestFuel->label) . " concentra a atividade do stock nos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestFuel->label,
                        'best_views_count' => (int) $bestFuel->views_count,
                        'best_leads_count' => (int) $bestFuel->leads_count,
                        'best_conversion_rate' => round($bestFuel->conversion_rate * 100, 2),
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

            if ($bestBrand && $secondBrand) {
                $insights[] = [
                    'type' => 'brand_performance',
                    'title' => 'Marca com melhor conversão',
                    'text' => $bestBrand->conversion_rate > 0
                        ? "{$bestBrand->label} converte melhor que {$secondBrand->label} no stock dos últimos 7 dias."
                        : "{$bestBrand->label} está a liderar o interesse entre as marcas do stock nos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestBrand->label,
                        'best_views_count' => (int) $bestBrand->views_count,
                        'best_leads_count' => (int) $bestBrand->leads_count,
                        'best_conversion_rate' => round($bestBrand->conversion_rate * 100, 2),
                        'second_label' => $secondBrand->label,
                        'second_views_count' => (int) $secondBrand->views_count,
                        'second_leads_count' => (int) $secondBrand->leads_count,
                        'second_conversion_rate' => round($secondBrand->conversion_rate * 100, 2),
                    ],
                ];
            }
        } elseif ($brandData->count() === 1) {
            $bestBrand = $brandData->first();

            if ($bestBrand) {
                $insights[] = [
                    'type' => 'brand_performance',
                    'title' => 'Marca em destaque',
                    'text' => "{$bestBrand->label} concentra o maior volume de interesse do stock nos últimos 7 dias.",
                    'meta' => [
                        'best_label' => $bestBrand->label,
                        'best_views_count' => (int) $bestBrand->views_count,
                        'best_leads_count' => (int) $bestBrand->leads_count,
                        'best_conversion_rate' => round($bestBrand->conversion_rate * 100, 2),
                    ],
                ];
            }
        }

        return $insights;
    }
}
