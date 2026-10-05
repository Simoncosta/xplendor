<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarSaleAttribution;
use App\Models\MetaAd;
use App\Repositories\CarAdSpendRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Atribuição de uma venda a uma campanha/anúncio Meta. Ordem (a primeira que
 * encontrar ganha; cada venda tem UMA atribuição):
 *   1. evidência de clique (car_ad_attributions, janela recente). Se o anúncio tem a
 *      tag de OUTRA viatura, conta na mesma (o anúncio trouxe o cliente ao stand),
 *      com menos confiança e marcado cross_car_ad;
 *   2. recurso pela TAG: anúncio com a tag desta viatura que gastou nos 14 dias
 *      antes da venda (tag_fallback, confiança baixa);
 *   3. recurso ao mapeamento manual (CarAdCampaign), só para campanhas SEM tag.
 * Precedência do 2B: numa campanha com tag, a tag ganha e o mapeamento não conta.
 */
class CampaignToSaleAttributionService
{
    private const DEFAULT_WINDOW_DAYS = 7;
    private const MODEL = 'last_touch_recent_window';
    private const TAG_FALLBACK_DAYS = 14;
    private const CROSS_CAR_PENALTY = 15;

    public function attributeSale(Car $car, array $context = []): array
    {
        $soldAt = Carbon::parse($context['sold_at'] ?? $car->sold_at ?? now());
        $windowDays = (int) ($context['window_days'] ?? self::DEFAULT_WINDOW_DAYS);
        $from = $soldAt->copy()->subDays($windowDays);

        $tagged = app(CarAdSpendRepository::class)->taggedCampaignIdList((int) $car->company_id);

        $attribution = $this->bestRecentAttribution($car, $from, $soldAt, $tagged);

        if ($attribution) {
            return $this->payloadFromAttribution($car, $attribution, $windowDays, $soldAt);
        }

        $tagAd = $this->tagFallback($car, $soldAt);

        if ($tagAd) {
            return $this->payloadFromTag($tagAd, $windowDays, $soldAt);
        }

        $mapping = $this->fallbackMapping($car, $soldAt, $tagged);

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
            // Venda SEM campanha: tipo próprio, separado do recurso ao mapeamento
            // ('fallback'), para não se misturarem nas estatísticas.
            'match_type' => 'none',
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

    /**
     * Grava a atribuição da venda. UMA por venda: a chave é a viatura (a car_sales só
     * permite uma venda por viatura), com a ligação à venda registada em car_sale_id.
     * Antes a chave era a hora (sold_at): marcar a venda duas vezes com horas
     * diferentes criava duas atribuições para a mesma venda.
     */
    public function recordSaleAttribution(Car $car, array $context = []): CarSaleAttribution
    {
        $soldAt = Carbon::parse($context['sold_at'] ?? $car->sold_at ?? now());
        $result = $this->attributeSale($car, $context);
        $saleId = DB::table('car_sales')->where('car_id', $car->id)->value('id');

        $record = CarSaleAttribution::query()->updateOrCreate(
            [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
            ],
            [
                'car_sale_id' => $saleId,
                'sold_at' => $soldAt,
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

    private function bestRecentAttribution(Car $car, Carbon $from, Carbon $soldAt, array $tagged = []): ?object
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
            // Registos que vieram do recurso ao mapeamento manual numa campanha que já
            // tem tag não contam (a tag ganha).
            ->when($tagged !== [], fn ($q) => $q->where(function ($w) use ($tagged) {
                $w->where('source', '!=', 'fallback')
                    ->orWhereNull('campaign_id')
                    ->orWhereNotIn('campaign_id', $tagged);
            }))
            ->orderByRaw('CASE WHEN ad_id IS NOT NULL THEN 1 WHEN adset_id IS NOT NULL THEN 2 WHEN campaign_id IS NOT NULL THEN 3 ELSE 4 END')
            ->orderByDesc('has_whatsapp_click')
            ->orderByDesc('has_strong_intent')
            ->orderByDesc('has_lead')
            ->orderByDesc('last_interaction_at')
            ->first();
    }

    /**
     * Anúncio com a tag desta viatura que gastou nos 14 dias antes da venda (o de
     * gasto mais recente; empate → o de maior gasto).
     */
    private function tagFallback(Car $car, Carbon $soldAt): ?object
    {
        return DB::table('meta_ad_car_spend_daily')
            ->where('company_id', $car->company_id)
            ->where('tagged_car_id', $car->id)
            ->whereBetween('date', [$soldAt->copy()->subDays(self::TAG_FALLBACK_DAYS)->toDateString(), $soldAt->toDateString()])
            ->where('spend_allocated', '>', 0)
            ->selectRaw('ad_id, campaign_id, adset_id, MAX(date) as last_date, SUM(spend_allocated) as spend')
            ->groupBy('ad_id', 'campaign_id', 'adset_id')
            ->orderByDesc('last_date')
            ->orderByDesc('spend')
            ->first();
    }

    private function fallbackMapping(Car $car, Carbon $soldAt, array $tagged = []): ?object
    {
        $from = $soldAt->copy()->subDays(14);

        return DB::table('car_ad_campaigns')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->when($tagged !== [], fn ($q) => $q->whereNotIn('campaign_id', $tagged))
            ->where(function ($query) use ($from) {
                $query->where('is_active', true)
                    ->orWhere('updated_at', '>=', $from);
            })
            ->orderByDesc('is_active')
            ->orderByRaw('CASE WHEN ad_id IS NOT NULL THEN 1 WHEN adset_id IS NOT NULL THEN 2 ELSE 3 END')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function payloadFromAttribution(Car $car, object $attribution, int $windowDays, Carbon $soldAt): array
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

        // Anúncio com a tag de OUTRA viatura: conta (trouxe o cliente ao stand), com
        // menos confiança e marcado.
        $crossCar = null;
        if (! empty($attribution->ad_id)) {
            $ad = MetaAd::where('company_id', $car->company_id)->where('ad_id', $attribution->ad_id)
                ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])->first();
            $adCars = array_map('intval', (array) ($ad?->tag_car_ids ?? []));
            if ($ad && $adCars !== [] && ! in_array((int) $car->id, $adCars, true)) {
                $crossCar = $adCars;
                $confidence -= self::CROSS_CAR_PENALTY;
                $matchType = 'cross_car_ad';
                $reasons[] = 'anúncio da viatura n.º ' . implode(', ', $adCars) . ' (o cliente comprou outra viatura)';
            }
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
                'cross_car' => $crossCar !== null,
                'ad_tag_car_ids' => $crossCar,
            ],
        ];
    }

