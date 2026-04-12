<?php

namespace App\Services;

use App\Models\Car;
use Illuminate\Support\Collection;

class LeadRealityGapService
{
    private const RESPONSE_THRESHOLD_HOURS = [
        'car' => 24,
        'motorhome' => 48,
    ];

    public function __construct(
        protected CarFunnelAnalyzer $carFunnelAnalyzer,
        protected IntentAnalysisService $intentAnalysisService,
    ) {}

    public function analyzeForCar(
        Car $car,
        array $funnelAnalysis = [],
        array $intentAnalysis = [],
        ?string $from = null,
        ?string $to = null
    ): array {
        if (empty($funnelAnalysis)) {
            $funnelAnalysis = $this->carFunnelAnalyzer->analyzeForCar($car, $from, $to);
        }

        if (empty($intentAnalysis)) {
            $intentAnalysis = $this->intentAnalysisService->analyzeForCar($car, $from, $to);
        }

        return $this->buildGap($car, $funnelAnalysis, $intentAnalysis);
    }

    public function analyzeForCars(
        Collection $cars,
        array $funnelAnalyses = [],
        array $intentAnalyses = [],
        ?string $from = null,
        ?string $to = null
    ): array {
        if ($cars->isEmpty()) {
            return [];
        }

        if (empty($funnelAnalyses)) {
            $funnelAnalyses = $this->carFunnelAnalyzer->analyzeForCars($cars, $from, $to);
        }

        if (empty($intentAnalyses)) {
            $intentAnalyses = $this->intentAnalysisService->analyzeForCars($cars, $from, $to);
        }

        return $cars->mapWithKeys(function (Car $car) use ($funnelAnalyses, $intentAnalyses) {
            return [
                $car->id => $this->buildGap(
                    $car,
                    $funnelAnalyses[$car->id] ?? [],
                    $intentAnalyses[$car->id] ?? []
                ),
            ];
        })->all();
    }

