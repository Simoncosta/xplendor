<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAdAttribution;
use App\Models\CarAdCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttributionService
{
    public function trackVisit(Car $car, Request $request): ?CarAdAttribution
    {
        $context = $this->extractTrackingContext($request);

        if (!$this->hasIdentity($context)) {
            return null;
        }

        return $this->upsertAttribution($car, $context, now());
    }

    public function trackInteraction(Car $car, string $type, array $context): ?CarAdAttribution
    {
        $request = $context['request'] ?? null;
        $trackingContext = $request instanceof Request
            ? $this->extractTrackingContext($request, $context)
            : $this->normalizeTrackingContext($context);

        if (!$this->hasIdentity($trackingContext)) {
            return null;
        }

        $attribution = $this->upsertAttribution($car, $trackingContext, now());

        if (!$attribution) {
            return null;
        }

        $updates = [
            'last_interaction_at' => now(),
        ];

        if ($type === 'whatsapp_click') {
            $updates['has_whatsapp_click'] = true;
        }

        $attribution->fill($updates)->save();

        Log::info('[Attribution] UPDATED', $this->logContext($attribution, [
            'event' => 'interaction',
            'interaction_type' => $type,
        ]));

        return $attribution->refresh();
    }

    public function trackLead(Car $car, Request $request): ?CarAdAttribution
    {
        $context = $this->extractTrackingContext($request);

        if (!$this->hasIdentity($context)) {
            return null;
        }

        $attribution = $this->upsertAttribution($car, $context, now());

        if (!$attribution) {
            return null;
        }

        $attribution->fill([
            'has_lead' => true,
            'last_interaction_at' => now(),
        ])->save();

        Log::info('[Attribution] UPDATED', $this->logContext($attribution, [
            'event' => 'lead',
        ]));

        return $attribution->refresh();
    }

    public function markStrongIntent(string $visitorId, int $carId, ?string $sessionId = null): void
    {
        $query = CarAdAttribution::query()
            ->where('car_id', $carId)
            ->where('visitor_id', $visitorId);

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }

        $attributions = $query->get();

        if ($attributions->isEmpty()) {
            Log::info('[Attribution] NO_MATCH', [
                'event' => 'strong_intent',
                'car_id' => $carId,
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
            ]);

            return;
        }

        foreach ($attributions as $attribution) {
            $attribution->fill([
                'has_strong_intent' => true,
                'last_interaction_at' => now(),
            ])->save();

            Log::info('[Attribution] UPDATED', $this->logContext($attribution, [
                'event' => 'strong_intent',
            ]));
        }
    }

    public function markLead(string $visitorId, int $carId, ?string $sessionId = null): void
    {
        $query = CarAdAttribution::query()
            ->where('car_id', $carId)
            ->where('visitor_id', $visitorId);

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }

        $attributions = $query->get();

        if ($attributions->isEmpty()) {
            Log::info('[Attribution] NO_MATCH', [
                'event' => 'lead_mark',
                'car_id' => $carId,
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
            ]);

            return;
        }

        foreach ($attributions as $attribution) {
            $attribution->fill([
                'has_lead' => true,
                'last_interaction_at' => now(),
            ])->save();

            Log::info('[Attribution] UPDATED', $this->logContext($attribution, [
                'event' => 'lead_mark',
            ]));
        }
    }

    public function getAttributionSummary(int $carId): array
    {
        $rows = CarAdAttribution::query()
            ->where('car_id', $carId)
            ->groupBy('campaign_id', 'adset_id', 'ad_id')
            ->orderBy('campaign_id')
            ->orderBy('adset_id')
            ->orderBy('ad_id')
            ->get([
                'campaign_id',
                'adset_id',
                'ad_id',
                DB::raw('COUNT(DISTINCT visitor_id) as total_visitors'),
                DB::raw('COUNT(DISTINCT session_id) as total_sessions'),
                DB::raw('SUM(CASE WHEN has_whatsapp_click = 1 THEN 1 ELSE 0 END) as whatsapp_clicks'),
                DB::raw('SUM(CASE WHEN has_strong_intent = 1 THEN 1 ELSE 0 END) as strong_intent_users'),
                DB::raw('SUM(CASE WHEN has_lead = 1 THEN 1 ELSE 0 END) as leads'),
            ])
            ->map(fn (CarAdAttribution $row) => [
                'campaign_id' => $row->campaign_id,
                'adset_id' => $row->adset_id,
                'ad_id' => $row->ad_id,
                'total_visitors' => (int) ($row->total_visitors ?? 0),
                'total_sessions' => (int) ($row->total_sessions ?? 0),
                'whatsapp_clicks' => (int) ($row->whatsapp_clicks ?? 0),
                'strong_intent_users' => (int) ($row->strong_intent_users ?? 0),
                'leads' => (int) ($row->leads ?? 0),
            ])
            ->values()
            ->all();

        $totals = CarAdAttribution::query()
            ->where('car_id', $carId)
            ->selectRaw('
                COUNT(DISTINCT visitor_id) as total_visitors,
                COUNT(DISTINCT session_id) as total_sessions,
                SUM(CASE WHEN has_whatsapp_click = 1 THEN 1 ELSE 0 END) as whatsapp_clicks,
                SUM(CASE WHEN has_strong_intent = 1 THEN 1 ELSE 0 END) as strong_intent_users,
                SUM(CASE WHEN has_lead = 1 THEN 1 ELSE 0 END) as leads
            ')
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'total_visitors' => (int) ($totals->total_visitors ?? 0),
                'total_sessions' => (int) ($totals->total_sessions ?? 0),
                'whatsapp_clicks' => (int) ($totals->whatsapp_clicks ?? 0),
                'strong_intent_users' => (int) ($totals->strong_intent_users ?? 0),
                'leads' => (int) ($totals->leads ?? 0),
            ],
        ];
    }

    private function upsertAttribution(Car $car, array $context, Carbon $occurredAt): ?CarAdAttribution
    {
        $attribution = $this->findExistingAttribution($car, $context);
        $resolved = $this->resolveCampaignData($car, $context);

        if (!$attribution) {
            $created = CarAdAttribution::create([
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'dealer_id' => $context['dealer_id'] ?? null,
                'source' => $resolved['source'],
                'platform' => $context['platform'] ?? 'meta',
                'campaign_id' => $resolved['campaign_id'],
                'adset_id' => $resolved['adset_id'],
                'ad_id' => $resolved['ad_id'],
                'visitor_id' => $context['visitor_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'utm_source' => $context['utm_source'] ?? null,
                'utm_medium' => $context['utm_medium'] ?? null,
                'utm_campaign' => $context['utm_campaign'] ?? null,
                'utm_content' => $context['utm_content'] ?? null,
                'utm_id' => $context['utm_id'] ?? null,
                'click_id' => $context['click_id'] ?? null,
                'first_interaction_at' => $occurredAt,
                'last_interaction_at' => $occurredAt,
            ]);

            Log::info('[Attribution] CREATED', $this->logContext($created, [
                'source_reason' => $resolved['reason'],
            ]));

            return $created;
        }

        $attribution->fill([
            'dealer_id' => $attribution->dealer_id ?? ($context['dealer_id'] ?? null),
            'platform' => $attribution->platform ?: ($context['platform'] ?? 'meta'),
            'source' => $this->lockFirstTouchValue($attribution->source, $resolved['source'], 'unknown'),
            'campaign_id' => $this->lockFirstTouchValue($attribution->campaign_id, $resolved['campaign_id']),
            'adset_id' => $this->lockFirstTouchValue($attribution->adset_id, $resolved['adset_id']),
            'ad_id' => $this->lockFirstTouchValue($attribution->ad_id, $resolved['ad_id']),
            'utm_source' => $this->lockFirstTouchValue($attribution->utm_source, $context['utm_source'] ?? null),
            'utm_medium' => $this->lockFirstTouchValue($attribution->utm_medium, $context['utm_medium'] ?? null),
            'utm_campaign' => $this->lockFirstTouchValue($attribution->utm_campaign, $context['utm_campaign'] ?? null),
            'utm_content' => $this->lockFirstTouchValue($attribution->utm_content, $context['utm_content'] ?? null),
            'utm_id' => $this->lockFirstTouchValue($attribution->utm_id, $context['utm_id'] ?? null),
            'click_id' => $this->lockFirstTouchValue($attribution->click_id, $context['click_id'] ?? null),
            'first_interaction_at' => $attribution->first_interaction_at ?? $occurredAt,
            'last_interaction_at' => $occurredAt,
        ])->save();

        Log::info('[Attribution] UPDATED', $this->logContext($attribution, [
            'source_reason' => $resolved['reason'],
        ]));

        return $attribution->refresh();
    }

    private function findExistingAttribution(Car $car, array $context): ?CarAdAttribution
    {
        $visitorId = $context['visitor_id'] ?? null;
        $sessionId = $context['session_id'] ?? null;

        if (!$visitorId || !$sessionId) {
            return null;
        }

        return CarAdAttribution::query()
            ->where([
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
            ])
            ->first();
    }

    private function resolveCampaignData(Car $car, array $context): array
    {
        $directAdId = $context['ad_id'] ?? null;
        if ($directAdId) {
            $mapping = $this->findMapping($car, $directAdId);

            Log::info('[Attribution] RESOLVED_DIRECT', [
                'ad_id' => $directAdId,
                'visitor_id' => $context['visitor_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'matched' => $mapping !== null,
            ]);

            if ($mapping) {
                return $this->resolvedPayload($mapping, 'direct', 'ad_id');
            }

            return [
                'source' => 'direct',
                'campaign_id' => null,
                'adset_id' => null,
                'ad_id' => $directAdId,
                'reason' => 'ad_id',
            ];
        }

        $utmId = $context['utm_id'] ?? null;
        if ($utmId) {
            $mapping = $this->findMapping($car, $utmId);

            Log::info('[Attribution] RESOLVED_UTM', [
                'utm_id' => $utmId,
                'visitor_id' => $context['visitor_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'matched' => $mapping !== null,
            ]);

            if ($mapping) {
                return $this->resolvedPayload($mapping, 'utm', 'utm_id');
            }
        }

        $utmCampaign = $context['utm_campaign'] ?? null;
        if ($utmCampaign) {
            $mapping = $this->findCampaignMappingByName($car, $utmCampaign);

            Log::info('[Attribution] RESOLVED_UTM', [
                'utm_campaign' => $utmCampaign,
                'visitor_id' => $context['visitor_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'matched' => $mapping !== null,
            ]);

            if ($mapping) {
                return $this->resolvedPayload($mapping, 'utm', 'utm_campaign');
            }
        }

        $fallback = CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->platform('meta')
            ->active()
            ->orderByDesc('ad_id')
            ->orderByDesc('adset_id')
            ->orderBy('id')
            ->first();

        if ($fallback) {
            Log::info('[Attribution] FALLBACK_USED', [
                'car_id' => $car->id,
                'visitor_id' => $context['visitor_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'campaign_id' => $fallback->campaign_id,
                'adset_id' => $fallback->adset_id,
                'ad_id' => $fallback->ad_id,
            ]);

            return $this->resolvedPayload($fallback, 'fallback', 'car_ad_campaigns');
        }

        Log::info('[Attribution] NO_MATCH', [
            'car_id' => $car->id,
            'visitor_id' => $context['visitor_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'ad_id' => $context['ad_id'] ?? null,
            'utm_id' => $context['utm_id'] ?? null,
            'utm_campaign' => $context['utm_campaign'] ?? null,
        ]);

        return [
            'source' => 'unknown',
            'campaign_id' => null,
            'adset_id' => null,
            'ad_id' => null,
            'reason' => 'unknown',
        ];
    }

    private function findMapping(Car $car, string $candidate): ?CarAdCampaign
    {
        return CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->platform('meta')
            ->active()
            ->where(function (Builder $query) use ($candidate) {
                $query->where('ad_id', $candidate)
                    ->orWhere('adset_id', $candidate)
                    ->orWhere('campaign_id', $candidate);
            })
            ->orderByRaw("CASE WHEN ad_id = ? THEN 3 WHEN adset_id = ? THEN 2 WHEN campaign_id = ? THEN 1 ELSE 0 END DESC", [
                $candidate,
                $candidate,
                $candidate,
            ])
            ->orderBy('id')
            ->first();
    }

    private function findCampaignMappingByName(Car $car, string $utmCampaign): ?CarAdCampaign
    {
        return CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->platform('meta')
            ->active()
            ->where(function (Builder $query) use ($utmCampaign) {
                $query->where('campaign_id', $utmCampaign)
                    ->orWhere('campaign_name', $utmCampaign);
            })
            ->orderBy('id')
            ->first();
    }

    private function resolvedPayload(CarAdCampaign $mapping, string $source, string $reason): array
    {
        return [
            'source' => $source,
            'campaign_id' => $mapping->campaign_id,
            'adset_id' => $mapping->adset_id,
            'ad_id' => $mapping->ad_id,
            'reason' => $reason,
        ];
    }

    private function extractTrackingContext(Request $request, array $overrides = []): array
    {
        $tracking = array_filter([
            ...($request->input('tracking', [])),
            ...$request->only([
                'visitor_id',
                'session_id',
                'referrer',
                'landing_path',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_content',
                'utm_id',
                'ad_id',
                'click_id',
                'dealer_id',
                'platform',
            ]),
            ...$overrides,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->normalizeTrackingContext($tracking, $request);
    }

    private function normalizeTrackingContext(array $tracking, ?Request $request = null): array
    {
        $queryValues = array_merge(
            $request?->query() ?? [],
            $this->parseQueryString($tracking['landing_path'] ?? null),
            $this->parseQueryString($tracking['page_url'] ?? null),
            $this->parseQueryString($tracking['referrer'] ?? null),
        );

        $clickId = $tracking['click_id']
            ?? $queryValues['fbclid']
            ?? $queryValues['gclid']
            ?? $queryValues['msclkid']
            ?? null;

        $platform = $tracking['platform']
            ?? $this->resolvePlatform(
                $tracking['utm_source'] ?? $queryValues['utm_source'] ?? null,
                $tracking['referrer'] ?? null
            );

        return [
            'visitor_id' => $tracking['visitor_id'] ?? null,
            'session_id' => $tracking['session_id'] ?? null,
            'dealer_id' => $tracking['dealer_id'] ?? null,
            'platform' => $platform,
            'campaign_id' => $tracking['campaign_id'] ?? null,
            'adset_id' => $tracking['adset_id'] ?? null,
            'ad_id' => $tracking['ad_id'] ?? $queryValues['ad_id'] ?? null,
            'utm_source' => $tracking['utm_source'] ?? $queryValues['utm_source'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? $queryValues['utm_medium'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? $queryValues['utm_campaign'] ?? null,
            'utm_content' => $tracking['utm_content'] ?? $queryValues['utm_content'] ?? null,
            'utm_id' => $tracking['utm_id'] ?? $queryValues['utm_id'] ?? null,
            'click_id' => $clickId,
        ];
    }

    private function parseQueryString(?string $url): array
    {
        if (!$url) {
            return [];
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (!$query) {
            return [];
        }

        parse_str($query, $values);

        return is_array($values) ? $values : [];
    }

    private function resolvePlatform(?string $utmSource, ?string $referrer): string
    {
        $source = strtolower((string) $utmSource);
        $ref = strtolower((string) $referrer);

        if (
            str_contains($source, 'meta')
            || str_contains($source, 'facebook')
            || str_contains($source, 'instagram')
            || str_contains($ref, 'facebook.')
            || str_contains($ref, 'instagram.')
        ) {
            return 'meta';
        }

        return 'meta';
    }

    private function hasIdentity(array $context): bool
    {
        return !empty($context['visitor_id']) && !empty($context['session_id']);
    }

    private function lockFirstTouchValue(mixed $current, mixed $incoming, mixed $emptySentinel = null): mixed
    {
        if ($current !== null && $current !== '' && $current !== $emptySentinel) {
            return $current;
        }

        return $incoming ?? $current;
    }

    private function logContext(CarAdAttribution $attribution, array $extra = []): array
    {
        return [
            'attribution_id' => $attribution->id,
            'company_id' => $attribution->company_id,
            'car_id' => $attribution->car_id,
            'visitor_id' => $attribution->visitor_id,
            'session_id' => $attribution->session_id,
            'source' => $attribution->source,
            'campaign_id' => $attribution->campaign_id,
            'adset_id' => $attribution->adset_id,
            'ad_id' => $attribution->ad_id,
            ...$extra,
        ];
    }
}
