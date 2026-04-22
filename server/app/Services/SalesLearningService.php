<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarSalesLearning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SalesLearningService
{
    public function __construct(
        protected CarFunnelAnalyzer $carFunnelAnalyzer,
        protected IntentAnalysisService $intentAnalysisService,
        protected LeadRealityGapService $leadRealityGapService,
        protected AttributionService $attributionService,
        protected ContactSignalService $contactSignalService,
    ) {}

    public function captureSaleSnapshot(Car $car, array $saleContext = []): CarSalesLearning
    {
        $soldAt = Carbon::parse($saleContext['sold_at'] ?? $car->sold_at ?? now());
        $from = $soldAt->copy()->subDays(7);

        $recentMetrics = $this->recentMetrics($car, $from, $soldAt);
        $funnelAnalysis = $this->withRecentMetricFallback($this->safeFunnelAnalysis($car, $from, $soldAt, $recentMetrics), $recentMetrics);
        $intentAnalysis = $this->withRecentIntentFallback($this->safeIntentAnalysis($car, $from, $soldAt, $recentMetrics), $recentMetrics);
        $leadRealityGap = $this->safeLeadRealityGap($car, $funnelAnalysis, $intentAnalysis, $from, $soldAt);
        $attributionSummary = $this->attributionService->getAttributionSummary($car->id);
        $contactSignal = $this->contactSignalService->calculate($car, $funnelAnalysis, $intentAnalysis, $leadRealityGap, $attributionSummary);
        $campaignContext = $this->campaignContext($car, $soldAt);
        $dailySignals = $this->dailyContactSignals($car, $from, $soldAt);
        $peakSignal = $this->peakContactSignal($dailySignals);
        $contactSignalScore = max((int) ($contactSignal['score'] ?? 0), $this->recentContactSignalFloor($recentMetrics));
        $daysInStock = $car->created_at ? (int) $car->created_at->diffInDays($soldAt) : null;
        $priceAtSale = $car->promo_price_gross && $car->price_gross && $car->promo_price_gross < $car->price_gross
            ? $car->promo_price_gross
            : $car->price_gross;
        $priceVsBenchmark = $this->priceVsBenchmark($car);

        $snapshot = CarSalesLearning::query()->updateOrCreate(
            [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'sold_at' => $soldAt,
            ],
            [
                'sale_price' => $saleContext['sale_price'] ?? null,
                'buyer_age' => $saleContext['buyer_age'] ?? null,
                'buyer_gender' => $saleContext['buyer_gender'] ?? null,
                'contact_signal_score' => $contactSignalScore,
                'contact_signal_level' => $contactSignal['level'] ?? $this->levelFromScore($contactSignalScore),
                'peak_contact_signal_last_7d' => $peakSignal['score'],
                'peak_contact_signal_at' => $peakSignal['date'],
                'contact_signal_trend' => $this->contactSignalTrend($dailySignals),
                'sessions_last_7d' => $recentMetrics['sessions'],
                'views_last_7d' => $recentMetrics['views'],
                'whatsapp_clicks_last_7d' => $recentMetrics['whatsapp_clicks'],
                'leads_last_7d' => $recentMetrics['leads'],
                'primary_contact_channel' => $this->primaryContactChannel($recentMetrics),
                'campaign_ids' => $campaignContext['campaign_ids'],
                'ad_ids' => $campaignContext['ad_ids'],
                'adset_ids' => $campaignContext['adset_ids'],
                'price_at_sale' => $priceAtSale,
                'days_in_stock' => $daysInStock,
                'sale_quality_score' => $this->saleQualityScore($contactSignalScore, $daysInStock, $priceVsBenchmark),
            ]
        );

        Log::info('[Sales Learning] Snapshot captured', [
            'company_id' => $car->company_id,
            'car_id' => $car->id,
            'sold_at' => $soldAt->toDateTimeString(),
            'contact_signal_score' => $contactSignalScore,
        ]);

        return $snapshot;
    }

    public function summaryForCar(Car $car): array
    {
        $snapshot = CarSalesLearning::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->orderByDesc('sold_at')
            ->first();

        return [
            'has_sale_snapshot' => $snapshot !== null,
            'sold_at' => $snapshot?->sold_at?->toDateTimeString() ?? $car->sold_at?->toDateTimeString(),
            'sale_price' => $snapshot?->sale_price !== null ? (float) $snapshot->sale_price : null,
            'buyer_age' => $snapshot?->buyer_age,
            'buyer_gender' => $snapshot?->buyer_gender,
            'contact_signal_score' => $snapshot?->contact_signal_score,
            'contact_signal_level' => $snapshot?->contact_signal_level,
            'primary_contact_channel' => $snapshot?->primary_contact_channel,
            'sale_quality_score' => $snapshot?->sale_quality_score,
        ];
    }

    private function recentMetrics(Car $car, Carbon $from, Carbon $soldAt): array
    {
        $views = DB::table('car_views')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('created_at', [$from, $soldAt])
            ->count();

        $interactions = DB::table('car_interactions')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('created_at', [$from, $soldAt]);

        return [
            'views' => (int) $views,
            'sessions' => (int) (clone $interactions)->distinct('session_id')->count('session_id'),
            'whatsapp_clicks' => (int) (clone $interactions)->where('interaction_type', 'whatsapp_click')->count(),
            'calls' => (int) (clone $interactions)->where('interaction_type', 'call_click')->count(),
            'forms' => (int) (clone $interactions)->where('interaction_type', 'form_open')->count(),
            'leads' => (int) DB::table('car_leads')
                ->where('company_id', $car->company_id)
                ->where('car_id', $car->id)
                ->whereBetween('created_at', [$from, $soldAt])
                ->count(),
        ];
    }

    private function dailyContactSignals(Car $car, Carbon $from, Carbon $soldAt): array
    {
        $signals = [];

        for ($date = $from->copy()->startOfDay(); $date->lte($soldAt); $date->addDay()) {
            $dayStart = $date->copy();
            $dayEnd = $date->copy()->endOfDay();
            $views = DB::table('car_views')
                ->where('company_id', $car->company_id)
                ->where('car_id', $car->id)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();
            $interactions = DB::table('car_interactions')
                ->where('company_id', $car->company_id)
                ->where('car_id', $car->id)
                ->whereBetween('created_at', [$dayStart, $dayEnd]);
            $whatsapp = (clone $interactions)->where('interaction_type', 'whatsapp_click')->count();
            $calls = (clone $interactions)->where('interaction_type', 'call_click')->count();
            $forms = (clone $interactions)->where('interaction_type', 'form_open')->count();
            $leads = DB::table('car_leads')
                ->where('company_id', $car->company_id)
                ->where('car_id', $car->id)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();

            $signals[] = [
                'date' => $dayStart->toDateString(),
                'score' => min(100, (int) (($views * 2) + ($whatsapp * 18) + ($calls * 14) + ($forms * 10) + ($leads * 24))),
            ];
        }

        return $signals;
    }

    private function peakContactSignal(array $dailySignals): array
    {
        $peak = collect($dailySignals)->sortByDesc('score')->first() ?? ['score' => 0, 'date' => null];

        return [
            'score' => (int) ($peak['score'] ?? 0),
            'date' => !empty($peak['date']) ? Carbon::parse($peak['date']) : null,
        ];
    }

    private function contactSignalTrend(array $dailySignals): string
    {
        $lastThree = collect($dailySignals)->slice(-3)->avg('score') ?? 0;
        $previousFour = collect($dailySignals)->slice(0, max(0, count($dailySignals) - 3))->avg('score') ?? 0;
        $delta = $lastThree - $previousFour;

        if ($delta >= 8) {
            return 'up';
        }

        if ($delta <= -8) {
            return 'down';
        }

        return 'stable';
    }

    private function primaryContactChannel(array $recentMetrics): string
    {
        if (($recentMetrics['whatsapp_clicks'] ?? 0) > 0) {
            return 'whatsapp';
        }

        if (($recentMetrics['calls'] ?? 0) > 0) {
            return 'call';
        }

        if (($recentMetrics['forms'] ?? 0) > 0 || ($recentMetrics['leads'] ?? 0) > 0) {
            return 'form';
        }

        return 'unknown';
    }

    private function saleQualityScore(int $contactSignalScore, ?int $daysInStock, ?float $priceVsBenchmark): int
    {
        $stockScore = match (true) {
            $daysInStock === null => 45,
            $daysInStock <= 15 => 100,
            $daysInStock <= 30 => 80,
            $daysInStock <= 60 => 60,
            $daysInStock <= 90 => 40,
            default => 25,
        };
        $priceScore = match (true) {
            $priceVsBenchmark === null => 55,
            $priceVsBenchmark <= -5 => 85,
            $priceVsBenchmark <= 5 => 70,
            $priceVsBenchmark <= 12 => 50,
            default => 30,
        };

        return max(0, min(100, (int) round(($stockScore * 0.35) + ($contactSignalScore * 0.45) + ($priceScore * 0.20))));
    }

    private function recentContactSignalFloor(array $recentMetrics): int
    {
        return min(100, (int) (
            ($recentMetrics['whatsapp_clicks'] * 18)
            + ($recentMetrics['calls'] * 14)
            + ($recentMetrics['forms'] * 10)
            + ($recentMetrics['leads'] * 24)
        ));
    }

    private function levelFromScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'very_high',
            $score >= 65 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'very_low',
        };
    }

    private function priceVsBenchmark(Car $car): ?float
    {
        $score = DB::table('car_sale_potential_scores')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first(['price_vs_market']);

        return $score?->price_vs_market !== null ? (float) $score->price_vs_market : null;
    }

    private function safeFunnelAnalysis(Car $car, Carbon $from, Carbon $soldAt, array $recentMetrics): array
    {
        try {
            return $this->carFunnelAnalyzer->analyzeForCar($car, $from->toDateString(), $soldAt->toDateString());
        } catch (Throwable $exception) {
            Log::warning('[Sales Learning] Funnel analysis fallback used', [
                'car_id' => $car->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'metrics' => [
                    'sessions' => $recentMetrics['sessions'],
                    'views' => $recentMetrics['views'],
                    'whatsapp_clicks' => $recentMetrics['whatsapp_clicks'],
                    'leads' => $recentMetrics['leads'],
                ],
            ];
        }
    }

    private function withRecentMetricFallback(array $funnelAnalysis, array $recentMetrics): array
    {
        $metrics = $funnelAnalysis['metrics'] ?? [];

        $funnelAnalysis['metrics'] = [
            ...$metrics,
            'sessions' => max((int) ($metrics['sessions'] ?? 0), $recentMetrics['sessions']),
            'views' => max((int) ($metrics['views'] ?? 0), $recentMetrics['views']),
            'whatsapp_clicks' => max((int) ($metrics['whatsapp_clicks'] ?? 0), $recentMetrics['whatsapp_clicks']),
            'leads' => max((int) ($metrics['leads'] ?? 0), $recentMetrics['leads']),
            'form_opens' => max((int) ($metrics['form_opens'] ?? 0), $recentMetrics['forms']),
        ];

        return $funnelAnalysis;
    }

    private function withRecentIntentFallback(array $intentAnalysis, array $recentMetrics): array
    {
        return [
            ...$intentAnalysis,
            'unique_visitors' => max((int) ($intentAnalysis['unique_visitors'] ?? 0), $recentMetrics['views']),
            'sessions' => max((int) ($intentAnalysis['sessions'] ?? 0), $recentMetrics['sessions']),
            'whatsapp_clicks' => max((int) ($intentAnalysis['whatsapp_clicks'] ?? 0), $recentMetrics['whatsapp_clicks']),
            'leads' => max((int) ($intentAnalysis['leads'] ?? 0), $recentMetrics['leads']),
        ];
    }

    private function safeIntentAnalysis(Car $car, Carbon $from, Carbon $soldAt, array $recentMetrics): array
    {
        try {
            return $this->intentAnalysisService->analyzeForCar($car, $from->toDateString(), $soldAt->toDateString());
        } catch (Throwable $exception) {
            Log::warning('[Sales Learning] Intent analysis fallback used', [
                'car_id' => $car->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'intent_score' => 0,
                'strong_intent_users' => 0,
                'unique_visitors' => $recentMetrics['views'],
                'sessions' => $recentMetrics['sessions'],
                'whatsapp_clicks' => $recentMetrics['whatsapp_clicks'],
                'leads' => $recentMetrics['leads'],
            ];
        }
    }

    private function safeLeadRealityGap(Car $car, array $funnelAnalysis, array $intentAnalysis, Carbon $from, Carbon $soldAt): array
    {
        try {
            return $this->leadRealityGapService->analyzeForCar($car, $funnelAnalysis, $intentAnalysis, $from->toDateString(), $soldAt->toDateString());
        } catch (Throwable $exception) {
            Log::warning('[Sales Learning] Lead reality gap fallback used', [
                'car_id' => $car->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function campaignContext(Car $car, Carbon $soldAt): array
    {
        $from = $soldAt->copy()->subDays(14);
        $mappings = DB::table('car_ad_campaigns')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where(function ($query) use ($from) {
                $query->where('is_active', true)
                    ->orWhere('updated_at', '>=', $from);
            })
            ->get(['campaign_id', 'adset_id', 'ad_id']);

        return [
            'campaign_ids' => $mappings->pluck('campaign_id')->filter()->unique()->values()->all(),
            'adset_ids' => $mappings->pluck('adset_id')->filter()->unique()->values()->all(),
            'ad_ids' => $mappings->pluck('ad_id')->filter()->unique()->values()->all(),
        ];
    }
}
