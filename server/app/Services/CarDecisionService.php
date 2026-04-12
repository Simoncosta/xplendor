<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAdCampaign;
use Illuminate\Support\Collection;

class CarDecisionService
{
    private const DECISION_THRESHOLDS = [
        'car' => [
            'mature_views' => 30,
            'mature_clicks' => 40,
            'mature_spend' => 25.0,
            'kill_score' => 30,
            'warning_intent_views' => 30,
            'strong_scroll' => 55.0,
            'strong_time_on_page' => 45.0,
            'warning_form_opens' => 2,
        ],
        'motorhome' => [
            'mature_views' => 55,
            'mature_clicks' => 65,
            'mature_spend' => 45.0,
            'kill_score' => 25,
            'warning_intent_views' => 45,
            'strong_scroll' => 60.0,
            'strong_time_on_page' => 70.0,
            'warning_form_opens' => 4,
        ],
    ];

    public function __construct(
        protected CarFunnelAnalyzer $carFunnelAnalyzer,
        protected AdsGuardrailService $adsGuardrailService,
        protected IntentAnalysisService $intentAnalysisService,
        protected LeadRealityGapService $leadRealityGapService,
    ) {}

    public function resolve(Car $car, ?string $from = null, ?string $to = null): array
    {
        $analysis = $this->carFunnelAnalyzer->analyzeForCar($car, $from, $to);
        $intelligence = $this->intentAnalysisService->analyzeForCar($car, $from, $to);
        $leadRealityGap = $this->leadRealityGapService->analyzeForCar($car, $analysis, $intelligence, $from, $to);

        return $this->buildDecisionPayload($car, $analysis, $intelligence, $leadRealityGap, $from, $to);
    }

    public function resolveForCars(Collection $cars, ?string $from = null, ?string $to = null): array
    {
        if ($cars->isEmpty()) {
            return [];
        }

        $analyses = $this->carFunnelAnalyzer->analyzeForCars($cars, $from, $to);
        $intentAnalyses = $this->intentAnalysisService->analyzeForCars($cars, $from, $to);
        $leadRealityGaps = $this->leadRealityGapService->analyzeForCars($cars, $analyses, $intentAnalyses, $from, $to);

        return $cars->map(function (Car $car) use ($analyses, $intentAnalyses, $leadRealityGaps, $from, $to) {
            return $this->buildDecisionPayload(
                $car,
                $analyses[$car->id] ?? [],
                $intentAnalyses[$car->id] ?? [],
                $leadRealityGaps[$car->id] ?? [],
                $from,
                $to
            );
        })->all();
    }

    private function buildDecisionPayload(
        Car $car,
        array $analysis,
        array $intelligence = [],
        array $leadRealityGap = [],
        ?string $from = null,
        ?string $to = null
    ): array
    {
        $phases = $analysis['phases'] ?? [];
        $metrics = $analysis['metrics'] ?? [];
        $vehicleType = $this->resolveVehicleType($car);
        $thresholds = $this->resolveThresholds($vehicleType);
        $isMatureCampaign = $this->isMatureCampaign($metrics, $thresholds);
        $scores = $this->buildScores($phases);
        $guardrails = $this->adsGuardrailService->evaluate($car, $analysis, $from, $to, $intelligence, $leadRealityGap);

        if (!$this->hasActiveCampaigns($car)) {
            return [
                'car_id' => $car->id,
                'car_name' => $this->buildCarName($car),
                'decision' => 'NO_ACTIVE_CAMPAIGN',
                'confidence' => 100,
                'reason' => 'Não existem campanhas ativas para esta viatura.',
                'main_metric' => 'Sem investimento ativo',
                'actions' => [
                    'Criar nova campanha',
                    'Reativar campanha anterior',
                ],
                'scores' => $scores,
                'funnel' => $phases,
                'guardrails' => $guardrails,
                'intelligence' => $intelligence,
                'lead_reality_gap' => $leadRealityGap,
            ];
        }

        if ($this->hasInsufficientData($metrics)) {
            return [
                'car_id' => $car->id,
                'car_name' => $this->buildCarName($car),
                'decision' => 'INSUFFICIENT_DATA',
                'confidence' => 25,
                'reason' => 'Ainda não existem dados suficientes para uma decisão fiável.',
                'main_metric' => 'Dados insuficientes',
                'actions' => [
                    'Gerar tráfego inicial para recolher sinais',
                    'Aguardar mais dados de navegação e intenção',
                ],
                'scores' => $scores,
                'funnel' => $phases,
                'guardrails' => $guardrails,
                'intelligence' => $intelligence,
                'lead_reality_gap' => $leadRealityGap,
            ];
        }

        $worstPhase = $this->resolveWorstPhase($scores);
        $decision = $this->resolveDecision($phases, $scores, $metrics, $isMatureCampaign, $vehicleType, $thresholds);

        return [
            'car_id' => $car->id,
            'car_name' => $this->buildCarName($car),
            'decision' => $decision,
            'confidence' => $this->resolveConfidence($metrics, $scores),
            'reason' => $this->buildReason($decision, $worstPhase, $phases, $isMatureCampaign, $vehicleType),
            'main_metric' => $this->buildMainMetric($decision, $worstPhase, $metrics, $vehicleType),
            'actions' => $this->buildActions($decision, $worstPhase, $car, $vehicleType),
            'scores' => $scores,
            'funnel' => $phases,
            'guardrails' => $guardrails,
            'intelligence' => $intelligence,
            'lead_reality_gap' => $leadRealityGap,
        ];
    }

