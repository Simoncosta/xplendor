<?php

namespace App\Services;

use App\Models\Car;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarFunnelAnalyzer
{
    private const DEFAULT_WINDOW_DAYS = 7;

    private const THRESHOLDS = [
        'car' => [
            'delivery' => [
                'good_impressions' => 1500,
                'warning_impressions' => 700,
                'bad_impressions' => 300,
                'high_cpm' => 12.0,
                'warning_cpm' => 8.0,
                'mature_spend' => 12.0,
            ],
            'click' => [
                'good_ctr' => 1.5,
                'warning_ctr' => 0.9,
                'bad_ctr' => 0.6,
                'high_cpc' => 1.20,
                'warning_cpc' => 0.75,
                'mature_impressions' => 1200,
            ],
            'intent' => [
                'good_views' => 30,
                'warning_views' => 15,
                'good_time_on_page' => 45.0,
                'warning_time_on_page' => 25.0,
                'good_scroll' => 55.0,
                'warning_scroll' => 35.0,
            ],
            'conversion' => [
                'mature_views' => 30,
                'mature_clicks' => 40,
                'mature_spend' => 25.0,
                'warning_form_opens' => 3,
            ],
        ],
        'motorhome' => [
            'delivery' => [
                'good_impressions' => 1800,
                'warning_impressions' => 800,
                'bad_impressions' => 350,
                'high_cpm' => 14.0,
                'warning_cpm' => 9.5,
                'mature_spend' => 16.0,
            ],
            'click' => [
                'good_ctr' => 1.2,
                'warning_ctr' => 0.7,
                'bad_ctr' => 0.45,
                'high_cpc' => 1.50,
                'warning_cpc' => 0.95,
                'mature_impressions' => 1500,
            ],
            'intent' => [
                'good_views' => 40,
                'warning_views' => 20,
                'good_time_on_page' => 70.0,
                'warning_time_on_page' => 40.0,
                'good_scroll' => 65.0,
                'warning_scroll' => 45.0,
            ],
            'conversion' => [
                'mature_views' => 55,
                'mature_clicks' => 60,
                'mature_spend' => 45.0,
                'warning_form_opens' => 4,
            ],
        ],
    ];

    public function analyzeForCar(Car $car, ?string $from = null, ?string $to = null): array
    {
        $to ??= now()->toDateString();
        $from ??= now()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->toDateString();

        $metrics = $this->loadMetricsForCars(collect([$car]), $from, $to)[$car->id] ?? $this->emptyMetrics();
        $context = [
            'vehicle_type' => $car->vehicle_type ?: 'car',
            'price' => $car->price_gross !== null ? (float) $car->price_gross : null,
            'segment' => $car->segment,
            'days_in_stock' => $car->created_at ? (int) $car->created_at->diffInDays(now()) : null,
        ];

        return [
            'car_id' => $car->id,
            'company_id' => $car->company_id,
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'metrics' => $metrics,
            'phases' => $this->analyzeFunnel($metrics, $context),
        ];
    }

    public function analyzeForCars(Collection $cars, ?string $from = null, ?string $to = null): array
    {
        $to ??= now()->toDateString();
        $from ??= now()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->toDateString();

        if ($cars->isEmpty()) {
            return [];
        }

        $metricsByCarId = $this->loadMetricsForCars($cars, $from, $to);

        return $cars->mapWithKeys(function (Car $car) use ($from, $to, $metricsByCarId) {
            $metrics = $metricsByCarId[$car->id] ?? $this->emptyMetrics();
            $context = [
                'vehicle_type' => $car->vehicle_type ?: 'car',
                'price' => $car->price_gross !== null ? (float) $car->price_gross : null,
                'segment' => $car->segment,
                'days_in_stock' => $car->created_at ? (int) $car->created_at->diffInDays(now()) : null,
            ];

            return [
                $car->id => [
                    'car_id' => $car->id,
                    'company_id' => $car->company_id,
                    'period' => [
                        'from' => $from,
                        'to' => $to,
                    ],
                    'metrics' => $metrics,
                    'phases' => $this->analyzeFunnel($metrics, $context),
                ],
            ];
        })->all();
    }

    public function analyzeFunnel(array $metrics, array $context = []): array
    {
        $vehicleType = $this->resolveVehicleType($context);
        $thresholds = $this->resolveThresholds($vehicleType);

        return [
            'delivery' => $this->analyzeDelivery($metrics, $context, $thresholds['delivery']),
            'click' => $this->analyzeClick($metrics, $context, $thresholds['click']),
            'intent' => $this->analyzeIntent($metrics, $context, $thresholds['intent']),
            'conversion' => $this->analyzeConversion($metrics, $context, $thresholds['conversion']),
        ];
    }

    private function loadMetricsForCars(Collection $cars, string $from, string $to): array
    {
        $companyId = (int) $cars->first()->company_id;
        $carIds = $cars->pluck('id')->map(fn ($id) => (int) $id)->all();

        $campaign = DB::table('campaign_car_metrics_daily')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                car_id,
                COALESCE(SUM(impressions), 0) as impressions,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(spend_normalized), 0) as spend_normalized
            ')
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $funnel = DB::table('car_funnel_metrics_daily')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                car_id,
                COALESCE(SUM(sessions), 0) as sessions,
                COALESCE(SUM(views), 0) as views,
                CASE
                    WHEN COALESCE(SUM(views), 0) > 0
                        THEN ROUND(SUM(COALESCE(avg_time_on_page, 0) * views) / SUM(views), 2)
                    ELSE NULL
                END as avg_time_on_page,
                CASE
                    WHEN COALESCE(SUM(views), 0) > 0
                        THEN ROUND(SUM(COALESCE(scroll, 0) * views) / SUM(views), 2)
                    ELSE NULL
                END as scroll,
                COALESCE(SUM(whatsapp_clicks), 0) as whatsapp_clicks,
                COALESCE(SUM(form_opens), 0) as form_opens,
                COALESCE(SUM(leads), 0) as leads
            ')
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        return collect($carIds)->mapWithKeys(function (int $carId) use ($campaign, $funnel) {
            $campaignMetrics = $campaign->get($carId);
            $funnelMetrics = $funnel->get($carId);

            $impressions = (int) ($campaignMetrics->impressions ?? 0);
            $clicks = (int) ($campaignMetrics->clicks ?? 0);
            $spend = round((float) ($campaignMetrics->spend_normalized ?? 0), 2);

            return [
                $carId => [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'spend_normalized' => $spend,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                    'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
                    'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
                    'sessions' => (int) ($funnelMetrics->sessions ?? 0),
                    'views' => (int) ($funnelMetrics->views ?? 0),
                    'avg_time_on_page' => $funnelMetrics?->avg_time_on_page !== null ? round((float) $funnelMetrics->avg_time_on_page, 2) : null,
                    'scroll' => $funnelMetrics?->scroll !== null ? round((float) $funnelMetrics->scroll, 2) : null,
                    'whatsapp_clicks' => (int) ($funnelMetrics->whatsapp_clicks ?? 0),
                    'form_opens' => (int) ($funnelMetrics->form_opens ?? 0),
                    'leads' => (int) ($funnelMetrics->leads ?? 0),
                ],
            ];
        })->all();
    }

    private function emptyMetrics(): array
    {
        return [
            'impressions' => 0,
            'clicks' => 0,
            'spend_normalized' => 0.0,
            'ctr' => null,
            'cpc' => null,
            'cpm' => null,
            'sessions' => 0,
            'views' => 0,
            'avg_time_on_page' => null,
            'scroll' => null,
            'whatsapp_clicks' => 0,
            'form_opens' => 0,
            'leads' => 0,
        ];
    }

    private function analyzeDelivery(array $metrics, array $context, array $thresholds): array
    {
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $spend = (float) ($metrics['spend_normalized'] ?? 0);
        $cpm = $metrics['cpm'] !== null ? (float) $metrics['cpm'] : null;
        $vehicleType = $this->resolveVehicleType($context);

        $state = 'good';
        $diagnosis = $vehicleType === 'motorhome'
            ? 'Entrega saudável para um produto de decisão longa'
            : 'Entrega saudável';

        if ($impressions <= $thresholds['bad_impressions'] && $spend >= $thresholds['mature_spend']) {
            $state = 'bad';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Entrega ainda fraca para gerar aprendizagem suficiente'
                : 'Entrega fraca para o investimento atual';
        } elseif ($impressions < $thresholds['warning_impressions']) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Volume de entrega ainda curto para um ciclo de decisão mais longo'
                : 'Volume de entrega ainda curto';
        } elseif ($cpm !== null && $cpm >= $thresholds['high_cpm']) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Entrega cara para a fase atual do funil'
                : 'Entrega cara para o volume gerado';
        }

        return [
            'phase' => 'delivery',
            'state' => $state,
            'diagnosis' => $diagnosis,
            'metrics' => [
                'impressions' => $impressions,
                'spend_normalized' => round($spend, 2),
                'cpm' => $cpm,
            ],
        ];
    }

    private function analyzeClick(array $metrics, array $context, array $thresholds): array
    {
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);
        $ctr = $metrics['ctr'] !== null ? (float) $metrics['ctr'] : null;
        $cpc = $metrics['cpc'] !== null ? (float) $metrics['cpc'] : null;
        $vehicleType = $this->resolveVehicleType($context);

        $state = 'good';
        $diagnosis = $vehicleType === 'motorhome'
            ? 'Clique saudável para uma compra mais ponderada'
            : 'CTR saudável e clique eficiente';

        if ($impressions >= $thresholds['mature_impressions'] && $clicks === 0) {
            $state = 'bad';
            $diagnosis = 'Entrega sem clique relevante';
        } elseif ($ctr !== null && $ctr < $thresholds['bad_ctr']) {
            $state = 'bad';
            $diagnosis = 'CTR fraco';
        } elseif ($ctr !== null && $ctr < $thresholds['warning_ctr']) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'CTR abaixo do esperado para gerar descoberta qualificada'
                : 'CTR abaixo do esperado';
        } elseif ($cpc !== null && $cpc > $thresholds['high_cpc']) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Clique caro para um funil ainda em fase de exploração'
                : 'Clique caro para o retorno atual';
        }

        return [
            'phase' => 'click',
            'state' => $state,
            'diagnosis' => $diagnosis,
            'metrics' => [
                'clicks' => $clicks,
                'ctr' => $ctr,
                'cpc' => $cpc,
            ],
        ];
    }

    private function analyzeIntent(array $metrics, array $context, array $thresholds): array
    {
        $views = (int) ($metrics['views'] ?? 0);
        $avgTimeOnPage = $metrics['avg_time_on_page'] !== null ? (float) $metrics['avg_time_on_page'] : null;
        $scroll = $metrics['scroll'] !== null ? (float) $metrics['scroll'] : null;
        $whatsappClicks = (int) ($metrics['whatsapp_clicks'] ?? 0);
        $formOpens = (int) ($metrics['form_opens'] ?? 0);
        $leads = (int) ($metrics['leads'] ?? 0);
        $vehicleType = $this->resolveVehicleType($context);

        $state = 'good';
        $diagnosis = $vehicleType === 'motorhome'
            ? 'Há sinais de exploração qualificada do produto'
            : 'Há sinais de interesse qualificado';

        if (
            $views >= $thresholds['good_views']
            && $leads === 0
            && ($whatsappClicks + $formOpens) === 0
        ) {
            $state = $vehicleType === 'motorhome' ? 'good' : 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Consumo de conteúdo alto sem conversão imediata, comportamento esperado em motorhome'
                : 'LPV alto sem progressão para intenção';
        } elseif (
            $views >= $thresholds['warning_views']
            && (($avgTimeOnPage !== null && $avgTimeOnPage < $thresholds['warning_time_on_page'])
                || ($scroll !== null && $scroll < $thresholds['warning_scroll']))
        ) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Há curiosidade, mas o envolvimento ainda é superficial para uma decisão longa'
                : 'Interesse superficial na página';
        } elseif (
            $views < $thresholds['warning_views']
            && ($whatsappClicks + $formOpens) === 0
        ) {
            $state = 'bad';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Poucos sinais de exploração qualificada no site'
                : 'Poucos sinais de intenção real no site';
        }

        return [
            'phase' => 'intent',
            'state' => $state,
            'diagnosis' => $diagnosis,
            'metrics' => [
                'views' => $views,
                'avg_time_on_page' => $avgTimeOnPage,
                'scroll' => $scroll,
                'whatsapp_clicks' => $whatsappClicks,
                'form_opens' => $formOpens,
            ],
        ];
    }

    private function analyzeConversion(array $metrics, array $context, array $thresholds): array
    {
        $leads = (int) ($metrics['leads'] ?? 0);
        $whatsappClicks = (int) ($metrics['whatsapp_clicks'] ?? 0);
        $formOpens = (int) ($metrics['form_opens'] ?? 0);
        $views = (int) ($metrics['views'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);
        $spend = (float) ($metrics['spend_normalized'] ?? 0);
        $vehicleType = $this->resolveVehicleType($context);
        $daysInStock = (int) ($context['days_in_stock'] ?? 0);

        $state = 'good';
        $diagnosis = 'Já existem sinais de conversão real';

        if ($leads > 0) {
            return [
                'phase' => 'conversion',
                'state' => $state,
                'diagnosis' => $diagnosis,
                'metrics' => [
                    'leads' => $leads,
                    'whatsapp_clicks' => $whatsappClicks,
                ],
            ];
        }

        if ($whatsappClicks > 0) {
            $state = $vehicleType === 'motorhome' ? 'warning' : 'bad';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Há interesse inicial, mas conversão ainda não aconteceu — comportamento esperado para produto de decisão longa'
                : 'Há intenção mas sem lead real';
        } elseif ($formOpens >= $thresholds['warning_form_opens']) {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Há abertura de formulário, mas a conversão ainda está em maturação'
                : 'Há abertura de formulário sem conversão';
        } elseif (
            $views >= $thresholds['mature_views']
            || $clicks >= $thresholds['mature_clicks']
            || $spend >= $thresholds['mature_spend']
        ) {
            $state = $vehicleType === 'motorhome' && $daysInStock <= 90 ? 'warning' : 'bad';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Existe aprendizagem, mas a conversão ainda não amadureceu para um produto de decisão longa'
                : 'Campanha madura sem conversão';
        } else {
            $state = 'warning';
            $diagnosis = $vehicleType === 'motorhome'
                ? 'Ainda sem sinal de conversão suficiente, mas dentro de um comportamento inicial plausível'
                : 'Ainda sem sinal de conversão suficiente';
        }

        return [
            'phase' => 'conversion',
            'state' => $state,
            'diagnosis' => $diagnosis,
            'metrics' => [
                'leads' => $leads,
                'whatsapp_clicks' => $whatsappClicks,
            ],
        ];
    }

    private function resolveVehicleType(array $context): string
    {
        return ($context['vehicle_type'] ?? 'car') === 'motorhome'
            ? 'motorhome'
            : 'car';
    }

    private function resolveThresholds(string $vehicleType): array
    {
        return self::THRESHOLDS[$vehicleType] ?? self::THRESHOLDS['car'];
    }
}
