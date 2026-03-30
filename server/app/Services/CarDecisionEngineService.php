<?php

namespace App\Services;

use App\Models\Car;

class CarDecisionEngineService
{
    public function __construct(
        protected CarDecisionContextBuilder $contextBuilder
    ) {}

    public function decide(Car $car): array
    {
        $context = $this->contextBuilder->build($car);
        $breakdown = [
            'interest_score' => $this->scoreInterest($context),
            'conversion_score' => $this->scoreConversion($context),
            'stock_pressure_score' => $this->scoreStockPressure($context),
            'market_fit_score' => $this->scoreMarketFit($context),
            'benchmark_score' => $this->scoreBenchmark($context),
            'data_confidence_score' => $this->scoreDataConfidence($context),
        ];

        $weights = $this->resolveWeights($context, $breakdown);
        $decisionScore = $this->weightedScore($breakdown, $weights);
        $confidenceScore = $this->resolveConfidenceScore($context, $breakdown);
        $decision = $this->resolveDecision($context, $breakdown, $decisionScore, $confidenceScore);
        $expectedLeads = $this->resolveExpectedLeads($context, $breakdown, $decisionScore, $confidenceScore, $decision);

        return [
            'action' => $decision,
            'decision_score' => $decisionScore,
            'confidence_score' => $confidenceScore,
            'expected_leads' => $expectedLeads,
            'title' => $this->resolveTitle($decision),
            'summary' => $this->buildSummary($decision, $context, $breakdown),
            'reason' => $this->buildReason($decision, $context, $breakdown),
            'next_step' => $this->buildNextStep($decision, $context),
            'creative_direction' => $this->buildCreativeDirection($decision, $context),
            'total_budget' => $this->resolveBudget($decision, $decisionScore, $confidenceScore),
            'daily_budget' => $this->resolveDailyBudget($decision, $decisionScore, $confidenceScore),
            'duration_days' => $this->resolveDurationDays($decision),
            'cta_primary_label' => $decision === 'review_campaign' ? 'Rever campanha' : ($decision === 'do_not_invest' ? 'Ver detalhe' : 'Criar campanha'),
            'cta_secondary_label' => $decision === 'do_not_invest' ? 'Acompanhar evolução' : 'Ver detalhe',
            'breakdown' => $breakdown,
            'weights' => $weights,
            'context' => [
                'historical_trend' => $context['historical_trend'],
                'data_quality' => $context['data_quality'],
            ],
        ];
    }

    private function scoreInterest(array $context): int
    {
        $performance = $context['performance'];
        $trend = $context['historical_trend'];

        $score = 10;
        $score += min(40, (int) round(($performance['views_30d'] ?? 0) * 0.45));
        $score += min(30, (int) round(($performance['interactions_30d'] ?? 0) * 3.5));
        $score += match ($trend['trend_views'] ?? 'stable') {
            'rising' => 12,
            'falling' => -10,
            'stable' => 4,
            default => 0,
        };
        $score += match ($trend['trend_interest'] ?? 'stable') {
            'rising' => 8,
            'falling' => -8,
            'stable' => 3,
            default => 0,
        };

        return $this->clamp($score);
    }

    private function scoreConversion(array $context): int
    {
        $performance = $context['performance'];
        $benchmark = $context['stock_benchmark'];

        $views30 = max(1, (int) ($performance['views_30d'] ?? 0));
        $leads30 = (int) ($performance['leads_30d'] ?? 0);
        $conv30 = ($leads30 / $views30) * 100;
        $benchmarkConv = (float) ($benchmark['avg_conversion_rate'] ?? 0);

        $score = 18;
        $score += min(40, $leads30 * 14);
        $score += min(18, (int) round($conv30 * 7));

        if ($benchmarkConv > 0) {
            $score += $conv30 >= $benchmarkConv ? 12 : -10;
        }

        if (($performance['views_30d'] ?? 0) >= 80 && $leads30 === 0) {
            $score -= 20;
        }

        return $this->clamp($score);
    }