    private function hasInsufficientData(array $metrics): bool
    {
        return (int) ($metrics['impressions'] ?? 0) === 0
            && (int) ($metrics['clicks'] ?? 0) === 0
            && (int) ($metrics['views'] ?? 0) === 0
            && (int) ($metrics['leads'] ?? 0) === 0
            && (int) ($metrics['whatsapp_clicks'] ?? 0) === 0
            && (int) ($metrics['form_opens'] ?? 0) === 0;
    }

    private function hasActiveCampaigns(Car $car): bool
    {
        return CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where('is_active', true)
            ->exists();
    }

    private function buildScores(array $phases): array
    {
        return collect($phases)->mapWithKeys(function (array $phase, string $key) {
            $score = match ($phase['state'] ?? 'warning') {
                'good' => 80,
                'warning' => 55,
                'bad' => 30,
                default => 0,
            };

            return [$key => $score];
        })->all();
    }

    private function resolveWorstPhase(array $scores): string
    {
        asort($scores);

        return (string) array_key_first($scores);
    }

    private function resolveVehicleType(Car $car): string
    {
        return $car->vehicle_type === 'motorhome' ? 'motorhome' : 'car';
    }

    private function resolveThresholds(string $vehicleType): array
    {
        return self::DECISION_THRESHOLDS[$vehicleType] ?? self::DECISION_THRESHOLDS['car'];
    }

    private function isMatureCampaign(array $metrics, array $thresholds): bool
    {
        return (int) ($metrics['views'] ?? 0) >= $thresholds['mature_views']
            || (int) ($metrics['clicks'] ?? 0) >= $thresholds['mature_clicks']
            || (float) ($metrics['spend_normalized'] ?? 0) >= $thresholds['mature_spend'];
    }

