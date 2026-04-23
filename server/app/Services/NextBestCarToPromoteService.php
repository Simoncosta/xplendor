<?php

namespace App\Services;

use App\Models\Car;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NextBestCarToPromoteService
{
    private const WINDOW_DAYS = 7;

    private const WEIGHTS = [
        'contact_signal' => 30,
        'recent_demand' => 18,
        'price_position' => 18,
        'market_score' => 14,
        'no_active_campaign' => 10,
        'stock_age' => 6,
        'similar_sales_learning' => 4,
    ];

    private const PENALTIES = [
        'active_campaign_consuming' => 10,
        'weak_data' => 15,
        'saturated_segment' => 6,
        'price_misaligned' => 10,
    ];

    private const STATE_LABELS = [
        'ready' => 'Pronto para anunciar',
        'candidate' => 'Bom candidato',
        'watch' => 'Em observação',
        'avoid' => 'Evitar investimento',
    ];

    public function rankCarsForPromotion(int $companyId, array $filters = []): array
    {
        $cars = Car::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['brand:id,name', 'model:id,name'])
            ->orderByDesc('created_at')
            ->get();

        if ($cars->isEmpty()) {
            return $this->emptyResult();
        }

        $carIds = $cars->pluck('id')->all();
        $metrics = $this->recentMetrics($companyId, $carIds);
        $ipsScores = $this->latestPotentialScores($companyId, $carIds);
        $campaigns = $this->campaignMetrics($companyId, $carIds);
        $segmentCounts = $this->segmentCounts($cars);
        $learning = $this->similarSalesLearning($companyId);

        $ranked = $cars
            ->map(fn (Car $car) => $this->rankCar(
                $car,
                $metrics[$car->id] ?? [],
                $ipsScores[$car->id] ?? null,
                $campaigns[$car->id] ?? [],
                $segmentCounts,
                $learning
            ))
            ->sortBy([
                fn (array $a, array $b) => $this->stateOrder($a['promotion_state']) <=> $this->stateOrder($b['promotion_state']),
                fn (array $a, array $b) => $b['promotion_score'] <=> $a['promotion_score'],
                fn (array $a, array $b) => $b['confidence'] <=> $a['confidence'],
            ])
            ->values()
            ->map(function (array $item, int $index) {
                $item['position'] = $index + 1;
                return $item;
            });

        $limited = $this->applyLimit($ranked, $filters['limit'] ?? null);
        $summary = $ranked->countBy('promotion_state');

        return [
            'summary' => [
                'ready' => (int) ($summary['ready'] ?? 0),
                'candidate' => (int) ($summary['candidate'] ?? 0),
                'watch' => (int) ($summary['watch'] ?? 0),
                'avoid' => (int) ($summary['avoid'] ?? 0),
            ],
            'cars' => $limited->values()->all(),
            'cars_ranked_for_ads' => $limited->values()->all(),
            'ready' => $limited->where('promotion_state', 'ready')->values()->all(),
            'candidate' => $limited->where('promotion_state', 'candidate')->values()->all(),
            'watch' => $limited->where('promotion_state', 'watch')->values()->all(),
            'avoid' => $limited->where('promotion_state', 'avoid')->values()->all(),
            'investment_switch_suggestion' => $this->suggestInvestmentSwitchFromRanking($ranked),
        ];
    }

    public function suggestInvestmentSwitch(int $companyId): ?array
    {
        $ranking = $this->rankCarsForPromotion($companyId, ['limit' => 'all']);

        return $ranking['investment_switch_suggestion'] ?? null;
    }

    private function rankCar(
        Car $car,
        array $metrics,
        ?array $ips,
        array $campaign,
        array $segmentCounts,
        array $learning
    ): array {
        $hasActiveCampaign = (int) ($campaign['active_campaigns'] ?? 0) > 0;
        $recentSpend = (float) ($campaign['spend_last_7d'] ?? 0);
        $contactSignal = $this->contactSignalScore($metrics);
        $demandScore = $this->recentDemandScore($metrics);
        $priceScore = $this->pricePositionScore($ips);
        $marketScore = $this->marketScore($ips);
        $stockScore = $this->stockAgeScore($car);
        $learningScore = $this->learningScore($car, $learning);
        $segmentKey = $this->segmentKey($car);

        $score = 0;
        $score += $contactSignal * self::WEIGHTS['contact_signal'] / 100;
        $score += $demandScore * self::WEIGHTS['recent_demand'] / 100;
        $score += $priceScore * self::WEIGHTS['price_position'] / 100;
        $score += $marketScore * self::WEIGHTS['market_score'] / 100;
        $score += $hasActiveCampaign ? 0 : self::WEIGHTS['no_active_campaign'];
        $score += $stockScore * self::WEIGHTS['stock_age'] / 100;
        $score += $learningScore * self::WEIGHTS['similar_sales_learning'] / 100;

        $weakData = $this->hasWeakData($metrics);
        $priceMisaligned = $this->isPriceMisaligned($ips);
        $segmentSaturated = ($segmentCounts[$segmentKey] ?? 0) >= 6;
        $activeCampaignConsuming = $hasActiveCampaign && $recentSpend >= 20 && $contactSignal < 30;
        $isSeed = $this->isTestCampaignSeed($metrics, $hasActiveCampaign, $marketScore, $priceScore);

        if ($weakData) {
            $score -= self::PENALTIES['weak_data'];
        }

        if ($priceMisaligned) {
            $score -= self::PENALTIES['price_misaligned'];
        }

        if ($segmentSaturated) {
            $score -= self::PENALTIES['saturated_segment'];
        }

        if ($activeCampaignConsuming) {
            $score -= self::PENALTIES['active_campaign_consuming'];
        }

        $score = max(0, min(100, (int) round($score)));
        $state = $this->classifyState($score, $contactSignal, $weakData, $priceMisaligned, $activeCampaignConsuming, $isSeed);
        $reasons = $this->buildReasons($contactSignal, $demandScore, $priceScore, $marketScore, $hasActiveCampaign, $weakData, $priceMisaligned, $activeCampaignConsuming, $isSeed);
        $action = $this->recommendedAction($state, $isSeed);

        return [
            'car_id' => $car->id,
            'car_name' => $this->carName($car),
            'price_gross' => $this->effectivePrice($car),
            'promotion_score' => $score,
            'promotion_state' => $state,
            'promotion_label' => self::STATE_LABELS[$state],
            'confidence' => $this->confidence($metrics, $ips, $campaign, $learningScore),
            'reasons' => $reasons,
            'recommended_action' => $action,
            'has_active_campaign' => $hasActiveCampaign,
            'active_campaigns' => (int) ($campaign['active_campaigns'] ?? 0),
            'spend_last_7d' => round($recentSpend, 2),
            'contact_signal_score' => $contactSignal,
            'views_last_7d' => (int) ($metrics['views'] ?? 0),
            'sessions_last_7d' => (int) ($metrics['sessions'] ?? 0),
            'whatsapp_clicks_last_7d' => (int) ($metrics['whatsapp_clicks'] ?? 0),
            'leads_last_7d' => (int) ($metrics['leads'] ?? 0),
            'price_vs_market' => $ips['price_vs_market'] ?? null,
            'market_score' => $ips['score'] ?? null,
            'days_in_stock' => $this->daysInStock($car),
            'flags' => $isSeed ? ['test_campaign_seed'] : [],

            // Backwards-compatible fields for the existing dashboard payload.
            'priority_score' => $score,
            'confidence_score' => $this->confidence($metrics, $ips, $campaign, $learningScore),
            'investment_label' => $this->legacyInvestmentLabel($state),
            'reason' => $reasons[0] ?? null,
            'why_now' => $reasons[1] ?? ($reasons[0] ?? null),
            'risk_note' => $state === 'avoid' ? ($reasons[0] ?? 'Não vale investimento neste momento.') : null,
            'smartads_decision' => $this->legacyDecision($state, $isSeed),
        ];
    }

    private function classifyState(
        int $score,
        int $contactSignal,
        bool $weakData,
        bool $priceMisaligned,
        bool $activeCampaignConsuming,
        bool $isSeed
    ): string {
        if ($isSeed) {
            return $score >= 52 ? 'candidate' : 'watch';
        }

        if ($activeCampaignConsuming && $score < 45) {
            return 'avoid';
        }

        if ($score >= 75 && $contactSignal >= 45 && !$priceMisaligned && !$activeCampaignConsuming) {
            return 'ready';
        }

        if ($score >= 55 && !$activeCampaignConsuming) {
            return 'candidate';
        }

        if ($weakData || $score >= 40) {
            return 'watch';
        }

        return 'avoid';
    }

    private function buildReasons(
        int $contactSignal,
        int $demandScore,
        int $priceScore,
        int $marketScore,
        bool $hasActiveCampaign,
        bool $weakData,
        bool $priceMisaligned,
        bool $activeCampaignConsuming,
        bool $isSeed
    ): array {
        $reasons = [];

        if ($activeCampaignConsuming) {
            $reasons[] = 'Campanha ativa a consumir sem sinal suficiente.';
        } elseif ($contactSignal >= 60) {
            $reasons[] = 'Sinal forte de contacto recente.';
        } elseif ($contactSignal >= 35) {
            $reasons[] = 'Já existe intenção comercial a validar.';
        } elseif ($weakData) {
            $reasons[] = 'Ainda há pouco volume para decidir com segurança.';
        } else {
            $reasons[] = 'Sinal de contacto ainda fraco.';
        }

        if ($priceMisaligned) {
            $reasons[] = 'Preço desalinhado face ao mercado.';
        } elseif ($priceScore >= 70 || $marketScore >= 65) {
            $reasons[] = 'Bom posicionamento face ao mercado.';
        }

        if (!$hasActiveCampaign) {
            $reasons[] = $isSeed
                ? 'Sem investimento ativo; pode testar campanha seed sem entrar em evitar.'
                : 'Ainda sem investimento ativo.';
        } elseif (!$activeCampaignConsuming) {
            $reasons[] = 'Já existe campanha ativa com sinais a acompanhar.';
        }

        if ($demandScore >= 60 && count($reasons) < 3) {
            $reasons[] = 'Procura recente acima do mínimo esperado.';
        }

        return array_slice(array_values(array_unique($reasons)), 0, 3);
    }

    private function recommendedAction(string $state, bool $isSeed): array
    {
        if ($isSeed) {
            return [
                'type' => 'test_campaign_seed',
                'label' => 'Testar campanha seed',
            ];
        }

        return match ($state) {
            'ready' => ['type' => 'promote_car', 'label' => 'Promover este carro'],
            'candidate' => ['type' => 'promote_car', 'label' => 'Promover este carro'],
            'avoid' => ['type' => 'avoid_investment', 'label' => 'Evitar investimento'],
            default => ['type' => 'observe_car', 'label' => 'Observar evolução'],
        };
    }

    private function suggestInvestmentSwitchFromRanking(Collection $ranked): ?array
    {
        $from = $ranked
            ->filter(fn (array $car) => ($car['has_active_campaign'] ?? false) && ($car['promotion_score'] ?? 0) < 45)
            ->sortBy('promotion_score')
            ->first();

        $to = $ranked
            ->filter(fn (array $car) => !($car['has_active_campaign'] ?? false) && in_array($car['promotion_state'], ['ready', 'candidate'], true))
            ->sortByDesc('promotion_score')
            ->first();

        if (!$from || !$to || (($to['promotion_score'] ?? 0) - ($from['promotion_score'] ?? 0)) < 18) {
            return null;
        }

        return [
            'from_car_id' => $from['car_id'],
            'from_car_name' => $from['car_name'],
            'to_car_id' => $to['car_id'],
            'to_car_name' => $to['car_name'],
            'reason' => [
                'O carro atual tem score fraco.',
                'Existe outro carro com probabilidade superior de performar.',
                'O candidato alternativo ainda não tem campanha ativa.',
            ],
            'confidence' => max(55, min(95, (int) round(60 + (($to['promotion_score'] - $from['promotion_score']) * 0.6)))),
            'action' => [
                'type' => 'switch_car_investment',
                'label' => 'Trocar investimento',
            ],
        ];
    }

    private function recentMetrics(int $companyId, array $carIds): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $views = DB::table('car_views')
            ->select('car_id', DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT COALESCE(session_id, ip_address)) as sessions'))
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('created_at', '>=', $since)
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $interactions = DB::table('car_interactions')
            ->select(
                'car_id',
                DB::raw("SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) as whatsapp_clicks"),
                DB::raw("SUM(CASE WHEN interaction_type IN ('call_click', 'show_phone', 'copy_phone') THEN 1 ELSE 0 END) as calls"),
                DB::raw("SUM(CASE WHEN interaction_type IN ('form_open', 'form_start') THEN 1 ELSE 0 END) as form_opens"),
                DB::raw('COUNT(DISTINCT session_id) as interaction_sessions')
            )
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('created_at', '>=', $since)
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $leads = DB::table('car_leads')
            ->select('car_id', DB::raw('COUNT(*) as leads'))
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('created_at', '>=', $since)
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $map = [];

        foreach ($carIds as $carId) {
            $viewRow = $views[$carId] ?? null;
            $interactionRow = $interactions[$carId] ?? null;
            $leadRow = $leads[$carId] ?? null;

            $map[$carId] = [
                'views' => (int) ($viewRow->views ?? 0),
                'sessions' => max((int) ($viewRow->sessions ?? 0), (int) ($interactionRow->interaction_sessions ?? 0)),
                'whatsapp_clicks' => (int) ($interactionRow->whatsapp_clicks ?? 0),
                'calls' => (int) ($interactionRow->calls ?? 0),
                'form_opens' => (int) ($interactionRow->form_opens ?? 0),
                'leads' => (int) ($leadRow->leads ?? 0),
            ];
        }

        return $map;
    }

    private function latestPotentialScores(int $companyId, array $carIds): array
    {
        return DB::table('car_sale_potential_scores')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->orderByDesc('calculated_at')
            ->get()
            ->groupBy('car_id')
            ->map(fn (Collection $scores) => [
                'score' => (int) $scores->first()->score,
                'classification' => $scores->first()->classification,
                'price_vs_market' => $scores->first()->price_vs_market !== null ? (float) $scores->first()->price_vs_market : null,
            ])
            ->all();
    }

    private function campaignMetrics(int $companyId, array $carIds): array
    {
        $active = DB::table('car_ad_campaigns')
            ->select('car_id', DB::raw('COUNT(*) as active_campaigns'))
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('is_active', true)
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $spend = DB::table('campaign_car_metrics_daily')
            ->select('car_id', DB::raw('SUM(spend_normalized) as spend_last_7d'))
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('date', '>=', now()->subDays(self::WINDOW_DAYS)->toDateString())
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $map = [];

        foreach ($carIds as $carId) {
            $map[$carId] = [
                'active_campaigns' => (int) (($active[$carId] ?? null)->active_campaigns ?? 0),
                'spend_last_7d' => (float) (($spend[$carId] ?? null)->spend_last_7d ?? 0),
            ];
        }

        return $map;
    }

    private function similarSalesLearning(int $companyId): array
    {
        return DB::table('car_sales_learning')
            ->join('cars', 'cars.id', '=', 'car_sales_learning.car_id')
            ->select(
                DB::raw("COALESCE(cars.segment, cars.vehicle_type, 'unknown') as segment_key"),
                DB::raw('AVG(car_sales_learning.sale_quality_score) as avg_quality')
            )
            ->where('car_sales_learning.company_id', $companyId)
            ->where('car_sales_learning.created_at', '>=', now()->subDays(90))
            ->groupBy('segment_key')
            ->pluck('avg_quality', 'segment_key')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    private function segmentCounts(Collection $cars): array
    {
        return $cars
            ->groupBy(fn (Car $car) => $this->segmentKey($car))
            ->map(fn (Collection $items) => $items->count())
            ->all();
    }

    private function contactSignalScore(array $metrics): int
    {
        $score = 0;
        $score += min(32, (int) ($metrics['whatsapp_clicks'] ?? 0) * 16);
        $score += min(20, (int) ($metrics['calls'] ?? 0) * 12);
        $score += min(18, (int) ($metrics['leads'] ?? 0) * 18);
        $score += min(10, (int) ($metrics['form_opens'] ?? 0) * 5);
        $score += min(12, (int) floor(((int) ($metrics['sessions'] ?? 0)) / 3));
        $score += min(8, (int) floor(((int) ($metrics['views'] ?? 0)) / 15));

        return max(0, min(100, $score));
    }

    private function recentDemandScore(array $metrics): int
    {
        $viewsScore = min(55, ((int) ($metrics['views'] ?? 0)) * 1.2);
        $sessionsScore = min(30, ((int) ($metrics['sessions'] ?? 0)) * 2);
        $interactionScore = min(15, (((int) ($metrics['whatsapp_clicks'] ?? 0)) + ((int) ($metrics['calls'] ?? 0)) + ((int) ($metrics['form_opens'] ?? 0))) * 5);

        return max(0, min(100, (int) round($viewsScore + $sessionsScore + $interactionScore)));
    }

    private function pricePositionScore(?array $ips): int
    {
        $priceVsMarket = $ips['price_vs_market'] ?? null;

        if ($priceVsMarket === null) {
            return 50;
        }

        if ($priceVsMarket <= -3) {
            return 92;
        }

        if ($priceVsMarket <= 5) {
            return 78;
        }

        if ($priceVsMarket <= 12) {
            return 52;
        }

        return 18;
    }

    private function marketScore(?array $ips): int
    {
        return max(0, min(100, (int) ($ips['score'] ?? 50)));
    }

    private function stockAgeScore(Car $car): int
    {
        $days = $this->daysInStock($car);

        if ($days <= 0) {
            return 45;
        }

        if ($days <= 21) {
            return 80;
        }

        if ($days <= 75) {
            return 70;
        }

        if ($days <= 140) {
            return 45;
        }

        return 22;
    }

    private function learningScore(Car $car, array $learning): int
    {
        $quality = $learning[$this->segmentKey($car)] ?? null;

        return $quality === null ? 45 : max(0, min(100, (int) round($quality)));
    }

    private function confidence(array $metrics, ?array $ips, array $campaign, int $learningScore): int
    {
        $confidence = 35;
        $confidence += min(20, (int) floor(((int) ($metrics['views'] ?? 0)) / 10));
        $confidence += min(15, (int) floor(((int) ($metrics['sessions'] ?? 0)) / 4));
        $confidence += $ips ? 15 : 0;
        $confidence += ((int) ($campaign['active_campaigns'] ?? 0)) > 0 ? 8 : 0;
        $confidence += $learningScore !== 45 ? 7 : 0;

        return max(30, min(95, $confidence));
    }

    private function hasWeakData(array $metrics): bool
    {
        $views = (int) ($metrics['views'] ?? 0);
        $sessions = (int) ($metrics['sessions'] ?? 0);
        $signals = (int) ($metrics['whatsapp_clicks'] ?? 0)
            + (int) ($metrics['calls'] ?? 0)
            + (int) ($metrics['form_opens'] ?? 0)
            + (int) ($metrics['leads'] ?? 0);

        return $views < 8 && $sessions < 3 && $signals === 0;
    }

    private function isPriceMisaligned(?array $ips): bool
    {
        return ($ips['price_vs_market'] ?? null) !== null && (float) $ips['price_vs_market'] > 12;
    }

    private function isTestCampaignSeed(array $metrics, bool $hasActiveCampaign, int $marketScore, int $priceScore): bool
    {
        if ($hasActiveCampaign) {
            return false;
        }

        $views = (int) ($metrics['views'] ?? 0);
        $signals = (int) ($metrics['whatsapp_clicks'] ?? 0)
            + (int) ($metrics['calls'] ?? 0)
            + (int) ($metrics['form_opens'] ?? 0)
            + (int) ($metrics['leads'] ?? 0);

        return $signals === 0 && $views < 12 && ($marketScore >= 45 || $priceScore >= 55);
    }

    private function segmentKey(Car $car): string
    {
        return (string) ($car->segment ?: $car->vehicle_type ?: 'unknown');
    }

    private function daysInStock(Car $car): int
    {
        $date = $car->car_created_at ?? $car->created_at;

        if (!$date instanceof CarbonInterface) {
            return 0;
        }

        return max(0, $date->diffInDays(now()));
    }

    private function effectivePrice(Car $car): ?float
    {
        if ($car->promo_price_gross && $car->price_gross && (float) $car->promo_price_gross < (float) $car->price_gross) {
            return (float) $car->promo_price_gross;
        }

        return $car->price_gross !== null ? (float) $car->price_gross : null;
    }

    private function carName(Car $car): string
    {
        $name = trim(sprintf(
            '%s %s %s',
            $car->brand?->name ?? '',
            $car->model?->name ?? '',
            $car->version ?? ''
        ));

        return $name !== '' ? $name : "Carro #{$car->id}";
    }

    private function applyLimit(Collection $ranked, mixed $limit): Collection
    {
        if ($limit === 'all' || $limit === null) {
            return $ranked;
        }

        $limit = (int) $limit;

        if (!in_array($limit, [5, 10, 20], true)) {
            return $ranked;
        }

        return $ranked->take($limit);
    }

    private function stateOrder(string $state): int
    {
        return match ($state) {
            'ready' => 1,
            'candidate' => 2,
            'watch' => 3,
            'avoid' => 4,
            default => 5,
        };
    }

    private function legacyInvestmentLabel(string $state): string
    {
        return match ($state) {
            'ready' => 'high_priority',
            'candidate' => 'medium_priority',
            'avoid' => 'avoid_investment',
            default => 'low_priority',
        };
    }

    private function legacyDecision(string $state, bool $isSeed): ?string
    {
        if ($isSeed) {
            return 'test_campaign_seed';
        }

        return match ($state) {
            'ready' => 'scale_ads',
            'candidate' => 'test_campaign',
            'avoid' => 'do_not_invest',
            default => 'review_campaign',
        };
    }

    private function emptyResult(): array
    {
        return [
            'summary' => [
                'ready' => 0,
                'candidate' => 0,
                'watch' => 0,
                'avoid' => 0,
            ],
            'cars' => [],
            'cars_ranked_for_ads' => [],
            'ready' => [],
            'candidate' => [],
            'watch' => [],
            'avoid' => [],
            'investment_switch_suggestion' => null,
        ];
    }
}