    private function scoreStockPressure(array $context): int
    {
        $days = (int) ($context['performance']['days_in_stock'] ?? 0);
        $leadsTotal = (int) ($context['performance']['leads_total'] ?? 0);

        $score = match (true) {
            $days >= 90 => 95,
            $days >= 60 => 84,
            $days >= 45 => 72,
            $days >= 30 => 58,
            $days >= 14 => 42,
            default => 25,
        };

        if ($days >= 45 && $leadsTotal === 0) {
            $score += 6;
        }

        return $this->clamp($score);
    }

    private function scoreMarketFit(array $context): int
    {
        $market = $context['market_intelligence'];
        $position = $market['market_position'] ?? 'insufficient_data';
        $delta = (float) ($market['car_price_vs_median_pct'] ?? 0);

        $score = match ($position) {
            'below_market' => 78,
            'aligned_market' => 60,
            'above_market' => 34,
            default => 50,
        };

        if ($position === 'below_market') {
            $score += min(12, (int) round(abs(min(0, $delta)) / 2));
        }

        if ($position === 'above_market') {
            $score -= min(18, (int) round(max(0, $delta - 5) * 1.3));
        }

        return $this->clamp($score);
    }

    private function scoreBenchmark(array $context): int
    {
        $benchmark = $context['stock_benchmark'];
        $performance = $context['performance'];

        if ((int) ($benchmark['comparables_count'] ?? 0) < 2) {
            return 50;
        }

        $viewsRatio = $this->safeRatio((float) ($performance['views_total'] ?? 0), (float) ($benchmark['avg_views'] ?? 0));
        $leadsRatio = $this->safeRatio((float) ($performance['leads_total'] ?? 0), (float) ($benchmark['avg_leads'] ?? 0));
        $interactionsRatio = $this->safeRatio((float) ($performance['interactions_total'] ?? 0), (float) ($benchmark['avg_interactions'] ?? 0));

        $score = 50;
        $score += (int) round(($viewsRatio - 1) * 12);
        $score += (int) round(($leadsRatio - 1) * 18);
        $score += (int) round(($interactionsRatio - 1) * 12);

        return $this->clamp($score);
    }

    private function scoreDataConfidence(array $context): int
    {
        $quality = $context['data_quality'];
        $performance = $context['performance'];

        $score = 25;
        $score += min(30, (int) round(($quality['signal_volume_score'] ?? 0) * 0.3));
        $score += min(15, (int) (($quality['internal_comparables_count'] ?? 0) * 3));
        $score += min(15, (int) (($quality['market_comparables_count'] ?? 0) * 2));
        $score += min(15, (int) floor(((int) ($performance['days_in_stock'] ?? 0)) / 7));

        if (!empty($quality['has_cold_start'])) {
            $score -= 12;
        }

        return $this->clamp($score);
    }

    private function resolveWeights(array $context, array $breakdown): array
    {
        $weights = [
            'interest_score' => 0.22,
            'conversion_score' => 0.22,
            'stock_pressure_score' => 0.16,
            'market_fit_score' => 0.16,
            'benchmark_score' => 0.14,
            'data_confidence_score' => 0.10,
        ];

        $performance = $context['performance'];
        $views = (int) ($performance['views_total'] ?? 0);
        $leads = (int) ($performance['leads_total'] ?? 0);
        $days = (int) ($performance['days_in_stock'] ?? 0);

        if ($views >= 100 && $leads === 0) {
            $weights['conversion_score'] += 0.06;
            $weights['market_fit_score'] += 0.06;
            $weights['interest_score'] -= 0.04;
            $weights['benchmark_score'] -= 0.04;
        }

        if ($views < 40) {
            $weights['interest_score'] += 0.08;
            $weights['market_fit_score'] -= 0.04;
            $weights['conversion_score'] -= 0.04;
        }

        if ($days >= 45) {
            $weights['stock_pressure_score'] += 0.08;
            $weights['interest_score'] -= 0.04;
            $weights['benchmark_score'] -= 0.04;
        }

        if (($breakdown['data_confidence_score'] ?? 0) < 50) {
            $weights['data_confidence_score'] += 0.08;
            $weights['interest_score'] -= 0.03;
            $weights['conversion_score'] -= 0.03;
            $weights['market_fit_score'] -= 0.02;
        }

        $sum = array_sum($weights);

        return collect($weights)
            ->map(fn($weight) => round($weight / $sum, 4))
            ->all();
    }

