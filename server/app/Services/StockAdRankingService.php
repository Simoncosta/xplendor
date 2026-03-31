<?php

namespace App\Services;

use App\Models\Car;

class StockAdRankingService
{
    public function __construct(
        protected CarDecisionEngineService $carDecisionEngineService
    ) {}

    public function rankActiveCarsForAds(int $companyId): array
    {
        $cars = Car::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['brand:id,name', 'model:id,name'])
            ->orderByDesc('created_at')
            ->get();

        if ($cars->isEmpty()) {
            return ['cars_ranked_for_ads' => []];
        }

        $ranked = $cars
            ->map(function (Car $car) {
                $decision = $this->carDecisionEngineService->decide($car);
                $priorityScore = $this->resolvePriorityScore($decision);
                $investmentLabel = $this->resolveInvestmentLabel($decision['action'] ?? null, $priorityScore);

                return [
                    'car_id' => $car->id,
                    'car_name' => trim(sprintf(
                        '%s %s %s',
                        $car->brand?->name ?? '',
                        $car->model?->name ?? '',
                        $car->version ?? ''
                    )),
                    'price_gross' => $car->promo_price_gross && $car->price_gross && $car->promo_price_gross < $car->price_gross
                        ? (float) $car->promo_price_gross
                        : ($car->price_gross !== null ? (float) $car->price_gross : null),
                    'priority_score' => $priorityScore,
                    'confidence_score' => (int) ($decision['confidence_score'] ?? 0),
                    'investment_label' => $investmentLabel,
                    'reason' => $decision['reason'] ?? null,
                    'why_now' => $decision['why_now'] ?? ($decision['summary'] ?? null),
                    'risk_note' => $decision['risk_note'] ?? $this->resolveRiskNote($decision),
                    'smartads_decision' => $decision['action'] ?? null,
                ];
            })
            ->sort(function (array $a, array $b) {
                $orderA = $this->resolveActionOrder($a['smartads_decision'] ?? null);
                $orderB = $this->resolveActionOrder($b['smartads_decision'] ?? null);

                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }

                if ($b['priority_score'] !== $a['priority_score']) {
                    return $b['priority_score'] <=> $a['priority_score'];
                }

                return $b['confidence_score'] <=> $a['confidence_score'];
            })
            ->values()
            ->map(function (array $item, int $index) {
                $item['position'] = $index + 1;
                return $item;
            })
            ->all();

        return [
            'cars_ranked_for_ads' => $ranked,
            'ready' => array_values(array_filter($ranked, fn($car) => ($car['smartads_decision'] ?? null) === 'scale_ads')),
            'test' => array_values(array_filter($ranked, fn($car) => ($car['smartads_decision'] ?? null) === 'test_campaign')),
            'exploration' => array_values(array_filter($ranked, fn($car) => ($car['smartads_decision'] ?? null) === 'test_campaign_seed')),
            'review' => array_values(array_filter($ranked, fn($car) => ($car['smartads_decision'] ?? null) === 'review_campaign')),
            'avoid' => array_values(array_filter($ranked, fn($car) => ($car['smartads_decision'] ?? null) === 'do_not_invest')),
        ];
    }

    private function resolvePriorityScore(array $decision): int
    {
        $base = (int) ($decision['decision_score'] ?? 0);
        $confidence = (int) ($decision['confidence_score'] ?? 0);
        $action = $decision['action'] ?? null;

        $actionAdjustment = match ($action) {
            'scale_ads' => 10,
            'test_campaign' => 4,
            'test_campaign_seed' => 1,
            'review_campaign' => -8,
            'do_not_invest' => -20,
            default => 0,
        };

        $score = $base + $actionAdjustment + (int) round(($confidence - 50) * 0.2);

        if (
            $action === 'test_campaign_seed'
            && str_contains(strtolower((string) ($decision['reason'] ?? '')), 'preco competitivo')
        ) {
            $score += 15;
        }

        return max(0, min(100, $score));
    }

    private function resolveInvestmentLabel(?string $action, int $priorityScore): string
    {
        if ($action === 'do_not_invest' || $priorityScore < 35) {
            return 'avoid_investment';
        }

        if ($priorityScore >= 75 || $action === 'scale_ads') {
            return 'high_priority';
        }

        if ($priorityScore >= 55 || $action === 'test_campaign') {
            return 'medium_priority';
        }

        if ($action === 'test_campaign_seed') {
            return 'medium_priority';
        }

        return 'low_priority';
    }

    private function resolveActionOrder(?string $action): int
    {
        return match ($action) {
            'scale_ads' => 1,
            'test_campaign' => 2,
            'test_campaign_seed' => 3,
            'review_campaign' => 4,
            'do_not_invest' => 5,
            default => 6,
        };
    }

    private function resolveRiskNote(array $decision): ?string
    {
        $reason = strtolower((string) ($decision['reason'] ?? ''));
        $confidence = (int) ($decision['confidence_score'] ?? 0);

        if (str_contains($reason, 'acima da mediana')) {
            return 'Preco acima do mercado pode limitar retorno.';
        }

        if ($confidence < 45) {
            return 'Decisao ainda com pouca base de dados.';
        }

        if (($decision['action'] ?? null) === 'review_campaign') {
            return 'Precisa de correcao antes de receber mais budget.';
        }

        return null;
    }
}
