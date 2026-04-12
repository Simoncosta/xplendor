<?php

namespace App\Services;

use App\Models\CampaignCarMetricDaily;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\MetaAudienceInsight;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdsGuardrailService
{
    private const DEFAULT_WINDOW_DAYS = 7;

    public function evaluate(
        Car $car,
        array $analysis,
        ?string $from = null,
        ?string $to = null,
        array $intelligence = [],
        array $leadRealityGap = []
    ): array
    {
        $period = $analysis['period'] ?? [];
        $from ??= $period['from'] ?? now()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->toDateString();
        $to ??= $period['to'] ?? now()->toDateString();
        $vehicleType = $car->vehicle_type ?? 'car';
        $thresholds = $this->getThresholds($vehicleType);

        $snapshot = $this->buildSnapshot($car, $analysis['metrics'] ?? [], $from, $to, $thresholds);
        $alerts = array_values(array_filter([
            $this->detectSpendWithoutQualifiedLead($snapshot, $thresholds),
            $this->detectCreativeFatigue($snapshot, $thresholds),
            $this->detectHighSpendLowIntent($snapshot, $thresholds),
            $this->detectGapGuardrail($leadRealityGap),
        ]));

        usort($alerts, function (array $left, array $right) {
            $severityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            $leftSeverity = $severityOrder[$left['severity'] ?? 'low'] ?? 0;
            $rightSeverity = $severityOrder[$right['severity'] ?? 'low'] ?? 0;

            if ($leftSeverity !== $rightSeverity) {
                return $rightSeverity <=> $leftSeverity;
            }

            return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $alerts;
    }

    public function thresholds(): array
    {
        return [
            'car' => $this->getThresholds('car'),
            'motorhome' => $this->getThresholds('motorhome'),
        ];
    }

    private function buildSnapshot(Car $car, array $metrics, string $from, string $to, array $thresholds): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();
        $windowDays = max(1, $fromDate->copy()->startOfDay()->diffInDays($toDate->copy()->startOfDay()) + 1);
        $previousTo = $fromDate->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($windowDays - 1)->startOfDay();

        $currentTotals = $this->aggregateCampaignMetrics($car, $fromDate, $toDate);
        $previousTotals = $this->aggregateCampaignMetrics($car, $previousFrom, $previousTo);
        $dailyRiskRows = $this->loadDailyRiskRows($car, $fromDate, $toDate);

        $qualifiedLeadCount = CarLead::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->whereIn('status', ['qualified', 'won'])
            ->count();

        $unansweredLeadQuery = CarLead::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->whereNull('contacted_at')
            ->whereNotIn('status', ['won', 'lost', 'spam'])
            ->where('created_at', '<=', now()->subHours($thresholds['max_hours_without_lead_response']));

        $unansweredLeadCount = (clone $unansweredLeadQuery)->count();
        $oldestUnansweredLeadAt = (clone $unansweredLeadQuery)->oldest('created_at')->value('created_at');

        $reach = (int) round((float) MetaAudienceInsight::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereDate('period_start', '>=', $fromDate->toDateString())
            ->whereDate('period_end', '<=', $toDate->toDateString())
            ->sum('reach'));

        $impressions = (int) ($metrics['impressions'] ?? $currentTotals['impressions']);
        $clicks = (int) ($metrics['clicks'] ?? $currentTotals['clicks']);
        $spend = round((float) ($metrics['spend_normalized'] ?? $currentTotals['spend_normalized']), 2);
        $ctr = $metrics['ctr'] !== null && array_key_exists('ctr', $metrics)
            ? (float) $metrics['ctr']
            : ($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null);
        $previousCtr = $previousTotals['impressions'] > 0
            ? round(($previousTotals['clicks'] / $previousTotals['impressions']) * 100, 2)
            : null;
        $frequency = $reach > 0 ? round($impressions / $reach, 2) : null;

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'window_days' => $windowDays,
            'metrics' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend_normalized' => $spend,
                'ctr' => $ctr,
                'avg_time_on_page' => $metrics['avg_time_on_page'] ?? null,
                'scroll' => $metrics['scroll'] ?? null,
                'whatsapp_clicks' => (int) ($metrics['whatsapp_clicks'] ?? 0),
                'form_opens' => (int) ($metrics['form_opens'] ?? 0),
                'leads' => (int) ($metrics['leads'] ?? 0),
            ],
            'previous_ctr' => $previousCtr,
            'frequency' => $frequency,
            'qualified_lead_count' => $qualifiedLeadCount,
            'unanswered_lead_count' => $unansweredLeadCount,
            'oldest_unanswered_lead_at' => $oldestUnansweredLeadAt ? Carbon::parse($oldestUnansweredLeadAt) : null,
            'daily_risk_rows' => $dailyRiskRows,
        ];
    }

    private function getThresholds(string $vehicleType): array
    {
        $defaults = [
            'car' => [
                'max_spend_without_qualified_lead' => 120.0,
                'max_frequency_before_fatigue' => 2.5,
                'fatigue_ctr_threshold' => 1.2,
                'fatigue_ctr_drop_ratio' => 0.25,
                'max_hours_without_lead_response' => 24,
                'max_days_high_spend_low_intent' => 3,
                'min_daily_spend_for_low_intent_risk' => 20.0,
                'low_intent_avg_time_on_page' => 30.0,
                'low_intent_scroll' => 40.0,
                'max_soft_conversion_signals_without_qualified_lead' => 1,
            ],
            'motorhome' => [
                'max_spend_without_qualified_lead' => 300.0,
                'max_frequency_before_fatigue' => 3.5,
                'fatigue_ctr_threshold' => 0.7,
                'fatigue_ctr_drop_ratio' => 0.25,
                'max_hours_without_lead_response' => 48,
                'max_days_high_spend_low_intent' => 7,
                'min_daily_spend_for_low_intent_risk' => 35.0,
                'low_intent_avg_time_on_page' => 45.0,
                'low_intent_scroll' => 45.0,
                'max_soft_conversion_signals_without_qualified_lead' => 1,
            ],
        ];

        return $defaults[$vehicleType] ?? $defaults['car'];
    }

    private function aggregateCampaignMetrics(Car $car, Carbon $from, Carbon $to): array
    {
        $row = CampaignCarMetricDaily::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                COALESCE(SUM(impressions), 0) as impressions,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(spend_normalized), 0) as spend_normalized
            ')
            ->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'spend_normalized' => round((float) ($row->spend_normalized ?? 0), 2),
        ];
    }

    private function loadDailyRiskRows(Car $car, Carbon $from, Carbon $to): array
    {
        return DB::table('campaign_car_metrics_daily as campaign')
            ->leftJoin('car_funnel_metrics_daily as funnel', function ($join) {
                $join->on('funnel.company_id', '=', 'campaign.company_id')
                    ->on('funnel.car_id', '=', 'campaign.car_id')
                    ->on('funnel.date', '=', 'campaign.date');
            })
            ->where('campaign.company_id', $car->company_id)
            ->where('campaign.car_id', $car->id)
            ->whereBetween('campaign.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('campaign.date')
            ->orderBy('campaign.date')
            ->get([
                'campaign.date',
                DB::raw('ROUND(COALESCE(SUM(campaign.spend_normalized), 0), 2) as spend'),
                DB::raw('MAX(funnel.avg_time_on_page) as avg_time_on_page'),
                DB::raw('MAX(funnel.scroll) as scroll'),
                DB::raw('MAX(funnel.whatsapp_clicks) as whatsapp_clicks'),
                DB::raw('MAX(funnel.form_opens) as form_opens'),
                DB::raw('MAX(funnel.leads) as leads'),
            ])
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'spend' => round((float) ($row->spend ?? 0), 2),
                'avg_time_on_page' => $row->avg_time_on_page !== null ? round((float) $row->avg_time_on_page, 2) : null,
                'scroll' => $row->scroll !== null ? round((float) $row->scroll, 2) : null,
                'whatsapp_clicks' => (int) ($row->whatsapp_clicks ?? 0),
                'form_opens' => (int) ($row->form_opens ?? 0),
                'leads' => (int) ($row->leads ?? 0),
            ])
            ->all();
    }

    private function detectSpendWithoutQualifiedLead(array $snapshot, array $thresholds): ?array
    {
        $metrics = $snapshot['metrics'];
        $softSignals = (int) ($metrics['whatsapp_clicks'] ?? 0) + (int) ($metrics['form_opens'] ?? 0);

        if (
            (float) ($metrics['spend_normalized'] ?? 0) < $thresholds['max_spend_without_qualified_lead']
            || (int) ($snapshot['qualified_lead_count'] ?? 0) > 0
            || $softSignals > $thresholds['max_soft_conversion_signals_without_qualified_lead']
            || (int) ($metrics['leads'] ?? 0) > 0
        ) {
            return null;
        }

        return [
            'type' => 'spend_without_qualified_lead',
            'severity' => 'high',
            'title' => 'Gasto elevado sem lead qualificada',
            'message' => sprintf(
                'A viatura já acumulou €%s em %d dias sem leads qualificadas nem sinais fortes de conversão.',
                number_format((float) $metrics['spend_normalized'], 2, ',', '.'),
                (int) $snapshot['window_days']
            ),
            'recommended_action' => 'Pausar campanha e rever oferta ou criativo.',
            'manual_only' => true,
        ];
    }

    private function detectCreativeFatigue(array $snapshot, array $thresholds): ?array
    {
        $currentCtr = $snapshot['metrics']['ctr'];
        $previousCtr = $snapshot['previous_ctr'];
        $frequency = $snapshot['frequency'];

        if ($currentCtr === null || $frequency === null) {
            return null;
        }

        $hasCtrDrop = $previousCtr !== null
            ? $currentCtr <= ($previousCtr * (1 - $thresholds['fatigue_ctr_drop_ratio']))
            : true;

        if (
            $frequency < $thresholds['max_frequency_before_fatigue']
            || $currentCtr > $thresholds['fatigue_ctr_threshold']
            || !$hasCtrDrop
        ) {
            return null;
        }

        $message = sprintf(
            'A frequência média está em %s e o CTR atual caiu para %s%%.',
            number_format((float) $frequency, 2, ',', '.'),
            number_format((float) $currentCtr, 2, ',', '.')
        );

        if ($previousCtr !== null) {
            $message .= sprintf(' No período anterior estava em %s%%.', number_format((float) $previousCtr, 2, ',', '.'));
        }

        return [
            'type' => 'creative_fatigue',
            'severity' => 'medium',
            'title' => 'Fadiga criativa detetada',
            'message' => $message,
            'recommended_action' => 'Trocar criativo antes de continuar a escalar investimento.',
            'manual_only' => true,
        ];
    }

    private function detectHighSpendLowIntent(array $snapshot, array $thresholds): ?array
    {
        $riskDays = collect($snapshot['daily_risk_rows'] ?? [])->filter(function (array $row) use ($thresholds) {
            $hasLowEngagement = (float) ($row['spend'] ?? 0) >= $thresholds['min_daily_spend_for_low_intent_risk']
                && (int) ($row['leads'] ?? 0) === 0
                && (int) ($row['whatsapp_clicks'] ?? 0) === 0
                && (int) ($row['form_opens'] ?? 0) <= 1
                && (((float) ($row['avg_time_on_page'] ?? 0)) < $thresholds['low_intent_avg_time_on_page'])
                && (((float) ($row['scroll'] ?? 0)) < $thresholds['low_intent_scroll']);

            return $hasLowEngagement;
        });

        if ($riskDays->count() < $thresholds['max_days_high_spend_low_intent']) {
            return null;
        }

        return [
            'type' => 'high_spend_low_intent',
            'severity' => 'medium',
            'title' => 'Investimento alto com baixa intenção',
            'message' => sprintf(
                'Nos últimos %d dias houve %d dias com gasto relevante e interesse fraco na página.',
                (int) $snapshot['window_days'],
                $riskDays->count()
            ),
            'recommended_action' => 'Remover da rotação ou rever proposta, preço e criativo.',
            'manual_only' => true,
        ];
    }

    private function detectUnansweredLeads(array $snapshot): ?array
    {
        $count = (int) ($snapshot['unanswered_lead_count'] ?? 0);
        $oldestLeadAt = $snapshot['oldest_unanswered_lead_at'] ?? null;

        if ($count === 0 || !$oldestLeadAt instanceof Carbon) {
            return null;
        }

        return [
            'type' => 'unanswered_leads',
            'severity' => 'high',
            'title' => 'Leads sem resposta',
            'message' => sprintf(
                'Existem %d leads sem contacto há pelo menos %d horas.',
                $count,
                $oldestLeadAt->diffInHours(now())
            ),
            'recommended_action' => 'Responder às leads antes de aumentar investimento neste funil.',
            'manual_only' => true,
        ];
    }

    private function detectDecisionFriction(array $intelligence): ?array
    {
        if (
            (int) ($intelligence['intent_score'] ?? 0) < 70
            || (int) ($intelligence['strong_intent_users'] ?? 0) < 2
            || (int) ($intelligence['leads'] ?? 0) > 0
            || (int) ($intelligence['confidence_score'] ?? 0) < 70
        ) {
            return null;
        }

        return [
            'type' => 'decision_friction',
            'severity' => 'high',
            'title' => 'Interesse forte sem contacto',
            'message' => 'Há utilizadores com forte intenção, mas sem lead capturada.',
            'recommended_action' => 'Rever CTA, proposta, preço ou fricção de contacto.',
            'manual_only' => true,
        ];
    }

    private function detectContactLoss(array $intelligence): ?array
    {
        if (
            (int) ($intelligence['strong_intent_users'] ?? 0) < 3
            || (float) ($intelligence['contact_efficiency'] ?? -1) !== 0.0
            || (int) ($intelligence['confidence_score'] ?? 0) < 70
        ) {
            return null;
        }

        return [
            'type' => 'contact_loss',
            'severity' => 'high',
            'title' => 'Perda de contacto provável',
            'message' => 'Há sinais fortes de contacto, mas o sistema não capturou leads.',
            'recommended_action' => 'Validar fluxo WhatsApp, proposta de contacto e confiança comercial.',
            'manual_only' => true,
        ];
    }

    private function detectNoResponse(array $snapshot, array $intelligence): ?array
    {
        $count = (int) ($snapshot['unanswered_lead_count'] ?? 0);
        $oldestLeadAt = $snapshot['oldest_unanswered_lead_at'] ?? null;

        if ($count === 0 || !$oldestLeadAt instanceof Carbon) {
            return null;
        }

        return [
            'type' => 'no_response',
            'severity' => 'high',
            'title' => 'Leads sem resposta',
            'message' => 'O stand ainda não respondeu a leads existentes.',
            'recommended_action' => 'Responder e evitar escalar investimento antes disso.',
            'manual_only' => true,
        ];
    }

    private function detectGapGuardrail(array $leadRealityGap): ?array
    {
        $primaryState = (string) ($leadRealityGap['primary_gap_state'] ?? '');

        return match ($primaryState) {
            'decision_friction' => [
                'type' => 'decision_friction',
                'severity' => 'high',
                'title' => 'Interesse forte sem contacto',
                'message' => 'Há utilizadores com forte intenção, mas sem contacto capturado.',
                'recommended_action' => 'Rever CTA, proposta, preço ou fricção de contacto.',
                'manual_only' => true,
            ],
            'contact_capture_failure' => [
                'type' => 'contact_capture_failure',
                'severity' => 'high',
                'title' => 'Perda de contacto provável',
                'message' => 'Há sinais fortes de contacto, mas o sistema não capturou leads.',
                'recommended_action' => 'Validar fluxo WhatsApp, proposta de contacto e confiança comercial.',
                'manual_only' => true,
            ],
            'no_response' => [
                'type' => 'no_response',
                'severity' => 'high',
                'title' => 'Leads sem resposta',
                'message' => 'O stand ainda não respondeu a leads existentes.',
                'recommended_action' => 'Responder e evitar escalar investimento antes disso.',
                'manual_only' => true,
            ],
            default => null,
        };
    }
}
