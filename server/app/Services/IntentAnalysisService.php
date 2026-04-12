<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarLead;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IntentAnalysisService
{
    private const DEFAULT_WINDOW_DAYS = 30;
    private const MIN_BENCHMARK_VISITORS = 5;
    private const MIN_BENCHMARK_SESSIONS = 8;
    private const MIN_BENCHMARK_COMPARABLE_CARS = 3;

    public function analyzeForCar(Car $car, ?string $from = null, ?string $to = null): array
    {
        $benchmarkCars = Car::query()
            ->where('company_id', $car->company_id)
            ->where('status', 'active')
            ->get(['id', 'company_id']);

        $analyses = $this->analyzeCollections(collect([$car]), $benchmarkCars, $from, $to);

        return $analyses[$car->id] ?? $this->emptyAnalysis($from, $to);
    }

    public function analyzeForCars(Collection $cars, ?string $from = null, ?string $to = null): array
    {
        if ($cars->isEmpty()) {
            return [];
        }

        $benchmarkCars = Car::query()
            ->where('company_id', (int) $cars->first()->company_id)
            ->where('status', 'active')
            ->get(['id', 'company_id']);

        return $this->analyzeCollections($cars, $benchmarkCars, $from, $to);
    }

    private function analyzeCollections(Collection $targetCars, Collection $benchmarkCars, ?string $from = null, ?string $to = null): array
    {
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->startOfDay();

        if ($benchmarkCars->isEmpty()) {
            return $targetCars->mapWithKeys(fn (Car $car) => [
                $car->id => $this->emptyAnalysis($fromDate->toDateString(), $toDate->toDateString()),
            ])->all();
        }

        $companyId = (int) $benchmarkCars->first()->company_id;
        $benchmarkCarIds = $benchmarkCars->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $carViewStats = $this->loadCarViewStats($companyId, $benchmarkCarIds, $fromDate, $toDate);
        $carInteractionStats = $this->loadCarInteractionStats($companyId, $benchmarkCarIds, $fromDate, $toDate);
        $carLeadStats = $this->loadCarLeadStats($companyId, $benchmarkCarIds, $fromDate, $toDate);
        $identityViewStats = $this->loadIdentityViewStats($companyId, $benchmarkCarIds, $fromDate, $toDate);
        $identityWhatsappStats = $this->loadIdentityWhatsappStats($companyId, $benchmarkCarIds, $fromDate, $toDate);

        $benchmarkAnalyses = collect($benchmarkCarIds)->mapWithKeys(function (int $carId) use (
            $carViewStats,
            $carInteractionStats,
            $carLeadStats,
            $identityViewStats,
            $identityWhatsappStats,
            $fromDate,
            $toDate
        ) {
            $analysis = $this->buildBaseAnalysis(
                $carId,
                $carViewStats->get($carId),
                $carInteractionStats->get($carId),
                $carLeadStats->get($carId),
                $identityViewStats[$carId] ?? [],
                $identityWhatsappStats[$carId] ?? [],
                $fromDate->toDateString(),
                $toDate->toDateString()
            );

            return [$carId => $analysis];
        });

        $benchmarkEligible = $benchmarkAnalyses->filter(function (array $analysis) {
            return (int) ($analysis['unique_visitors'] ?? 0) >= self::MIN_BENCHMARK_VISITORS
                || (int) ($analysis['sessions'] ?? 0) >= self::MIN_BENCHMARK_SESSIONS;
        });

        $hasSufficientBenchmark = $benchmarkEligible->count() >= self::MIN_BENCHMARK_COMPARABLE_CARS;
        $averageIntentScore = $hasSufficientBenchmark ? round((float) $benchmarkEligible->avg('intent_score'), 2) : 0.0;
        $averageLeads = $hasSufficientBenchmark ? round((float) $benchmarkEligible->avg('leads'), 2) : 0.0;

        return $targetCars->mapWithKeys(function (Car $car) use ($benchmarkAnalyses, $averageIntentScore, $averageLeads, $hasSufficientBenchmark, $benchmarkEligible) {
            $analysis = $benchmarkAnalyses->get($car->id, $this->emptyAnalysis());
            $analysis['relative_performance'] = $this->buildRelativePerformance(
                $analysis['intent_score'] ?? 0,
                (int) ($analysis['leads'] ?? 0),
                $averageIntentScore,
                $averageLeads,
                $hasSufficientBenchmark,
                $benchmarkEligible->count()
            );

            return [$car->id => $analysis];
        })->all();
    }

    private function loadCarViewStats(int $companyId, array $carIds, Carbon $fromDate, Carbon $toDate): Collection
    {
        return DB::table('car_views')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('car_id')
            ->selectRaw('
                car_id,
                COUNT(*) as views_count,
                COUNT(DISTINCT session_id) as sessions_count,
                COUNT(DISTINCT visitor_id) as unique_visitors_count,
                ROUND(AVG(COALESCE(view_duration_seconds, 0)), 2) as avg_time_on_page
            ')
            ->get()
            ->keyBy('car_id');
    }

    private function loadCarInteractionStats(int $companyId, array $carIds, Carbon $fromDate, Carbon $toDate): Collection
    {
        return DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('car_id')
            ->selectRaw("
                car_id,
                SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) as whatsapp_clicks,
                ROUND(AVG(
                    CASE
                        WHEN interaction_type IN ('scroll', 'scroll_depth') THEN
                            CAST(
                                COALESCE(
                                    JSON_UNQUOTE(JSON_EXTRACT(meta, '$.scroll_pct')),
                                    JSON_UNQUOTE(JSON_EXTRACT(meta, '$.scroll_depth'))
                                ) AS DECIMAL(10, 2)
                            )
                        ELSE NULL
                    END
                ), 2) as avg_scroll
            ")
            ->get()
            ->keyBy('car_id');
    }

    private function loadCarLeadStats(int $companyId, array $carIds, Carbon $fromDate, Carbon $toDate): Collection
    {
        return CarLead::query()
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('car_id')
            ->selectRaw('
                car_id,
                COUNT(*) as leads_count,
                SUM(CASE WHEN contacted_at IS NOT NULL THEN 1 ELSE 0 END) as contacted_leads_count,
                SUM(CASE WHEN contacted_at IS NULL THEN 1 ELSE 0 END) as unanswered_leads_count
            ')
            ->get()
            ->keyBy('car_id');
    }

    private function loadIdentityViewStats(int $companyId, array $carIds, Carbon $fromDate, Carbon $toDate): array
    {
        return DB::table('car_views')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->where(function ($query) {
                $query->whereNotNull('visitor_id');
            })
            ->groupBy('car_id', 'visitor_id')
            ->selectRaw('
                car_id,
                visitor_id as identity_key,
                COUNT(DISTINCT session_id) as sessions_count,
                COALESCE(SUM(view_duration_seconds), 0) as total_view_duration_seconds
            ')
            ->get()
            ->groupBy('car_id')
            ->map(function (Collection $rows) {
                return $rows->keyBy('identity_key')->map(fn ($row) => [
                    'sessions_count' => (int) ($row->sessions_count ?? 0),
                    'total_view_duration_seconds' => (int) ($row->total_view_duration_seconds ?? 0),
                ])->all();
            })
            ->all();
    }

    private function loadIdentityWhatsappStats(int $companyId, array $carIds, Carbon $fromDate, Carbon $toDate): array
    {
        return DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->where(function ($query) {
                $query->whereNotNull('visitor_id');
            })
            ->groupBy('car_id', 'visitor_id')
            ->selectRaw("
                car_id,
                visitor_id as identity_key,
                SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) as whatsapp_clicks
            ")
            ->get()
            ->groupBy('car_id')
            ->map(function (Collection $rows) {
                return $rows->keyBy('identity_key')->map(fn ($row) => [
                    'whatsapp_clicks' => (int) ($row->whatsapp_clicks ?? 0),
                ])->all();
            })
            ->all();
    }

    private function buildBaseAnalysis(
        int $carId,
        ?object $viewStats,
        ?object $interactionStats,
        ?object $leadStats,
        array $identityViews,
        array $identityWhatsapps,
        ?string $from = null,
        ?string $to = null
    ): array {
        $avgTime = round((float) ($viewStats->avg_time_on_page ?? 0), 2);
        $avgScroll = round((float) ($interactionStats->avg_scroll ?? 0), 2);
        $sessions = (int) ($viewStats->sessions_count ?? 0);
        $uniqueVisitors = (int) ($viewStats->unique_visitors_count ?? 0);
        $whatsappClicks = (int) ($interactionStats->whatsapp_clicks ?? 0);
        $leads = (int) ($leadStats->leads_count ?? 0);
        $contactedLeads = (int) ($leadStats->contacted_leads_count ?? 0);
        $unansweredLeads = (int) ($leadStats->unanswered_leads_count ?? 0);
        $visitorIntentScores = $this->resolveVisitorIntentScores($identityViews, $identityWhatsapps);
        $strongIntentUsers = $this->resolveStrongIntentUsersCount($visitorIntentScores);
        $intentDistribution = $this->resolveIntentDistribution($visitorIntentScores);

        $intentScore = 0;

        if ($avgTime >= 60) {
            $intentScore += 30;
        }
        if ($avgScroll >= 60) {
            $intentScore += 20;
        }
        if ($sessions >= 2) {
            $intentScore += 15;
        }
        if ($whatsappClicks >= 1) {
            $intentScore += 20;
        }
        if ($whatsappClicks >= 3) {
            $intentScore += 15;
        }

        $intentScore = min($intentScore, 100);
        $contactEfficiency = $strongIntentUsers > 0
            ? round(($leads / $strongIntentUsers) * 100, 2)
            : null;
        $confidenceScore = $this->resolveConfidenceScore(
            $uniqueVisitors,
            $strongIntentUsers,
            $whatsappClicks,
            $sessions
        );
        $contactEfficiencyConfidence = $this->resolveEfficiencyConfidence($strongIntentUsers, $uniqueVisitors, $whatsappClicks, $sessions);

        $diagnostic = $this->buildDiagnostic(
            $intentScore,
            $whatsappClicks,
            $strongIntentUsers,
            $leads,
            $unansweredLeads,
            $confidenceScore
        );
        $tags = $this->resolveTags($intentScore, $leads);

        return [
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'intent_score' => $intentScore,
            'intent_level' => $this->resolveIntentLevel($intentScore),
            'avg_time_on_page' => $avgTime,
            'avg_scroll' => $avgScroll,
            'sessions' => $sessions,
            'unique_visitors' => $uniqueVisitors,
            'strong_intent_users' => $strongIntentUsers,
            'strong_intent_users_logic' => 'unique_visitor_id_with_sessions>=2_and_total_time>=60_and_whatsapp_clicks>=1',
            'whatsapp_clicks' => $whatsappClicks,
            'leads' => $leads,
            'contacted_leads' => $contactedLeads,
            'unanswered_leads' => $unansweredLeads,
            'contact_efficiency' => $contactEfficiency,
            'contact_efficiency_confidence' => $contactEfficiencyConfidence,
            'confidence_score' => $confidenceScore,
            'intent_distribution' => $intentDistribution,
            'diagnostic' => $diagnostic,
            'tags' => $tags,
            'relative_performance' => [
                'intent_vs_avg' => null,
                'conversion_vs_avg' => null,
                'status' => 'insufficient_sample',
                'sample_size' => 0,
                'has_sufficient_sample' => false,
            ],
        ];
    }

    private function resolveVisitorIntentScores(array $identityViews, array $identityWhatsapps): array
    {
        $identityKeys = collect(array_unique(array_merge(array_keys($identityViews), array_keys($identityWhatsapps))));

        return $identityKeys->mapWithKeys(function (string $identityKey) use ($identityViews, $identityWhatsapps) {
            $viewData = $identityViews[$identityKey] ?? ['sessions_count' => 0, 'total_view_duration_seconds' => 0];
            $whatsappData = $identityWhatsapps[$identityKey] ?? ['whatsapp_clicks' => 0];
            $sessions = (int) ($viewData['sessions_count'] ?? 0);
            $totalViewDuration = (int) ($viewData['total_view_duration_seconds'] ?? 0);
            $whatsappClicks = (int) ($whatsappData['whatsapp_clicks'] ?? 0);
            $score = 0;

            if ($sessions >= 2) {
                $score += 30;
            }

            if ($totalViewDuration >= 60) {
                $score += 35;
            }

            if ($whatsappClicks >= 1) {
                $score += 20;
            }

            if ($whatsappClicks >= 3) {
                $score += 15;
            }

            return [$identityKey => [
                'sessions_count' => $sessions,
                'total_view_duration_seconds' => $totalViewDuration,
                'whatsapp_clicks' => $whatsappClicks,
                'intent_score' => min(100, $score),
            ]];
        })->all();
    }

    private function resolveStrongIntentUsersCount(array $visitorIntentScores): int
    {
        return collect($visitorIntentScores)->filter(function (array $visitor) {
            return (int) ($visitor['sessions_count'] ?? 0) >= 2
                && (int) ($visitor['total_view_duration_seconds'] ?? 0) >= 60
                && (int) ($visitor['whatsapp_clicks'] ?? 0) >= 1;
        })->count();
    }

    private function resolveIntentDistribution(array $visitorIntentScores): array
    {
        return collect($visitorIntentScores)->reduce(function (array $carry, array $visitor) {
            $score = (int) ($visitor['intent_score'] ?? 0);

            if ($score >= 85) {
                $carry['very_high']++;
            } elseif ($score >= 65) {
                $carry['high']++;
            } elseif ($score >= 35) {
                $carry['medium']++;
            } else {
                $carry['low']++;
            }

            return $carry;
        }, [
            'very_high' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ]);
    }

    private function resolveConfidenceScore(int $uniqueVisitors, int $strongIntentUsers, int $whatsappClicks, int $sessions): int
    {
        $score = 0;
        $score += min(30, $uniqueVisitors * 6);
        $score += min(25, $strongIntentUsers * 10);
        $score += min(25, $whatsappClicks * 4);
        $score += min(20, $sessions * 2);

        return max(15, min(100, $score));
    }

    private function resolveEfficiencyConfidence(int $strongIntentUsers, int $uniqueVisitors, int $whatsappClicks, int $sessions): string
    {
        $score = $this->resolveConfidenceScore($uniqueVisitors, $strongIntentUsers, $whatsappClicks, $sessions);

        return match (true) {
            $score >= 75 => 'high',
            $score >= 45 => 'medium',
            default => 'low',
        };
    }

    private function buildDiagnostic(
        int $intentScore,
        int $whatsappClicks,
        int $strongIntentUsers,
        int $leads,
        int $unansweredLeads,
        int $confidenceScore
    ): array {
        if ($leads > 0 && $unansweredLeads > 0) {
            return [
                'primary_issue' => 'no_response',
                'message' => 'Já existem leads, mas parte delas continua sem resposta do stand.',
                'confidence' => min(95, 70 + ($unansweredLeads * 10)),
                'confidence_reason' => 'existem leads reais sem contacto registado no CRM',
            ];
        }

        if ($intentScore >= 70 && $whatsappClicks >= 5 && $leads === 0) {
            return [
                'primary_issue' => 'decision_friction',
                'message' => 'Há forte intenção sem contacto capturado.',
                'confidence' => min(95, (int) round(55 + ($intentScore * 0.2) + min(15, $whatsappClicks * 2) + ($confidenceScore * 0.15))),
                'confidence_reason' => $this->resolveConfidenceReason($confidenceScore, $strongIntentUsers, $whatsappClicks),
            ];
        }

        if ($strongIntentUsers >= 2 && $leads === 0) {
            return [
                'primary_issue' => 'contact_loss',
                'message' => 'Existem utilizadores com intenção forte sem conversão em contacto real.',
                'confidence' => min(92, (int) round(50 + ($strongIntentUsers * 9) + ($intentScore * 0.12) + ($confidenceScore * 0.2))),
                'confidence_reason' => $this->resolveConfidenceReason($confidenceScore, $strongIntentUsers, $whatsappClicks),
            ];
        }

        if ($leads > 0) {
            return [
                'primary_issue' => 'healthy_interest',
                'message' => 'Já existe contacto real e o fluxo está a responder aos sinais de intenção.',
                'confidence' => max(55, min(90, $intentScore)),
                'confidence_reason' => 'existem leads capturadas e sinais consistentes de intenção',
            ];
        }

        return [
            'primary_issue' => 'low_intent',
            'message' => 'Ainda não há sinais fortes de intenção de contacto suficientes para concluir fricção.',
            'confidence' => max(35, min(70, 40 + (int) round($intentScore * 0.4))),
            'confidence_reason' => 'o volume de utilizadores e interações fortes ainda é limitado',
        ];
    }

    private function resolveConfidenceReason(int $confidenceScore, int $strongIntentUsers, int $whatsappClicks): string
    {
        return match (true) {
            $confidenceScore >= 75 => 'volume suficiente de utilizadores únicos e interações fortes',
            $strongIntentUsers >= 2 || $whatsappClicks >= 3 => 'já existem sinais fortes, mas ainda com amostra moderada',
            default => 'o padrão existe, mas ainda com amostra reduzida',
        };
    }

    private function resolveTags(int $intentScore, int $leads): array
    {
        $tags = [];

        if ($intentScore >= 75 && $leads === 0) {
            $tags[] = 'hot_no_conversion';
        }

        return $tags;
    }

    private function buildRelativePerformance(
        int $intentScore,
        int $leads,
        float $averageIntentScore,
        float $averageLeads,
        bool $hasSufficientSample,
        int $sampleSize
    ): array
    {
        if (!$hasSufficientSample) {
            return [
                'intent_vs_avg' => null,
                'conversion_vs_avg' => null,
                'status' => 'insufficient_sample',
                'sample_size' => $sampleSize,
                'has_sufficient_sample' => false,
            ];
        }

        $intentVsAvg = $this->formatDeltaPercentage($intentScore, $averageIntentScore);
        $conversionVsAvg = $this->formatDeltaPercentage($leads, $averageLeads);

        $status = 'aligned';

        if ($averageIntentScore > 0 && $intentScore > $averageIntentScore && $leads < $averageLeads) {
            $status = 'underperforming_conversion';
        } elseif ($intentScore >= $averageIntentScore && $leads >= $averageLeads) {
            $status = 'outperforming';
        } elseif ($intentScore < $averageIntentScore && $leads <= $averageLeads) {
            $status = 'low_intent';
        }

        return [
            'intent_vs_avg' => $intentVsAvg,
            'conversion_vs_avg' => $conversionVsAvg,
            'status' => $status,
            'sample_size' => $sampleSize,
            'has_sufficient_sample' => true,
        ];
    }

    private function formatDeltaPercentage(float $current, float $average): string
    {
        if ($average <= 0.0) {
            if ($current <= 0.0) {
                return '0%';
            }

            return '+100%';
        }

        $delta = (($current - $average) / $average) * 100;
        $rounded = (int) round($delta);

        return sprintf('%s%d%%', $rounded > 0 ? '+' : '', $rounded);
    }

    private function resolveIntentLevel(int $intentScore): string
    {
        return match (true) {
            $intentScore >= 75 => 'high',
            $intentScore >= 45 => 'medium',
            default => 'low',
        };
    }

    private function emptyAnalysis(?string $from = null, ?string $to = null): array
    {
        return [
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'intent_score' => 0,
            'intent_level' => 'low',
            'avg_time_on_page' => 0,
            'avg_scroll' => 0,
            'sessions' => 0,
            'unique_visitors' => 0,
            'strong_intent_users' => 0,
            'strong_intent_users_logic' => 'unique_visitor_id_with_sessions>=2_and_total_time>=60_and_whatsapp_clicks>=1',
            'whatsapp_clicks' => 0,
            'leads' => 0,
            'contacted_leads' => 0,
            'unanswered_leads' => 0,
            'contact_efficiency' => null,
            'contact_efficiency_confidence' => 'low',
            'confidence_score' => 15,
            'intent_distribution' => [
                'very_high' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ],
            'diagnostic' => [
                'primary_issue' => 'low_intent',
                'message' => 'Ainda não há dados suficientes para ler a intenção de contacto.',
                'confidence' => 35,
                'confidence_reason' => 'amostra insuficiente de visitantes com identificação consistente',
            ],
            'tags' => [],
            'relative_performance' => [
                'intent_vs_avg' => null,
                'conversion_vs_avg' => null,
                'status' => 'insufficient_sample',
                'sample_size' => 0,
                'has_sufficient_sample' => false,
            ],
        ];
    }
}