    private function payloadFromTag(object $tagAd, int $windowDays, Carbon $soldAt): array
    {
        $lastSpend = Carbon::parse($tagAd->last_date)->endOfDay();
        $days = max(0, (int) Carbon::parse($tagAd->last_date)->startOfDay()->diffInDays($soldAt->copy()->startOfDay()));
        $hours = $this->hoursBetween($lastSpend->min($soldAt), $soldAt);
        $confidence = 30 + match (true) {
            $days <= 3 => 10,
            $days <= 7 => 5,
            default => 0,
        };

        return [
            'platform' => 'meta',
            'campaign_id' => $tagAd->campaign_id,
            'adset_id' => $tagAd->adset_id,
            'ad_id' => $tagAd->ad_id,
            'model' => self::MODEL,
            'window_days' => $windowDays,
            'match_type' => 'tag_fallback',
            'time_to_sale_hours' => $hours,
            'time_from_last_interaction_hours' => null,
            'confidence_score' => $confidence,
            'confidence_reason' => sprintf(
                'Anúncio com a etiqueta desta viatura com gasto até %s, sem clique registado.',
                Carbon::parse($tagAd->last_date)->format('d/m/Y')
            ),
            'source_snapshot' => [
                'source' => 'meta_ad_tag',
                'last_spend_date' => (string) $tagAd->last_date,
                'spend_window' => round((float) $tagAd->spend, 2),
                'window_days' => self::TAG_FALLBACK_DAYS,
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
