<?php

namespace App\Services;

use App\Models\Car;

class ContactProbabilityService
{
    private const WEIGHTS = [
        // Base: intent is the strongest behavioural signal, but not the only truth.
        'intent_score' => 0.45,
        'strong_intent_user' => 7,
        'whatsapp_click' => 4,
        'form_open' => 4,
        'lead_base' => 18,
        'lead_increment' => 6,
        'session' => 1.2,
        'unique_visitor' => 0.8,
        'time_bonus' => 8,
        'scroll_bonus' => 7,
        'campaign_click_bonus' => 5,
        'low_volume_penalty' => 12,
        'inconsistency_penalty' => 10,
    ];

    public function calculate(
        Car $car,
        array $funnelAnalysis = [],
        array $intentAnalysis = [],
        array $leadRealityGap = [],
        array $attributionSummary = []
    ): array {
        $metrics = $funnelAnalysis['metrics'] ?? [];
        $intentScore = (int) ($intentAnalysis['intent_score'] ?? 0);
        $strongIntentUsers = (int) ($intentAnalysis['strong_intent_users'] ?? 0);
        $uniqueVisitors = (int) ($intentAnalysis['unique_visitors'] ?? 0);
        $sessions = (int) ($intentAnalysis['sessions'] ?? $metrics['sessions'] ?? 0);
        $whatsappClicks = (int) ($intentAnalysis['whatsapp_clicks'] ?? $metrics['whatsapp_clicks'] ?? 0);
        $formOpens = (int) ($metrics['form_opens'] ?? 0);
        $avgTimeOnPage = (float) ($intentAnalysis['avg_time_on_page'] ?? $metrics['avg_time_on_page'] ?? 0);
        $avgScroll = (float) ($intentAnalysis['avg_scroll'] ?? $metrics['scroll'] ?? 0);
        $leads = (int) ($intentAnalysis['leads'] ?? $metrics['leads'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);

        $score = $intentScore * self::WEIGHTS['intent_score'];
        $score += min(21, $strongIntentUsers * self::WEIGHTS['strong_intent_user']);
        $score += min(16, $whatsappClicks * self::WEIGHTS['whatsapp_click']);
        $score += min(12, $formOpens * self::WEIGHTS['form_open']);
        $score += min(10, $sessions * self::WEIGHTS['session']);
        $score += min(8, $uniqueVisitors * self::WEIGHTS['unique_visitor']);

        if ($avgTimeOnPage >= 60) {
            $score += self::WEIGHTS['time_bonus'];
        }

        if ($avgScroll >= 60) {
            $score += self::WEIGHTS['scroll_bonus'];
        }

        if ($clicks >= 10) {
            $score += self::WEIGHTS['campaign_click_bonus'];
        }

        if ($leads > 0) {
            $score += min(30, self::WEIGHTS['lead_base'] + ($leads * self::WEIGHTS['lead_increment']));
        }

        if ($sessions < 3 && $uniqueVisitors < 3 && $leads === 0) {
            $score -= self::WEIGHTS['low_volume_penalty'];
        }

        if ($intentScore >= 65 && $strongIntentUsers === 0 && $whatsappClicks === 0 && $leads === 0) {
            $score -= self::WEIGHTS['inconsistency_penalty'];
        }

        if (($leadRealityGap['primary_gap_state'] ?? null) === 'tracking_gap') {
            $score -= self::WEIGHTS['inconsistency_penalty'];
        }

        $score = max(0, min(100, (int) round($score)));
        $state = $this->resolveContactState($score, $intentScore, $strongIntentUsers, $whatsappClicks, $leads, (string) ($leadRealityGap['primary_gap_state'] ?? ''));

        return [
            'score' => $score,
            'level' => $this->resolveLevel($score),
            'state' => $state,
            'state_label' => $this->resolveStateLabel($state),
            'summary' => $this->resolveSummary($state),
            'diagnosis' => $this->buildDiagnosis($state, [
                'intent_score' => $intentScore,
                'strong_intent_users' => $strongIntentUsers,
                'whatsapp_clicks' => $whatsappClicks,
                'form_opens' => $formOpens,
                'sessions' => $sessions,
                'unique_visitors' => $uniqueVisitors,
                'leads' => $leads,
                'avg_time_on_page' => $avgTimeOnPage,
                'avg_scroll' => $avgScroll,
            ], $leadRealityGap),
            'inputs' => [
                'intent_score' => $intentScore,
                'strong_intent_users' => $strongIntentUsers,
                'unique_visitors' => $uniqueVisitors,
                'sessions' => $sessions,
                'whatsapp_clicks' => $whatsappClicks,
                'form_opens' => $formOpens,
                'avg_time_on_page' => $avgTimeOnPage,
                'avg_scroll' => $avgScroll,
                'leads' => $leads,
                'campaign_clicks' => $clicks,
                'attribution_rows' => count($attributionSummary['rows'] ?? []),
            ],
        ];
    }

    public function primaryRecommendedAction(array $contactProbability, ?array $recommendations = null, ?string $decision = null): array
    {
        $primary = $recommendations['primary_action'] ?? null;

        if (is_array($primary) && ($primary['type'] ?? null) !== 'no_action_needed') {
            return [
                'type' => (string) ($primary['type'] ?? 'review_campaign'),
                'label' => $this->actionLabel((string) ($primary['type'] ?? 'review_campaign')),
                'reason' => (string) ($primary['reason'] ?? $contactProbability['summary'] ?? 'Existe uma oportunidade de otimização.'),
                'next_step' => (string) ($primary['next_step'] ?? 'Rever a campanha e executar a próxima ação recomendada.'),
                'action_key' => $primary['action_key'] ?? null,
                'confidence' => (int) ($primary['confidence'] ?? $contactProbability['score'] ?? 60),
            ];
        }

        $state = (string) ($contactProbability['state'] ?? 'weak_contact_signal');
        $score = (int) ($contactProbability['score'] ?? 0);

        return match ($state) {
            'no_real_interest' => [
                'type' => 'pause_ad',
                'label' => 'Pausar anúncio',
                'reason' => 'O carro ainda não mostra sinais reais de contacto.',
                'next_step' => 'Reduzir investimento até existir uma proposta ou criativo mais forte.',
                'action_key' => 'pause_campaign',
                'confidence' => max(55, min(85, 100 - $score)),
            ],
            'high_interest_low_conversion' => [
                'type' => 'improve_landing',
                'label' => 'Melhorar página',
                'reason' => 'Há interesse, mas o contacto não está a acontecer.',
                'next_step' => 'Reforçar preço, CTA e prova visual para reduzir fricção.',
                'action_key' => null,
                'confidence' => max(65, min(92, $score)),
            ],
            'healthy_contact_flow', 'strong_contact_signal' => [
                'type' => 'scale_adset',
                'label' => 'Escalar conjunto',
                'reason' => 'O carro mostra sinais consistentes de contacto.',
                'next_step' => 'Aumentar orçamento ou duplicar o conjunto com melhor sinal.',
                'action_key' => 'duplicate_campaign',
                'confidence' => max(65, min(92, $score)),
            ],
            default => [
                'type' => 'test_creative',
                'label' => 'Gerar novo criativo',
                'reason' => 'O carro já capta alguma atenção, mas ainda não gera contacto suficiente.',
                'next_step' => 'Testar novo criativo com foco em preço, benefício ou urgência.',
                'action_key' => 'generate_new_copy',
                'confidence' => max(50, min(80, $score)),
            ],
        };
    }

    private function resolveLevel(int $score): string
    {
        return match (true) {
            $score >= 85 => 'very_high',
            $score >= 65 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'very_low',
        };
    }

    private function resolveContactState(int $score, int $intentScore, int $strongIntentUsers, int $whatsappClicks, int $leads, string $gapState): string
    {
        if ($gapState === 'healthy_flow' && $leads > 0 && $score >= 55) {
            return 'healthy_contact_flow';
        }

        if ($intentScore >= 65 && $strongIntentUsers >= 1 && $leads === 0) {
            return 'high_interest_low_conversion';
        }

        if ($score >= 65 || $whatsappClicks >= 2 || $leads > 0) {
            return 'strong_contact_signal';
        }

        if ($score < 25 && $whatsappClicks === 0 && $strongIntentUsers === 0 && $leads === 0) {
            return 'no_real_interest';
        }

        return 'weak_contact_signal';
    }

    private function resolveStateLabel(string $state): string
    {
        return [
            'no_real_interest' => 'Sem interesse real',
            'weak_contact_signal' => 'Sinal de contacto fraco',
            'strong_contact_signal' => 'Sinal forte de contacto',
            'high_interest_low_conversion' => 'Interesse sem conversão',
            'healthy_contact_flow' => 'Fluxo saudável de contacto',
        ][$state] ?? 'Sinal em avaliação';
    }

    private function resolveSummary(string $state): string
    {
        return [
            'no_real_interest' => 'O carro recebe pouco sinal útil para justificar mais investimento.',
            'weak_contact_signal' => 'Há algum interesse, mas ainda não é suficiente para prever contacto.',
            'strong_contact_signal' => 'O comportamento mostra probabilidade real de gerar contacto.',
            'high_interest_low_conversion' => 'Há interesse relevante, mas o contacto ainda não aconteceu.',
            'healthy_contact_flow' => 'O carro está a transformar interesse em contacto comercial.',
        ][$state] ?? 'A recolher sinais para consolidar a leitura.';
    }

    private function buildDiagnosis(string $state, array $signals, array $leadRealityGap): array
    {
        $items = [];

        if ($state === 'high_interest_low_conversion') {
            $items[] = 'Há interesse relevante, mas o contacto ainda não aconteceu.';
            $items[] = 'O volume de tentativas é moderado e ainda sem captura formal.';
        } elseif ($state === 'no_real_interest') {
            $items[] = 'O tráfego atual ainda não mostra intenção comercial suficiente.';
            $items[] = 'Não há tentativas claras de contacto neste período.';
        } elseif ($state === 'healthy_contact_flow') {
            $items[] = 'O carro já transforma interesse em contacto comercial.';
            $items[] = 'Os sinais de navegação e contacto estão consistentes.';
        } elseif ($state === 'strong_contact_signal') {
            $items[] = 'Há comportamento compatível com intenção real de contacto.';
            $items[] = 'As tentativas e sessões indicam oportunidade comercial.';
        } else {
            $items[] = 'Há sinais iniciais, mas ainda sem força suficiente para decisão agressiva.';
            $items[] = 'O criativo pode estar a gerar curiosidade, mas não decisão.';
        }

        if (($leadRealityGap['primary_gap_state'] ?? null) === 'tracking_gap') {
            $items[] = 'Os sinais não estão totalmente coerentes; convém validar tracking.';
        } elseif ((int) ($signals['leads'] ?? 0) === 0 && (int) ($signals['whatsapp_clicks'] ?? 0) > 0) {
            $items[] = 'Há tentativa de contacto sem lead formal registada.';
        } elseif ((float) ($signals['avg_time_on_page'] ?? 0) >= 60 || (float) ($signals['avg_scroll'] ?? 0) >= 60) {
            $items[] = 'A página está a reter atenção acima do normal.';
        }

        return array_slice(array_values(array_unique($items)), 0, 3);
    }

    private function actionLabel(string $type): string
    {
        return [
            'pause_ad' => 'Pausar anúncio',
            'pause_adset' => 'Pausar conjunto',
            'pause_campaign' => 'Pausar campanha',
            'scale_adset' => 'Escalar conjunto',
            'duplicate_campaign' => 'Duplicar campanha',
            'improve_landing' => 'Melhorar página',
            'improve_cta' => 'Ajustar CTA',
            'fix_contact_capture' => 'Rever contacto',
            'test_creative' => 'Gerar novo criativo',
            'test_audience' => 'Testar novo público',
            'test_offer' => 'Testar nova oferta',
        ][$type] ?? 'Executar ação';
    }
}