    private function resolveDecision(
        array $phases,
        array $scores,
        array $metrics,
        bool $isMatureCampaign,
        string $vehicleType,
        array $thresholds
    ): string
    {
        $hasTraffic = (int) ($metrics['impressions'] ?? 0) > 0
            || (int) ($metrics['clicks'] ?? 0) > 0
            || (int) ($metrics['views'] ?? 0) > 0;
        $hasConversionSignal = (int) ($metrics['leads'] ?? 0) > 0
            || (int) ($metrics['whatsapp_clicks'] ?? 0) > 0;
        $hasQualifiedIntent = ((float) ($metrics['avg_time_on_page'] ?? 0) >= $thresholds['strong_time_on_page'])
            || ((float) ($metrics['scroll'] ?? 0) >= $thresholds['strong_scroll'])
            || ((int) ($metrics['form_opens'] ?? 0) >= $thresholds['warning_form_opens']);
        $clickState = $phases['click']['state'] ?? null;
        $intentState = $phases['intent']['state'] ?? null;
        $conversionState = $phases['conversion']['state'] ?? null;
        $lowestScore = count($scores) > 0 ? min($scores) : 0;

        if (
            $lowestScore <= $thresholds['kill_score']
            && $clickState === 'bad'
            && $intentState === 'bad'
        ) {
            return 'PARAR';
        }

        if (
            $clickState === 'good'
            && $intentState === 'good'
            && $hasConversionSignal
            && $lowestScore >= 55
        ) {
            return 'ESCALAR';
        }

        if (
            $vehicleType === 'motorhome'
            && !$isMatureCampaign
            && ($intentState === 'good' || $hasQualifiedIntent)
            && !$hasConversionSignal
        ) {
            return 'MANTER';
        }

        if (
            !$isMatureCampaign
            && $intentState === 'good'
            && $conversionState === 'bad'
        ) {
            return 'MANTER';
        }

        if (
            !$isMatureCampaign
            && $conversionState === 'bad'
        ) {
            return 'MANTER';
        }

        if ($hasTraffic && !$isMatureCampaign) {
            return 'MANTER';
        }

        if (
            $conversionState === 'bad'
            || (
                $intentState === 'warning'
                && (int) ($metrics['views'] ?? 0) >= $thresholds['warning_intent_views']
                && !$hasConversionSignal
                && !($vehicleType === 'motorhome' && $hasQualifiedIntent)
            )
            || ($clickState === 'bad' && !($vehicleType === 'motorhome' && !$isMatureCampaign))
        ) {
            return 'CORRIGIR';
        }

        return $hasTraffic ? 'MANTER' : 'CORRIGIR';
    }

    private function resolveConfidence(array $metrics, array $scores): int
    {
        $signalStrength = min(
            100,
            (int) round(
                min(35, ((int) ($metrics['impressions'] ?? 0)) / 60)
                + min(20, ((int) ($metrics['clicks'] ?? 0)) * 1.2)
                + min(20, ((int) ($metrics['views'] ?? 0)) * 0.8)
                + min(15, ((int) ($metrics['whatsapp_clicks'] ?? 0)) * 5)
                + min(10, ((int) ($metrics['leads'] ?? 0)) * 10)
            )
        );

        $phaseConsistency = (int) round(array_sum($scores) / max(count($scores), 1));

        return max(20, min(95, (int) round(($signalStrength * 0.65) + ($phaseConsistency * 0.35))));
    }

    private function buildReason(
        string $decision,
        string $worstPhase,
        array $phases,
        bool $isMatureCampaign,
        string $vehicleType
    ): string
    {
        if ($decision === 'INSUFFICIENT_DATA') {
            return 'Ainda não existem dados suficientes para interpretar o funil com confiança.';
        }

        if ($decision === 'ESCALAR') {
            return $vehicleType === 'motorhome'
                ? 'O anúncio já gera descoberta qualificada e sinais de intenção suficientes para subir investimento.'
                : 'CTR, visita qualificada e conversão já mostram tração suficiente para subir investimento.';
        }

        if ($decision === 'MANTER') {
            return $vehicleType === 'motorhome'
                ? 'Existe interesse inicial, mas o comportamento é compatível com um produto de decisão mais longa.'
                : 'Já existe tráfego inicial e sinais de aprendizagem, mas ainda é cedo para acelerar.';
        }

        if ($decision === 'PARAR') {
            return $vehicleType === 'motorhome'
                ? 'Mesmo com ciclo de decisão longo, a campanha está sem sinais mínimos de descoberta qualificada.'
                : 'A campanha está a entregar pouco e com clique fraco para justificar mais orçamento.';
        }

        if (!$isMatureCampaign && (($phases['conversion']['state'] ?? null) === 'bad')) {
            return $vehicleType === 'motorhome'
                ? 'Existe interesse inicial, mas o comportamento é compatível com um produto de decisão mais longa.'
                : 'Campanha ainda em fase inicial, recolhendo dados.';
        }

        return match ($worstPhase) {
            'conversion' => $vehicleType === 'motorhome'
                ? 'Há exploração do produto, mas a decisão ainda não amadureceu para contacto.'
                : 'Há tráfego e intenção, mas a conversão está a falhar.',
            'intent' => $vehicleType === 'motorhome'
                ? 'Há curiosidade, mas falta aprofundar contexto de habitabilidade e uso real.'
                : 'O utilizador chega à página, mas o interesse ainda não evolui para intenção real.',
            'click' => 'A campanha entrega, mas o criativo ou a proposta ainda não geram clique suficiente.',
            'delivery' => 'O funil está a arrancar mal logo na entrega da campanha.',
            default => $phases[$worstPhase]['diagnosis'] ?? 'O funil precisa de otimização.',
        };
    }