    private function buildGap(Car $car, array $funnelAnalysis, array $intentAnalysis): array
    {
        $metrics = $funnelAnalysis['metrics'] ?? [];
        $vehicleType = $car->vehicle_type === 'motorhome' ? 'motorhome' : 'car';
        $responseThresholdHours = self::RESPONSE_THRESHOLD_HOURS[$vehicleType] ?? self::RESPONSE_THRESHOLD_HOURS['car'];

        $impressions = (int) ($metrics['impressions'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);
        $ctr = $metrics['ctr'] !== null && array_key_exists('ctr', $metrics) ? (float) $metrics['ctr'] : null;
        $cpc = $metrics['cpc'] !== null && array_key_exists('cpc', $metrics) ? (float) $metrics['cpc'] : null;
        $spend = round((float) ($metrics['spend_normalized'] ?? 0), 2);
        $views = (int) ($metrics['views'] ?? 0);
        $sessions = (int) ($intentAnalysis['sessions'] ?? $metrics['sessions'] ?? 0);
        $uniqueVisitors = (int) ($intentAnalysis['unique_visitors'] ?? 0);
        $avgTimeOnPage = round((float) ($intentAnalysis['avg_time_on_page'] ?? $metrics['avg_time_on_page'] ?? 0), 2);
        $avgScroll = round((float) ($intentAnalysis['avg_scroll'] ?? $metrics['scroll'] ?? 0), 2);
        $intentScore = (int) ($intentAnalysis['intent_score'] ?? 0);
        $strongIntentUsers = (int) ($intentAnalysis['strong_intent_users'] ?? 0);
        $whatsappClicks = (int) ($intentAnalysis['whatsapp_clicks'] ?? $metrics['whatsapp_clicks'] ?? 0);
        $formOpens = (int) ($metrics['form_opens'] ?? 0);
        $leads = (int) ($intentAnalysis['leads'] ?? $metrics['leads'] ?? 0);
        $contactedLeads = (int) ($intentAnalysis['contacted_leads'] ?? 0);
        $unansweredLeads = (int) ($intentAnalysis['unanswered_leads'] ?? 0);
        $confidenceScore = (int) ($intentAnalysis['confidence_score'] ?? 0);
        $diagnosticConfidence = (int) ($intentAnalysis['diagnostic']['confidence'] ?? $confidenceScore);
        $diagnosticConfidenceReason = (string) ($intentAnalysis['diagnostic']['confidence_reason'] ?? '');

        $estimatedRealContactProbability = $this->resolveEstimatedRealContactProbability(
            $intentScore,
            $strongIntentUsers,
            $whatsappClicks,
            $sessions,
            $leads,
            $formOpens,
            $uniqueVisitors
        );

        $captureGapRate = $strongIntentUsers > 0
            ? (int) round(max(0, 1 - min($leads, $strongIntentUsers) / $strongIntentUsers) * 100)
            : null;
        $responseGapRate = $leads > 0
            ? (int) round(max(0, 1 - min($contactedLeads, $leads) / $leads) * 100)
            : null;

        $hasTraffic = $impressions > 0 || $clicks > 0 || $views > 0 || $sessions > 0;
        $highConfidence = $confidenceScore >= 70 || $diagnosticConfidence >= 75;
        $trackingGap = $this->isTrackingGap($views, $sessions, $whatsappClicks, $formOpens, $leads, $impressions);
        $noResponse = $leads > 0 && $contactedLeads === 0 && $unansweredLeads > 0;
        $contactCaptureFailure = $whatsappClicks >= 5 && $strongIntentUsers >= 2 && $leads === 0 && $highConfidence;
        $decisionFriction = $intentScore >= 70 && $strongIntentUsers >= 2 && $leads === 0;
        $healthyFlow = $intentScore >= 45 && $leads > 0 && $contactedLeads > 0;
        $lowLeadQuality = $leads > 0 && $contactedLeads > 0 && $intentScore < 40 && $strongIntentUsers === 0;
        $noRealInterest = $hasTraffic && $intentScore < 40 && $strongIntentUsers === 0 && $whatsappClicks === 0 && $formOpens <= 1 && $leads === 0;

        $secondaryStates = [];
        if ($contactCaptureFailure) {
            $secondaryStates[] = 'contact_capture_failure';
        }
        if ($decisionFriction) {
            $secondaryStates[] = 'decision_friction';
        }
        if ($noResponse) {
            $secondaryStates[] = 'no_response';
        }
        if ($lowLeadQuality) {
            $secondaryStates[] = 'low_lead_quality';
        }
        if ($trackingGap) {
            $secondaryStates[] = 'tracking_gap';
        }
        if ($noRealInterest) {
            $secondaryStates[] = 'no_real_interest';
        }

        $primaryState = 'healthy_flow';
        if ($trackingGap) {
            $primaryState = 'tracking_gap';
        } elseif ($noResponse) {
            $primaryState = 'no_response';
        } elseif ($contactCaptureFailure) {
            $primaryState = 'contact_capture_failure';
        } elseif ($decisionFriction) {
            $primaryState = 'decision_friction';
        } elseif ($lowLeadQuality) {
            $primaryState = 'low_lead_quality';
        } elseif ($healthyFlow) {
            $primaryState = 'healthy_flow';
        } elseif ($noRealInterest) {
            $primaryState = 'no_real_interest';
        }

        $secondaryStates = array_values(array_filter(array_unique($secondaryStates), fn (string $state) => $state !== $primaryState));
        [$severity, $message, $failurePoint] = $this->resolveStatePresentation($primaryState);
        $confidence = $this->resolveGapConfidence(
            $primaryState,
            $confidenceScore,
            $diagnosticConfidence,
            $strongIntentUsers,
            $whatsappClicks,
            $leads,
            $contactedLeads,
            $trackingGap
        );

        $confidenceReason = $this->resolveGapConfidenceReason(
            $primaryState,
            $confidence,
            $diagnosticConfidenceReason,
            $strongIntentUsers,
            $whatsappClicks,
            $uniqueVisitors,
            $responseThresholdHours
        );

        return [
            'primary_gap_state' => $primaryState,
            'secondary_gap_states' => $secondaryStates,
            'severity' => $severity,
            'confidence' => $confidence,
            'confidence_reason' => $confidenceReason,
            'message' => $message,
            'likely_failure_point' => $failurePoint,
            'estimated_real_contact_probability' => $estimatedRealContactProbability,
            'capture_gap_rate' => $captureGapRate,
            'response_gap_rate' => $responseGapRate,
            'intent_score' => $intentScore,
            'strong_intent_users' => $strongIntentUsers,
            'whatsapp_clicks' => $whatsappClicks,
            'leads' => $leads,
            'contacted_leads' => $contactedLeads,
            'meta' => [
                'acquisition' => [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $ctr,
                    'cpc' => $cpc,
                    'spend' => $spend,
                ],
                'interest' => [
                    'avg_time_on_page' => $avgTimeOnPage,
                    'avg_scroll' => $avgScroll,
                    'sessions' => $sessions,
                    'unique_visitors' => $uniqueVisitors,
                    'form_opens' => $formOpens,
                ],
                'operation' => [
                    'unanswered_leads' => $unansweredLeads,
                    'response_threshold_hours' => $responseThresholdHours,
                ],
            ],
        ];
    }

    private function resolveEstimatedRealContactProbability(
        int $intentScore,
        int $strongIntentUsers,
        int $whatsappClicks,
        int $sessions,
        int $leads,
        int $formOpens,
        int $uniqueVisitors
    ): int {
        $score = (int) round($intentScore * 0.5);
        $score += min(18, $strongIntentUsers * 6);
        $score += min(14, $whatsappClicks * 2);
        $score += min(8, max(0, $sessions - 1) * 2);
        $score += min(6, $formOpens * 2);
        $score += min(6, $uniqueVisitors);

        if ($leads > 0) {
            $score += min(28, 18 + ($leads * 5));
        }

        if ($intentScore < 35 && $whatsappClicks === 0) {
            $score -= 12;
        }

        return max(0, min(100, $score));
    }

    private function isTrackingGap(
        int $views,
        int $sessions,
        int $whatsappClicks,
        int $formOpens,
        int $leads,
        int $impressions
    ): bool {
        if ($whatsappClicks >= 8 && $views <= 1) {
            return true;
        }

        if ($whatsappClicks >= 5 && $sessions <= 1) {
            return true;
        }

        if ($leads > ($sessions + 2) && $sessions > 0) {
            return true;
        }

        return $impressions > 0 && $views === 0 && ($whatsappClicks > 0 || $formOpens > 0 || $leads > 0);
    }

    private function resolveStatePresentation(string $primaryState): array
    {
        return match ($primaryState) {
            'no_real_interest' => ['medium', 'Há tráfego, mas ainda sem sinais fortes de interesse real.', 'traffic_quality'],
            'decision_friction' => ['high', 'Há utilizadores com forte intenção, mas sem contacto capturado.', 'decision'],
            'contact_capture_failure' => ['high', 'Há tentativa de contacto, mas a plataforma não capturou lead formal.', 'contact_capture'],
            'no_response' => ['urgent', 'O stand recebeu leads, mas ainda não respondeu dentro do tempo esperado.', 'operations'],
            'low_lead_quality' => ['medium', 'Existem leads, mas o sinal de intenção sugere qualidade fraca ou progressão limitada.', 'lead_quality'],
            'tracking_gap' => ['high', 'Os sinais recolhidos não estão coerentes. Validar tracking e instrumentação.', 'tracking'],
            default => ['low', 'O fluxo entre interesse, contacto e operação está consistente.', 'healthy_flow'],
        };
    }

    private function resolveGapConfidence(
        string $primaryState,
        int $confidenceScore,
        int $diagnosticConfidence,
        int $strongIntentUsers,
        int $whatsappClicks,
        int $leads,
        int $contactedLeads,
        bool $trackingGap
    ): int {
        $base = max($confidenceScore, $diagnosticConfidence);

        return match ($primaryState) {
            'tracking_gap' => max(55, min(90, $base - 10 + ($trackingGap ? 15 : 0))),
            'no_response' => max(75, min(98, 70 + ($leads * 5) + max(0, $leads - $contactedLeads) * 4)),
            'contact_capture_failure' => max(70, min(96, $base + ($strongIntentUsers * 4) + min(8, $whatsappClicks))),
            'decision_friction' => max(65, min(94, $base + ($strongIntentUsers * 5))),
            'healthy_flow' => max(60, min(92, $base + ($contactedLeads * 4))),
            default => max(40, min(88, $base)),
        };
    }

    private function resolveGapConfidenceReason(
        string $primaryState,
        int $confidence,
        string $diagnosticConfidenceReason,
        int $strongIntentUsers,
        int $whatsappClicks,
        int $uniqueVisitors,
        int $responseThresholdHours
    ): string {
        if ($primaryState === 'no_response') {
            return sprintf(
                'existem leads capturadas sem contacto registado acima do threshold operacional de %dh',
                $responseThresholdHours
            );
        }

        if ($primaryState === 'tracking_gap') {
            return 'os sinais de visitas, sessões e tentativas de contacto estão incoerentes entre si';
        }

        if ($confidence >= 80) {
            return $diagnosticConfidenceReason !== ''
                ? $diagnosticConfidenceReason
                : 'volume suficiente de visitantes únicos, interações fortes e tentativas de contacto';
        }

        if ($strongIntentUsers >= 2 || $whatsappClicks >= 3 || $uniqueVisitors >= 5) {
            return 'já existe padrão consistente, mas ainda com alguma variabilidade na amostra';
        }

        return 'a leitura é indiciária e deve ser interpretada com prudência';
    }
}