    private function weightedScore(array $breakdown, array $weights): int
    {
        $score = 0;

        foreach ($breakdown as $factor => $value) {
            $score += $value * ($weights[$factor] ?? 0);
        }

        return $this->clamp((int) round($score));
    }

    private function resolveConfidenceScore(array $context, array $breakdown): int
    {
        $quality = $context['data_quality'];
        $confidence = (int) round(
            ($breakdown['data_confidence_score'] * 0.55)
            + ((int) ($quality['internal_comparables_count'] ?? 0) >= 3 ? 12 : 4)
            + ((int) ($quality['market_comparables_count'] ?? 0) >= 5 ? 12 : 5)
            + ((int) ($quality['signal_volume_score'] ?? 0) * 0.18)
        );

        if (!empty($quality['has_cold_start'])) {
            $confidence -= 10;
        }

        return $this->clamp($confidence);
    }

    private function resolveDecision(array $context, array $breakdown, int $decisionScore, int $confidenceScore): string
    {
        $performance = $context['performance'];
        $market = $context['market_intelligence'];
        $views30 = (int) ($performance['views_30d'] ?? 0);
        $leads30 = (int) ($performance['leads_30d'] ?? 0);
        $delta = (float) ($market['car_price_vs_median_pct'] ?? 0);

        if (
            ($market['market_position'] ?? null) === 'above_market'
            && $delta > 8
            && $leads30 === 0
            && $views30 >= 60
        ) {
            return 'review_campaign';
        }

        if ($confidenceScore < 35 && $decisionScore < 55) {
            return 'do_not_invest';
        }

        if (
            $decisionScore >= 74
            && $confidenceScore >= 60
            && ($breakdown['market_fit_score'] ?? 0) >= 55
            && (($breakdown['conversion_score'] ?? 0) >= 55 || ($breakdown['benchmark_score'] ?? 0) >= 60)
        ) {
            return 'scale_ads';
        }

        if ($decisionScore >= 56 && $confidenceScore >= 45) {
            return 'test_campaign';
        }

        if ($decisionScore >= 40) {
            return 'review_campaign';
        }

        return 'do_not_invest';
    }

    private function resolveExpectedLeads(array $context, array $breakdown, int $decisionScore, int $confidenceScore, string $decision): string
    {
        if ($decision === 'do_not_invest') {
            return '0-1';
        }

        $benchmarkLeads = (float) ($context['stock_benchmark']['avg_leads'] ?? 0);
        $rawPotential = (
            ($decisionScore * 0.45)
            + ($confidenceScore * 0.25)
            + (($breakdown['interest_score'] ?? 0) * 0.15)
            + (($breakdown['conversion_score'] ?? 0) * 0.15)
        ) / 100;

        $leadEstimate = ($benchmarkLeads * 0.7) + ($rawPotential * 4.5);

        if ($decision === 'scale_ads' && $leadEstimate >= 5) {
            return '5+';
        }

        if ($leadEstimate >= 3) {
            return '3-5';
        }

        if ($leadEstimate >= 1) {
            return '1-3';
        }

        return '0-1';
    }

    private function resolveTitle(string $decision): string
    {
        return match ($decision) {
            'scale_ads' => 'Escalar investimento nesta viatura',
            'test_campaign' => 'Testar campanha com contexto favoravel',
            'review_campaign' => 'Rever campanha antes de investir mais',
            default => 'Nao investir em ads nesta fase',
        };
    }

