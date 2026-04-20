<?php

namespace App\Services;

use App\Models\Car;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartAdsOptimizerService
{
    public function __construct(
        protected CarFunnelAnalyzer $carFunnelAnalyzer,
        protected AttributionService $attributionService,
        protected IntentAnalysisService $intentAnalysisService,
        protected AdsGuardrailService $adsGuardrailService,
        protected LeadRealityGapService $leadRealityGapService,
    ) {}

    public function generateRecommendations(int $carId): array
    {
        $car = Car::query()->find($carId);

        if (!$car) {
            return $this->emptyRecommendations('no_action_needed');
        }

        $analysis = $this->carFunnelAnalyzer->analyzeForCar($car);
        $intelligence = $this->intentAnalysisService->analyzeForCar($car);
        $leadRealityGap = $this->leadRealityGapService->analyzeForCar($car, $analysis, $intelligence);
        $guardrails = $this->adsGuardrailService->evaluate($car, $analysis, null, null, $intelligence, $leadRealityGap);

        return $this->buildRecommendations(
            $car,
            $analysis,
            $intelligence,
            $leadRealityGap,
            $guardrails,
            null
        );
    }

    public function generateRecommendationsForCar(
        Car $car,
        array $analysis,
        array $intelligence,
        array $leadRealityGap,
        array $guardrails,
        ?string $decision = null
    ): array {
        return $this->buildRecommendations($car, $analysis, $intelligence, $leadRealityGap, $guardrails, $decision);
    }

    private function buildRecommendations(
        Car $car,
        array $analysis,
        array $intelligence,
        array $leadRealityGap,
        array $guardrails,
        ?string $decision
    ): array {
        $targets = $this->buildTargetContexts($car, $analysis, $intelligence);
        $guardrailTypes = collect($guardrails)->pluck('type')->filter()->values()->all();
        $recommendations = [
            'cut' => [],
            'scale' => [],
            'fix' => [],
            'test' => [],
        ];

        $cut = $this->resolveCutRecommendation($targets, $guardrailTypes);
        if ($cut) {
            $recommendations['cut'][] = $cut;
        }

        $scale = $this->resolveScaleRecommendation($targets, $decision);
        if ($scale && empty($recommendations['cut'])) {
            $recommendations['scale'][] = $scale;
        }

        $fix = $this->resolveFixRecommendation($targets, $leadRealityGap, $guardrailTypes, $decision);
        if ($fix) {
            $recommendations['fix'][] = $fix;
        }

        $test = $this->resolveTestRecommendation($targets, $analysis, $guardrailTypes);
        if ($test) {
            $recommendations['test'][] = $test;
        }

        if ($this->hasRecommendations($recommendations)) {
            return [
                ...$recommendations,
                'primary_action' => $this->resolvePrimaryAction($recommendations),
            ];
        }

        return [
            ...$recommendations,
            'state' => 'no_action_needed',
            'primary_action' => [
                'type' => 'no_action_needed',
                'reason' => 'Nenhuma otimização prioritária',
                'why' => 'Os sinais atuais não justificam uma mudança imediata no anúncio ou conjunto.',
                'next_step' => 'Manter monitorização e voltar a avaliar após recolher mais dados.',
                'action_key' => null,
                'confidence' => 80,
            ],
        ];
    }

    private function buildTargetContexts(Car $car, array $analysis, array $intelligence): Collection
    {
        $period = $analysis['period'] ?? [];
        $from = $period['from'] ?? now()->subDays(6)->toDateString();
        $to = $period['to'] ?? now()->toDateString();
        $metrics = $analysis['metrics'] ?? [];
        $globalFrequency = $this->resolveFrequency($car, $from, $to, $metrics);
        $attributionRows = collect($this->attributionService->getAttributionSummary($car->id)['rows'] ?? []);

        $performanceRows = DB::table('campaign_car_metrics_daily as daily')
            ->join('car_ad_campaigns as mapping', 'mapping.id', '=', 'daily.mapping_id')
            ->where('daily.company_id', $car->company_id)
            ->where('daily.car_id', $car->id)
            ->whereBetween('daily.date', [$from, $to])
            ->groupBy('mapping.id', 'mapping.campaign_id', 'mapping.adset_id', 'mapping.ad_id')
            ->orderBy('mapping.id')
            ->get([
                'mapping.id as mapping_id',
                'mapping.campaign_id',
                'mapping.adset_id',
                'mapping.ad_id',
                DB::raw('COALESCE(SUM(daily.impressions), 0) as impressions'),
                DB::raw('COALESCE(SUM(daily.clicks), 0) as clicks'),
                DB::raw('ROUND(COALESCE(SUM(daily.spend_normalized), 0), 2) as spend_normalized'),
            ]);

        $contexts = $performanceRows->map(function ($row) use ($attributionRows, $globalFrequency, $intelligence) {
            $attribution = $attributionRows->first(function (array $item) use ($row) {
                return ($item['campaign_id'] ?? null) === $row->campaign_id
                    && ($item['adset_id'] ?? null) === $row->adset_id
                    && ($item['ad_id'] ?? null) === $row->ad_id;
            }) ?? [];

            $impressions = (int) ($row->impressions ?? 0);
            $clicks = (int) ($row->clicks ?? 0);
            $spend = round((float) ($row->spend_normalized ?? 0), 2);
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null;
            $strongIntentUsers = (int) ($attribution['strong_intent_users'] ?? 0);
            $whatsappClicks = (int) ($attribution['whatsapp_clicks'] ?? 0);
            $leads = (int) ($attribution['leads'] ?? 0);
            $targetIntentScore = $this->resolveTargetIntentScore($strongIntentUsers, $whatsappClicks, $leads);

            return [
                'mapping_id' => (int) $row->mapping_id,
                'campaign_id' => $row->campaign_id,
                'adset_id' => $row->adset_id,
                'ad_id' => $row->ad_id,
                'target_level' => $this->resolveTargetLevel($row->ad_id, $row->adset_id),
                'target_id' => $this->resolveTargetId($row->ad_id, $row->adset_id, $row->campaign_id),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend' => $spend,
                'ctr' => $ctr,
                'frequency' => $globalFrequency,
                'leads' => $leads,
                'whatsapp_clicks' => $whatsappClicks,
                'strong_intent_users' => $strongIntentUsers,
                'intent_score' => $targetIntentScore,
                'global_intent_score' => (int) ($intelligence['intent_score'] ?? 0),
                'global_strong_intent_users' => (int) ($intelligence['strong_intent_users'] ?? 0),
            ];
        });

        if ($contexts->isNotEmpty()) {
            return $contexts;
        }

        $fallbackRows = $attributionRows->map(function (array $row) use ($globalFrequency, $metrics, $intelligence) {
            $strongIntentUsers = (int) ($row['strong_intent_users'] ?? 0);
            $whatsappClicks = (int) ($row['whatsapp_clicks'] ?? 0);
            $leads = (int) ($row['leads'] ?? 0);

            return [
                'mapping_id' => null,
                'campaign_id' => $row['campaign_id'] ?? null,
                'adset_id' => $row['adset_id'] ?? null,
                'ad_id' => $row['ad_id'] ?? null,
                'target_level' => $this->resolveTargetLevel($row['ad_id'] ?? null, $row['adset_id'] ?? null),
                'target_id' => $this->resolveTargetId($row['ad_id'] ?? null, $row['adset_id'] ?? null, $row['campaign_id'] ?? null),
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'spend' => round((float) ($metrics['spend_normalized'] ?? 0), 2),
                'ctr' => $metrics['ctr'] ?? null,
                'frequency' => $globalFrequency,
                'leads' => $leads,
                'whatsapp_clicks' => $whatsappClicks,
                'strong_intent_users' => $strongIntentUsers,
                'intent_score' => $this->resolveTargetIntentScore($strongIntentUsers, $whatsappClicks, $leads),
                'global_intent_score' => (int) ($intelligence['intent_score'] ?? 0),
                'global_strong_intent_users' => (int) ($intelligence['strong_intent_users'] ?? 0),
            ];
        });

        return $fallbackRows;
    }

    private function resolveCutRecommendation(Collection $targets, array $guardrailTypes): ?array
    {
        $candidate = $targets
            ->filter(function (array $target) use ($guardrailTypes) {
                $highSpendLowIntent = $target['spend'] >= 20
                    && (int) $target['leads'] === 0
                    && (int) $target['strong_intent_users'] === 0
                    && (int) $target['intent_score'] < 40;
                $fatigue = ($target['frequency'] ?? 0) > 2.5
                    && ($target['ctr'] ?? 0) !== null
                    && (float) $target['ctr'] <= 1.2;

                return $highSpendLowIntent
                    || $fatigue
                    || in_array('high_spend_low_intent', $guardrailTypes, true)
                    || in_array('spend_without_qualified_lead', $guardrailTypes, true)
                    || in_array('creative_fatigue', $guardrailTypes, true);
            })
            ->sortByDesc(fn (array $target) => [
                $target['spend'],
                -((int) $target['intent_score']),
            ])
            ->first();

        if (!$candidate) {
            return null;
        }

        $reason = ((float) ($candidate['frequency'] ?? 0) > 2.5 && (float) ($candidate['ctr'] ?? 99) <= 1.2)
            ? 'creative_fatigue'
            : 'high_spend_low_intent';

        return [
            ...$this->buildRecommendationEnvelope($candidate, [
                'type' => 'pause_'.$candidate['target_level'],
                'reason_key' => $reason,
                'reason' => 'Alto gasto sem intenção',
                'why' => $reason === 'creative_fatigue'
                    ? 'CTR baixo e frequência alta indicam fadiga criativa e baixa relevância do anúncio'
                    : 'Alto spend sem leads nem intenção indica baixa relevância do público ou da mensagem',
                'next_step' => 'Pausar este anúncio imediatamente e redirecionar orçamento para o melhor conjunto ou para um novo teste criativo.',
                'action_key' => 'pause_campaign',
                'impact' => [
                    'estimated_loss' => round((float) $candidate['spend'], 2),
                    'urgency' => 'high',
                ],
                'confidence' => min(95, max(70, (int) round(55 + $candidate['spend'] + (float) ($candidate['frequency'] ?? 0) * 8))),
            ]),
        ];
    }

    private function resolveScaleRecommendation(Collection $targets, ?string $decision): ?array
    {
        $candidate = $targets
            ->filter(function (array $target) use ($decision) {
                return (int) $target['strong_intent_users'] >= 2
                    && (($target['ctr'] ?? 0) >= 1.5)
                    && ((int) $target['leads'] > 0 || (int) $target['intent_score'] >= 70)
                    && !in_array($decision, ['PARAR', 'CORRIGIR'], true);
            })
            ->sortByDesc(fn (array $target) => [
                $target['leads'],
                $target['strong_intent_users'],
                $target['ctr'] ?? 0,
            ])
            ->first();

        if (!$candidate) {
            return null;
        }

        return [
            ...$this->buildRecommendationEnvelope($candidate, [
                'type' => 'scale_'.$candidate['target_level'],
                'reason_key' => 'high_intent_performance',
                'reason' => 'Anúncio com intenção forte e potencial de escala',
                'why' => 'CTR forte e sinais reais de intenção mostram que este target já está a captar procura qualificada.',
                'next_step' => 'Duplicar este conjunto e aumentar orçamento em 20% para acelerar volume sem perder controlo.',
                'action_key' => 'duplicate_campaign',
                'impact' => [
                    'estimated_gain' => max(1, (int) $candidate['strong_intent_users'] + (int) $candidate['leads']),
                    'urgency' => ((int) $candidate['leads'] > 0 || (int) $candidate['strong_intent_users'] >= 3) ? 'high' : 'medium',
                ],
                'confidence' => min(92, max(72, (int) round(50 + ($candidate['strong_intent_users'] * 8) + ($candidate['leads'] * 10) + ($candidate['ctr'] ?? 0) * 4))),
            ]),
        ];
    }

    private function resolveFixRecommendation(
        Collection $targets,
        array $leadRealityGap,
        array $guardrailTypes,
        ?string $decision
    ): ?array {
        $primaryState = (string) ($leadRealityGap['primary_gap_state'] ?? '');
        $highIntentNoConversion = in_array($primaryState, ['decision_friction'], true)
            || in_array('decision_friction', $guardrailTypes, true);
        $contactLoss = in_array($primaryState, ['contact_capture_failure'], true)
            || in_array('contact_capture_failure', $guardrailTypes, true);

        if (!$highIntentNoConversion && !$contactLoss && $decision !== 'CORRIGIR') {
            return null;
        }

        $candidate = $targets
            ->filter(function (array $target) use ($highIntentNoConversion, $contactLoss) {
                if ($contactLoss) {
                    return (int) $target['whatsapp_clicks'] >= 3 && (int) $target['leads'] === 0;
                }

                return (int) $target['strong_intent_users'] >= 2 && (int) $target['leads'] === 0;
            })
            ->sortByDesc(fn (array $target) => [
                $target['strong_intent_users'],
                $target['whatsapp_clicks'],
                $target['spend'],
            ])
            ->first();

        if (!$candidate) {
            return null;
        }

        if ($contactLoss) {
            return [
                ...$this->buildRecommendationEnvelope($candidate, [
                    'type' => 'fix_contact_flow',
                    'reason_key' => 'contact_loss',
                    'reason' => 'Há intenção, mas o contacto está a perder-se',
                    'why' => 'Muitos cliques em WhatsApp sem lead sugerem perda de contacto ou fricção no momento final da conversão.',
                    'next_step' => 'Validar o fluxo de contacto e reforçar a proposta de resposta imediata no anúncio e na landing.',
                    'action_key' => null,
                    'impact' => [
                        'urgency' => 'high',
                    ],
                    'confidence' => min(92, max(75, 58 + ((int) $candidate['whatsapp_clicks'] * 6))),
                ]),
            ];
        }

        return [
            ...$this->buildRecommendationEnvelope($candidate, [
                'type' => 'improve_landing',
                'reason_key' => 'high_intent_no_conversion',
                'reason' => 'Intenção alta sem conversão',
                'why' => 'Intenção alta sem leads indica fricção na conversão ou proposta fraca na página do carro.',
                'next_step' => 'Melhorar página do carro com preço mais visível, prova de valor e CTA direto para contacto.',
                'action_key' => null,
                'impact' => [
                    'urgency' => ((int) $candidate['strong_intent_users'] >= 3) ? 'high' : 'medium',
                ],
                'confidence' => min(92, max(74, 60 + ((int) $candidate['strong_intent_users'] * 8))),
            ]),
        ];
    }

    private function resolveTestRecommendation(Collection $targets, array $analysis, array $guardrailTypes): ?array
    {
        $lowCtrTarget = $targets
            ->filter(fn (array $target) => ($target['ctr'] ?? 99) < 1.0 && (int) $target['clicks'] > 0)
            ->sortByDesc('spend')
            ->first();

        if ($lowCtrTarget) {
            return [
                ...$this->buildRecommendationEnvelope($lowCtrTarget, [
                    'type' => 'test_creative',
                    'reason_key' => 'low_ctr',
                    'reason' => 'Criativo com CTR abaixo do esperado',
                    'why' => 'CTR baixo indica que o ângulo atual não está a gerar atenção suficiente no público certo.',
                    'next_step' => 'Criar novo criativo com foco em urgência, preço ou benefício principal desta viatura.',
                    'action_key' => 'generate_new_copy',
                    'impact' => [
                        'urgency' => 'low',
                    ],
                    'hypothesis' => 'new angle highlighting price',
                    'based_on' => 'low_ctr',
                    'confidence' => in_array('creative_fatigue', $guardrailTypes, true) ? 72 : 65,
                ]),
            ];
        }

        $highFrequencyTarget = $targets
            ->filter(fn (array $target) => ((float) ($target['frequency'] ?? 0)) > 2.5)
            ->sortByDesc('spend')
            ->first();

        if ($highFrequencyTarget) {
            return [
                ...$this->buildRecommendationEnvelope($highFrequencyTarget, [
                    'type' => 'test_creative',
                    'reason_key' => 'high_frequency',
                    'reason' => 'Sinais de fadiga criativa',
                    'why' => 'Frequência alta sugere saturação do mesmo criativo junto da audiência atual.',
                    'next_step' => 'Criar novo criativo com ângulo diferente para refrescar a campanha sem mexer no target.',
                    'action_key' => 'generate_new_copy',
                    'impact' => [
                        'urgency' => 'low',
                    ],
                    'hypothesis' => 'new angle reducing creative fatigue',
                    'based_on' => 'high_frequency',
                    'confidence' => 68,
                ]),
            ];
        }

        $lowReachTarget = $targets
            ->filter(fn (array $target) => (int) ($target['impressions'] ?? 0) < 500)
            ->sortByDesc('spend')
            ->first();

        if ($lowReachTarget) {
            return [
                ...$this->buildRecommendationEnvelope($lowReachTarget, [
                    'type' => 'test_audience',
                    'reason_key' => 'low_reach',
                    'reason' => 'Alcance baixo para o target atual',
                    'why' => 'Pouca entrega limita aprendizagem e reduz a hipótese de encontrar procura qualificada.',
                    'next_step' => 'Testar novo público ou abrir segmentação para aumentar alcance qualificado.',
                    'action_key' => null,
                    'impact' => [
                        'urgency' => 'low',
                    ],
                    'hypothesis' => 'new audience to unlock more qualified reach',
                    'based_on' => 'low_reach',
                    'confidence' => 62,
                ]),
            ];
        }

        return null;
    }

    private function resolveFrequency(Car $car, string $from, string $to, array $metrics): ?float
    {
        $reach = (int) round((float) DB::table('meta_audience_insights')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->sum('reach'));

        $impressions = (int) ($metrics['impressions'] ?? 0);

        return $reach > 0 ? round($impressions / $reach, 2) : null;
    }

    private function resolveTargetIntentScore(int $strongIntentUsers, int $whatsappClicks, int $leads): int
    {
        $score = 0;

        if ($strongIntentUsers >= 2) {
            $score += 60;
        } elseif ($strongIntentUsers >= 1) {
            $score += 35;
        }

        if ($whatsappClicks >= 1) {
            $score += 20;
        }

        if ($whatsappClicks >= 3) {
            $score += 10;
        }

        if ($leads >= 1) {
            $score += 20;
        }

        return min(100, $score);
    }

    private function resolveTargetLevel(?string $adId, ?string $adsetId): string
    {
        if (!empty($adId)) {
            return 'ad';
        }

        if (!empty($adsetId)) {
            return 'adset';
        }

        return 'campaign';
    }

    private function resolveTargetId(?string $adId, ?string $adsetId, ?string $campaignId): ?string
    {
        return $adId ?: ($adsetId ?: $campaignId);
    }

    private function hasRecommendations(array $recommendations): bool
    {
        return !empty($recommendations['cut'])
            || !empty($recommendations['scale'])
            || !empty($recommendations['fix'])
            || !empty($recommendations['test']);
    }

    private function emptyRecommendations(string $state): array
    {
        return [
            'cut' => [],
            'scale' => [],
            'fix' => [],
            'test' => [],
            'state' => $state,
            'primary_action' => [
                'type' => 'no_action_needed',
                'reason' => 'Nenhuma otimização prioritária',
                'why' => 'Os sinais atuais ainda não justificam uma ação operacional imediata.',
                'next_step' => 'Continuar a recolher dados e reavaliar o carro no próximo ciclo.',
                'action_key' => null,
                'confidence' => 80,
            ],
        ];
    }

    private function buildRecommendationEnvelope(array $target, array $payload): array
    {
        return [
            'type' => $payload['type'],
            'target_level' => $target['target_level'],
            'target_id' => (string) $target['target_id'],
            'reason' => $payload['reason'],
            'data' => [
                'spend' => round((float) ($target['spend'] ?? 0), 2),
                'leads' => (int) ($target['leads'] ?? 0),
                'intent_score' => (int) ($target['intent_score'] ?? 0),
                'ctr' => $target['ctr'] !== null ? round((float) $target['ctr'], 2) : null,
                'frequency' => $target['frequency'] !== null ? round((float) $target['frequency'], 2) : null,
            ],
            'impact' => $payload['impact'],
            'why' => $payload['why'],
            'next_step' => $payload['next_step'],
            'action_key' => $payload['action_key'],
            'confidence' => (int) $payload['confidence'],
            ...collect($payload)->only(['hypothesis', 'based_on'])->filter(fn ($value) => $value !== null)->all(),
        ];
    }

    private function resolvePrimaryAction(array $recommendations): ?array
    {
        foreach (['cut', 'scale', 'fix', 'test'] as $bucket) {
            if (!empty($recommendations[$bucket][0])) {
                return $recommendations[$bucket][0];
            }
        }

        return null;
    }
}