    private function buildMainMetric(string $decision, string $worstPhase, array $metrics, string $vehicleType): string
    {
        if ($decision === 'ESCALAR') {
            return ((int) ($metrics['leads'] ?? 0)) > 0 || (int) ($metrics['whatsapp_clicks'] ?? 0) > 0
                ? 'Conversão com tração'
                : ($vehicleType === 'motorhome' ? 'Interesse qualificado em profundidade' : 'CTR acima da média');
        }

        if ($decision === 'MANTER') {
            return $vehicleType === 'motorhome' ? 'Interesse compatível com decisão longa' : 'Campanha em aprendizagem';
        }

        if ($decision === 'PARAR') {
            return 'Baixa entrega e CTR fraco';
        }

        return match ($worstPhase) {
            'delivery' => 'Entrega insuficiente',
            'click' => 'CTR fraco',
            'conversion' => ((int) ($metrics['views'] ?? 0)) > 0 && (int) ($metrics['leads'] ?? 0) === 0
                ? 'LPV alto / leads zero'
                : 'Conversão abaixo do esperado',
            'intent' => 'Interesse na página abaixo do esperado',
            default => 'Sinal principal indefinido',
        };
    }

    private function buildActions(string $decision, string $worstPhase, Car $car, string $vehicleType): array
    {
        if ($decision === 'INSUFFICIENT_DATA') {
            return [
                'Ativar campanha de teste para gerar dados',
                'Acompanhar as primeiras interações do funil',
            ];
        }

        $actions = match ($decision) {
            'ESCALAR' => [
                'Aumentar orçamento no conjunto vencedor',
                'Expandir audiência semelhante',
                'Duplicar o criativo com melhor CTR',
            ],
            'MANTER' => [
                ...($vehicleType === 'motorhome' ? [
                    'Manter investimento estável enquanto recolhe sinais',
                    'Reforçar storytelling de uso e viagem',
                    'Reavaliar após mais tempo de aprendizagem',
                ] : [
                    'Manter investimento estável',
                    'Monitorizar primeiras conversões',
                    'Reavaliar após mais 3 a 5 dias',
                ]),
            ],
            'PARAR' => [
                'Pausar campanha atual',
                'Rever segmentação e oferta',
                'Redistribuir orçamento para carros com sinal',
            ],
            default => match ($worstPhase) {
            'conversion' => [
                ...($vehicleType === 'motorhome' ? [
                    'Reforçar storytelling do interior e habitabilidade',
                    'Melhorar hero da página com prova visual forte',
                    'Testar CTA mais consultivo',
                ] : [
                    'Melhorar CTA',
                    'Testar novo criativo',
                    'Rever página',
                ]),
            ],
            'intent' => [
                ...($vehicleType === 'motorhome' ? [
                    'Destacar layout, habitabilidade e cenários de uso',
                    'Reforçar prova visual do interior',
                    'Melhorar hero da página',
                ] : [
                    'Reforçar proposta de valor na landing',
                    'Testar novo criativo',
                    'Melhorar mensagem acima da dobra',
                ]),
            ],
            'click' => [
                'Testar novo criativo',
                'Rever headline',
                'Afinar proposta no anúncio',
            ],
            'delivery' => [
                'Alargar audiência',
                'Rever segmentação',
                'Aumentar volume de entrega inicial',
            ],
            default => [
                'Rever campanha',
                'Testar nova abordagem',
                'Recolher mais dados antes da próxima decisão',
            ],
            },
        };

        if ($vehicleType === 'motorhome' && $worstPhase === 'conversion') {
            $actions = [
                'Dar mais contexto aspiracional ao anúncio',
                'Reforçar prova de uso real na página',
                'Criar CTA menos transacional e mais consultivo',
            ];
        }

        return $actions;
    }

    private function buildCarName(Car $car): string
    {
        return trim(implode(' ', array_filter([
            $car->brand?->name,
            $car->model?->name,
            $car->version,
        ])));
    }
}