    private function buildSummary(string $decision, array $context, array $breakdown): string
    {
        $market = $context['market_intelligence'];
        $days = (int) ($context['performance']['days_in_stock'] ?? 0);

        return match ($decision) {
            'scale_ads' => 'A viatura combina procura suficiente, fit de mercado competitivo e sinais robustos para acelerar investimento.',
            'test_campaign' => !empty($context['data_quality']['has_cold_start'])
                ? 'Ainda ha poucos dados proprios, mas benchmark e contexto de mercado justificam um teste prudente.'
                : 'Ha sinais suficientes para validar investimento com budget controlado e ganhar mais aprendizagem.',
            'review_campaign' => ($market['market_position'] ?? null) === 'above_market'
                ? 'Existe interesse, mas o preco acima do mercado esta a reduzir a capacidade de conversao.'
                : 'O carro precisa de correccoes em proposta, criativo ou segmentacao antes de receber novo investimento.',
            default => $days >= 45
                ? 'O contexto atual e demasiado fraco para justificar investimento pago enquanto o carro nao for reposicionado.'
                : 'Ainda nao existem sinais suficientes para recomendar investimento imediato.',
        };
    }

    private function buildReason(string $decision, array $context, array $breakdown): string
    {
        $market = $context['market_intelligence'];
        $trend = $context['historical_trend'];
        $delta = $market['car_price_vs_median_pct'] ?? null;

        $reasons = [];

        if (($breakdown['stock_pressure_score'] ?? 0) >= 75) {
            $reasons[] = 'tempo em stock elevado';
        }

        if (($breakdown['conversion_score'] ?? 0) <= 40 && (int) ($context['performance']['views_30d'] ?? 0) >= 60) {
            $reasons[] = 'interesse sem conversao suficiente';
        }

        if (($market['market_position'] ?? null) === 'above_market' && $delta !== null) {
            $reasons[] = sprintf('preco %.1f%% acima da mediana do mercado', $delta);
        } elseif (($market['market_position'] ?? null) === 'below_market') {
            $reasons[] = 'preco competitivo face ao mercado';
        }

        if (($trend['trend_views'] ?? null) === 'rising') {
            $reasons[] = 'procura recente em aceleracao';
        } elseif (($trend['trend_views'] ?? null) === 'falling') {
            $reasons[] = 'procura recente a perder forca';
        }

        if (empty($reasons)) {
            $reasons[] = 'contexto atual da viatura e benchmark interno do stock';
        }

        return ucfirst(implode(', ', array_slice($reasons, 0, 3))) . '.';
    }

    private function buildNextStep(string $decision, array $context): string
    {
        $market = $context['market_intelligence'];

        return match ($decision) {
            'scale_ads' => 'Aumentar investimento e ativar criativo orientado a resposta imediata.',
            'test_campaign' => 'Lancar teste curto com budget controlado e medir resposta comercial.',
            'review_campaign' => ($market['market_position'] ?? null) === 'above_market' && !empty($market['recommended_price'])
                ? 'Rever preco e criativo antes de novo spend.'
                : 'Rever criativo, CTA e proposta antes de reinvestir.',
            default => 'Rever posicionamento, preco e proposta comercial antes de considerar ads.',
        };
    }

    private function buildCreativeDirection(string $decision, array $context): ?string
    {
        return match ($decision) {
            'scale_ads' => 'Criativo comercial direto, com foco em conversao e urgencia real.',
            'test_campaign' => 'Criativo de validacao com gancho forte e proposta clara.',
            'review_campaign' => 'Criativo de revisao orientado a remover friccao e recuperar atencao.',
            default => null,
        };
    }

    private function resolveBudget(string $decision, int $decisionScore, int $confidenceScore): int
    {
        if ($decision === 'do_not_invest' || $decision === 'review_campaign') {
            return 0;
        }

        if ($decision === 'scale_ads') {
            return $decisionScore >= 82 && $confidenceScore >= 75 ? 180 : 130;
        }

        return $confidenceScore >= 60 ? 75 : 45;
    }

    private function resolveDailyBudget(string $decision, int $decisionScore, int $confidenceScore): int
    {
        $total = $this->resolveBudget($decision, $decisionScore, $confidenceScore);
        $days = max(1, $this->resolveDurationDays($decision));

        return $total > 0 ? (int) round($total / $days) : 0;
    }

    private function resolveDurationDays(string $decision): int
    {
        return match ($decision) {
            'scale_ads' => 5,
            'test_campaign' => 5,
            default => 0,
        };
    }

    private function safeRatio(float $value, float $benchmark): float
    {
        if ($benchmark <= 0) {
            return $value > 0 ? 1.1 : 1.0;
        }

        return $value / $benchmark;
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
