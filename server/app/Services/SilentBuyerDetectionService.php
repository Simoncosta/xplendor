<?php

namespace App\Services;

use App\Repositories\Contracts\SilentBuyerDetectionRepositoryInterface;

class SilentBuyerDetectionService
{
    public function __construct(
        protected SilentBuyerDetectionRepositoryInterface $silentBuyerRepository,
    ) {}

    public function getDashboardSummary(int $companyId, int $days = 30): array
    {
        $buyers = $this->silentBuyerRepository
            ->detectByCompany($companyId, $days, 100)
            ->map(fn($buyer) => $this->enrichSilentBuyer($buyer))
            ->values();

        $topAffectedCars = $buyers
            ->groupBy('car_id')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'car_id' => $first['car_id'],
                    'car_name' => trim(sprintf('%s %s %s', $first['brand_name'], $first['model_name'], $first['version'] ?? '')),
                    'silent_buyers_count' => $group->count(),
                    'avg_intent_score' => round($group->avg('intent_score'), 1),
                    'max_intent_score' => (int) $group->max('intent_score'),
                    'dominant_action' => $group->countBy('recommended_action')->sortDesc()->keys()->first(),
                ];
            })
            ->sortByDesc('silent_buyers_count')
            ->take(5)
            ->values();

        $totalDetected = $buyers->count();

        return [
            'total_detected' => $totalDetected,
            'affected_cars_count' => $buyers->pluck('car_id')->unique()->count(),
            'average_intent_score' => $totalDetected > 0 ? round($buyers->avg('intent_score'), 1) : 0,
            'recent_window_days' => $days,
            'executive_alert' => $this->buildExecutiveAlert($buyers, $topAffectedCars, $days),
            'top_affected_cars' => $topAffectedCars,
            'recommended_focus' => $this->buildRecommendedFocus($buyers),
            'buyers' => $buyers->take(10)->values(),
        ];
    }

    public function getCarSummary(int $companyId, int $carId, int $days = 30): array
    {
        $buyers = $this->silentBuyerRepository
            ->detectByCar($companyId, $carId, $days, 50)
            ->map(fn($buyer) => $this->enrichSilentBuyer($buyer))
            ->values();

        $visitorsCount = $buyers->count();

        return [
            'visitors_count' => $visitorsCount,
            'average_intent_score' => $visitorsCount > 0 ? round($buyers->avg('intent_score'), 1) : 0,
            'top_intent_score' => $visitorsCount > 0 ? (int) $buyers->max('intent_score') : 0,
            'total_view_duration_seconds' => (int) $buyers->sum('total_view_duration_seconds'),
            'total_interactions' => (int) $buyers->sum('interactions_count'),
            'recent_window_days' => $days,
            'recommended_action' => $this->buildRecommendedFocus($buyers),
            'summary_text' => $this->buildCarSummaryText($buyers, $days),
            'visitors' => $buyers->take(10)->values(),
        ];
    }

    protected function enrichSilentBuyer(object $buyer): array
    {
        $interactionTypes = collect(explode(',', (string) ($buyer->interaction_types ?? '')))
            ->filter()
            ->values()
            ->all();

        $intentScore = $this->calculateIntentScore($buyer, $interactionTypes);

        return [
            'company_id' => (int) $buyer->company_id,
            'car_id' => (int) $buyer->car_id,
            'visitor_id' => (string) $buyer->visitor_id,
            'brand_name' => $buyer->brand_name,
            'model_name' => $buyer->model_name,
            'version' => $buyer->version,
            'status' => $buyer->status,
            'is_resume' => (bool) $buyer->is_resume,
            'views_count' => (int) $buyer->views_count,
            'sessions_count' => (int) $buyer->sessions_count,
            'interactions_count' => (int) $buyer->interactions_count,
            'strong_interactions_count' => (int) $buyer->strong_interactions_count,
            'contact_intent_count' => (int) $buyer->contact_intent_count,
            'total_view_duration_seconds' => (int) $buyer->total_view_duration_seconds,
            'avg_view_duration_seconds' => (float) $buyer->avg_view_duration_seconds,
            'has_lead' => (bool) $buyer->has_lead,
            'intent_score' => $intentScore,
            'interaction_types' => $interactionTypes,
            'recommended_action' => $this->recommendAction($buyer, $interactionTypes, $intentScore),
            'first_view_at' => $buyer->first_view_at,
            'last_view_at' => $buyer->last_view_at,
            'last_interaction_at' => $buyer->last_interaction_at,
        ];
    }

    protected function calculateIntentScore(object $buyer, array $interactionTypes): int
    {
        $viewsScore = min(25, max(0, ((int) $buyer->views_count - 2) * 5));
        $sessionsScore = min(20, max(0, ((int) $buyer->sessions_count - 1) * 7));
        $interactionsScore = min(25, ((int) $buyer->interactions_count * 4) + ((int) $buyer->strong_interactions_count * 3));
        $durationScore = min(20, (int) floor(((int) $buyer->total_view_duration_seconds) / 30));
        $contactIntentBonus = min(10, (int) $buyer->contact_intent_count * 4);

        $score = $viewsScore + $sessionsScore + $interactionsScore + $durationScore + $contactIntentBonus;

        if (in_array('whatsapp_click', $interactionTypes, true) || in_array('call_click', $interactionTypes, true)) {
            $score += 6;
        }

        return max(0, min(100, $score));
    }

    protected function recommendAction(object $buyer, array $interactionTypes, int $intentScore): string
    {
        if (in_array('whatsapp_click', $interactionTypes, true) || in_array('call_click', $interactionTypes, true)) {
            return 'Ativar follow-up por WhatsApp';
        }

        if ($intentScore >= 80 && (int) $buyer->total_view_duration_seconds >= 240) {
            return 'Rever preço';
        }

        if (in_array('favorite', $interactionTypes, true) || in_array('share', $interactionTypes, true) || in_array('form_open', $interactionTypes, true)) {
            return 'Considerar remarketing';
        }

        return 'Rever anúncio';
    }

    protected function buildExecutiveAlert($buyers, $topAffectedCars, int $days): string
    {
        if ($buyers->isEmpty()) {
            return "Sem compradores silenciosos relevantes nos ultimos {$days} dias.";
        }

        $topCar = $topAffectedCars->first();

        if ($topCar) {
            return sprintf(
                '%d compradores silenciosos detetados nos ultimos %d dias. O carro mais afetado e %s com %d sinais fortes.',
                $buyers->count(),
                $days,
                $topCar['car_name'],
                $topCar['silent_buyers_count']
            );
        }

        return sprintf('%d compradores silenciosos detetados nos ultimos %d dias.', $buyers->count(), $days);
    }

    protected function buildRecommendedFocus($buyers): string
    {
        if ($buyers->isEmpty()) {
            return 'Monitorizar comportamento silencioso';
        }

        return $buyers->countBy('recommended_action')->sortDesc()->keys()->first() ?: 'Monitorizar comportamento silencioso';
    }

    protected function buildCarSummaryText($buyers, int $days): string
    {
        if ($buyers->isEmpty()) {
            return "Nao foram encontrados compradores silenciosos para esta viatura nos ultimos {$days} dias.";
        }

        return sprintf(
            '%d visitantes silenciosos com score medio de %d/100 e forte sinal de intencao sem lead formal.',
            $buyers->count(),
            round($buyers->avg('intent_score'))
        );
    }
}
