<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarSaleAttribution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampaignToSaleAttributionService
{
    private const DEFAULT_WINDOW_DAYS = 7;
    private const MODEL = 'last_touch_recent_window';

    public function attributeSale(Car $car, array $context = []): array
    {
        $soldAt = Carbon::parse($context['sold_at'] ?? $car->sold_at ?? now());
        $windowDays = (int) ($context['window_days'] ?? self::DEFAULT_WINDOW_DAYS);
        $from = $soldAt->copy()->subDays($windowDays);

        $attribution = $this->bestRecentAttribution($car, $from, $soldAt);

        if ($attribution) {
            return $this->payloadFromAttribution($attribution, $windowDays, $soldAt);
        }

        $mapping = $this->fallbackMapping($car, $soldAt);

        if ($mapping) {
            return $this->payloadFromMapping($mapping, $windowDays, $soldAt);
        }

        return [
            'platform' => null,
            'campaign_id' => null,
            'adset_id' => null,
            'ad_id' => null,
            'model' => self::MODEL,
            'window_days' => $windowDays,
            'match_type' => 'fallback',
            'time_to_sale_hours' => null,
            'time_from_last_interaction_hours' => null,
            'confidence_score' => 0,
            'confidence_reason' => 'Sem campanha ou atribuição recente ligada a esta viatura.',
            'source_snapshot' => [
                'source' => 'no_match',
                'window_from' => $from->toDateTimeString(),
                'sold_at' => $soldAt->toDateTimeString(),
            ],
        ];
    }

    public function recordSaleAttribution(Car $car, array $context = []): CarSaleAttribution
    {
        $soldAt = Carbon::parse($context['sold_at'] ?? $car->sold_at ?? now());
        $result = $this->attributeSale($car, $context);

        $record = CarSaleAttribution::query()->updateOrCreate(
            [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'sold_at' => $soldAt,
            ],
            [
                'sale_price' => $context['sale_price'] ?? null,
                'attributed_platform' => $result['platform'],
                'attributed_campaign_id' => $result['campaign_id'],
                'attributed_adset_id' => $result['adset_id'],
                'attributed_ad_id' => $result['ad_id'],
                'attribution_model' => $result['model'],
                'attribution_window_days' => $result['window_days'],
                'match_type' => $result['match_type'],
                'time_to_sale_hours' => $result['time_to_sale_hours'],
                'time_from_last_interaction_hours' => $result['time_from_last_interaction_hours'],
                'confidence_score' => $result['confidence_score'],
                'confidence_reason' => $result['confidence_reason'],
                'source_snapshot' => $result['source_snapshot'],
            ]
        );

        Log::info('[Sale Attribution] Recorded', [
            'company_id' => $car->company_id,
            'car_id' => $car->id,
            'campaign_id' => $result['campaign_id'],
            'adset_id' => $result['adset_id'],
            'ad_id' => $result['ad_id'],
            'confidence_score' => $result['confidence_score'],
        ]);

        return $record;
    }

    public function summaryForCar(Car $car): ?array
    {
        $record = CarSaleAttribution::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->orderByDesc('sold_at')
            ->first();

        if (!$record) {
            return null;
        }

        return [
            'platform' => $record->attributed_platform,
            'campaign_id' => $record->attributed_campaign_id,
            'adset_id' => $record->attributed_adset_id,
            'ad_id' => $record->attributed_ad_id,
            'model' => $record->attribution_model,
            'window_days' => $record->attribution_window_days,
            'match_type' => $record->match_type,
            'time_to_sale_hours' => $record->time_to_sale_hours,
            'time_from_last_interaction_hours' => $record->time_from_last_interaction_hours,
            'confidence_score' => $record->confidence_score,
            'confidence_reason' => $record->confidence_reason,
        ];
    }

    private function bestRecentAttribution(Car $car, Carbon $from, Carbon $soldAt): ?object
    {
        return DB::table('car_ad_attributions')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->whereBetween('last_interaction_at', [$from, $soldAt])
            ->where(function ($query) {
                $query->whereNotNull('ad_id')
                    ->orWhereNotNull('adset_id')
                    ->orWhereNotNull('campaign_id');
            })
            ->orderByRaw('CASE WHEN ad_id IS NOT NULL THEN 1 WHEN adset_id IS NOT NULL THEN 2 WHEN campaign_id IS NOT NULL THEN 3 ELSE 4 END')
            ->orderByDesc('has_whatsapp_click')
            ->orderByDesc('has_strong_intent')
            ->orderByDesc('has_lead')
            ->orderByDesc('last_interaction_at')
            ->first();
    }

    private function fallbackMapping(Car $car, Carbon $soldAt): ?object
    {
        $from = $soldAt->copy()->subDays(14);

        return DB::table('car_ad_campaigns')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where(function ($query) use ($from) {
                $query->where('is_active', true)
                    ->orWhere('updated_at', '>=', $from);
            })
            ->orderByDesc('is_active')
            ->orderByRaw('CASE WHEN ad_id IS NOT NULL THEN 1 WHEN adset_id IS NOT NULL THEN 2 ELSE 3 END')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function payloadFromAttribution(object $attribution, int $windowDays, Carbon $soldAt): array
    {
        $hoursSinceLastInteraction = $this->hoursBetween($attribution->last_interaction_at, $soldAt);
        $confidence = 45 + $this->temporalConfidenceBonus($hoursSinceLastInteraction);
        $reasons = ['atribuição comportamental recente'];
        $matchType = 'campaign_match';

        if (!empty($attribution->ad_id)) {
            $confidence += 18;
            $reasons[] = 'ad_id recente';
            $matchType = 'direct_ad';
        } elseif (!empty($attribution->adset_id)) {
            $confidence += 12;
            $reasons[] = 'adset_id recente';
            $matchType = 'adset_match';
        }

        $signalCount = 0;

        if ((bool) $attribution->has_whatsapp_click) {
            $confidence += 12;
            $reasons[] = 'clique WhatsApp';
            $signalCount++;
        }

        if ((bool) $attribution->has_strong_intent) {
            $confidence += 8;
            $reasons[] = 'intenção forte';
            $signalCount++;
        }

        if ((bool) $attribution->has_lead) {
            $confidence += 8;
            $reasons[] = 'lead registada';
            $signalCount++;
        }

        if ($signalCount >= 2) {
            $confidence += 6;
            $reasons[] = 'múltiplos sinais';
        }

        return [
            'platform' => $attribution->platform ?? 'meta',
            'campaign_id' => $attribution->campaign_id,
            'adset_id' => $attribution->adset_id,
            'ad_id' => $attribution->ad_id,
            'model' => self::MODEL,
            'window_days' => $windowDays,
            'match_type' => $matchType,
            'time_to_sale_hours' => $hoursSinceLastInteraction,
            'time_from_last_interaction_hours' => $hoursSinceLastInteraction,
            'confidence_score' => min(92, $confidence),
            'confidence_reason' => 'Atribuído por '.implode(', ', $reasons).'.',
            'source_snapshot' => [
                'source' => 'car_ad_attributions',
                'attribution_id' => $attribution->id,
                'last_interaction_at' => $attribution->last_interaction_at,
                'has_whatsapp_click' => (bool) $attribution->has_whatsapp_click,
                'has_strong_intent' => (bool) $attribution->has_strong_intent,
                'has_lead' => (bool) $attribution->has_lead,
            ],
        ];
    }

    private function payloadFromMapping(object $mapping, int $windowDays, Carbon $soldAt): array
    {
        $level = !empty($mapping->ad_id) ? 'ad' : (!empty($mapping->adset_id) ? 'adset' : 'campaign');
        $hoursSinceMapping = $this->hoursBetween($mapping->updated_at, $soldAt);

        return [
            'platform' => $mapping->platform ?? 'meta',
            'campaign_id' => $mapping->campaign_id,
            'adset_id' => $mapping->adset_id,
            'ad_id' => $mapping->ad_id,
            'model' => self::MODEL,
            'window_days' => $windowDays,
            'match_type' => 'fallback',
            'time_to_sale_hours' => $hoursSinceMapping,
            'time_from_last_interaction_hours' => $hoursSinceMapping,
            'confidence_score' => ($mapping->is_active ? 35 : 25) + min(10, $this->temporalConfidenceBonus($hoursSinceMapping)),
            'confidence_reason' => "Fallback para mapping {$level} mais recente da viatura.",
            'source_snapshot' => [
                'source' => 'car_ad_campaigns',
                'mapping_id' => $mapping->id,
                'level' => $level,
                'is_active' => (bool) $mapping->is_active,
                'updated_at' => $mapping->updated_at,
            ],
        ];
    }

    private function hoursBetween(mixed $from, Carbon $soldAt): ?int
    {
        if (empty($from)) {
            return null;
        }

        return max(0, (int) Carbon::parse($from)->diffInHours($soldAt));
    }

    private function temporalConfidenceBonus(?int $hours): int
    {
        if ($hours === null) {
            return 0;
        }

        return match (true) {
            $hours <= 24 => 18,
            $hours <= 72 => 12,
            $hours <= 168 => 7,
            default => 2,
        };
    }
}
