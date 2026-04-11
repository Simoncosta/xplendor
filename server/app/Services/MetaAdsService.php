<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaAdsService
{
    private const GRAPH_URL = 'https://graph.facebook.com/v25.0';
    private const GRAPH_ACTIONS_URL = 'https://graph.facebook.com/v18.0';

    // ── Métricas de performance por ad/campanha/adset ──────────────────────────
    // Devolve spend, impressions, clicks, cpm, ctr, cpc, reach, frequency
    // para o período especificado.

    public function getInsights(
        string $accessToken,
        string $targetId,
        string $level,
        string $dateStart,
        string $dateStop
    ): array {
        $response = Http::get(self::GRAPH_URL . "/{$targetId}/insights", [
            'access_token' => $accessToken,
            'fields'       => 'spend,impressions,clicks,cpm,ctr,cpc,reach,frequency',
            'time_range'   => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'level'        => $level,
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getInsights failed', [
                'target_id' => $targetId,
                'level' => $level,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            return [];
        }

        $data = $response->json('data', []);
        return $data[0] ?? [];
    }

    // ── Breakdown por faixa etária e género ───────────────────────────────────
    // "Ouro" para alimentar o motor de marketing IA com públicos reais.

    public function getAudienceBreakdown(
        string $accessToken,
        string $adsetId,
        string $dateStart,
        string $dateStop
    ): array {
        $response = Http::get(self::GRAPH_URL . "/{$adsetId}/insights", [
            'access_token' => $accessToken,
            'fields'       => 'impressions,clicks,spend,reach',
            'breakdowns'   => 'age,gender',
            'time_range'   => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'level'        => 'adset',
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getAudienceBreakdown failed', [
                'adset_id' => $adsetId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            return [];
        }

        return $response->json('data', []);
    }

    public function getAdsetTargeting(
        string $accessToken,
        string $adsetId
    ): array {
        return $this->getAdsetTargetingDetails($accessToken, $adsetId)['normalized_targeting'];
    }

    public function getAdsetTargetingDetails(
        string $accessToken,
        string $adsetId
    ): array {
        $response = Http::get(self::GRAPH_URL . "/{$adsetId}", [
            'access_token' => $accessToken,
            'fields' => 'targeting',
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getAdsetTargeting failed', [
                'adset_id' => $adsetId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [
                'adset_id' => $adsetId,
                'has_targeting' => false,
                'raw_payload' => null,
                'normalized_targeting' => [],
            ];
        }

        $rawPayload = $response->json();
        Log::info('TARGETING DEBUG RAW', [
            'adset_id' => $adsetId,
            'full_response' => $rawPayload,
            'targeting_exists' => isset($rawPayload['targeting']),
            'targeting' => $rawPayload['targeting'] ?? null,
        ]);

        $targeting = $response->json('targeting', []);
        $automation = $response->json('targeting_automation', []);
        $normalized = $this->normalizeTargeting($targeting, $automation);

        Log::info('MetaAdsService: adset targeting payload fetched', [
            'adset_id' => $adsetId,
            'has_targeting' => !empty($targeting),
            'has_targeting_automation' => !empty($automation),
            'raw_payload' => $rawPayload,
            'normalized_targeting' => $normalized,
        ]);

        return [
            'adset_id' => $adsetId,
            'has_targeting' => !empty($targeting),
            'raw_payload' => $rawPayload,
            'normalized_targeting' => $normalized,
        ];
    }

    // ── Listar adsets de uma conta ────────────────────────────────────────────
    // Usado no frontend para o gestor seleccionar qual adset mapear a cada carro.

    public function getAdsets(string $accessToken, string $accountId): array
    {
        $response = Http::get(self::GRAPH_URL . "/act_{$accountId}/adsets", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,status,campaign_id,campaign{name}',
            'limit'        => 200,
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getAdsets failed', [
                'account_id' => $accountId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return [];
        }

        return $response->json('data', []);
    }

    public function getCampaignHierarchy(string $accessToken, string $accountId): array
    {
        $campaigns = $this->getCampaigns($accessToken, $accountId);
        $adsets = $this->getAdsets($accessToken, $accountId);
        $ads = $this->getAds($accessToken, $accountId);

        $adsByAdset = collect($ads)
            ->filter(fn(array $ad) => !empty($ad['adset_id']))
            ->groupBy('adset_id');

        $adsetsByCampaign = collect($adsets)
            ->groupBy('campaign_id')
            ->map(function ($items) use ($adsByAdset) {
                return collect($items)
                    ->map(function (array $adset) use ($adsByAdset) {
                        return [
                            'id' => (string) ($adset['id'] ?? ''),
                            'name' => (string) ($adset['name'] ?? ''),
                            'status' => (string) ($adset['status'] ?? 'UNKNOWN'),
                            'campaign_id' => (string) ($adset['campaign_id'] ?? ''),
                            'level' => 'adset',
                            'ads' => collect($adsByAdset->get($adset['id'], []))
                                ->map(fn(array $ad) => [
                                    'id' => (string) ($ad['id'] ?? ''),
                                    'name' => (string) ($ad['name'] ?? ''),
                                    'status' => (string) ($ad['status'] ?? 'UNKNOWN'),
                                    'campaign_id' => (string) ($ad['campaign_id'] ?? ''),
                                    'adset_id' => (string) ($ad['adset_id'] ?? ''),
                                    'level' => 'ad',
                                ])
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values()
                    ->all();
            });

        return collect($campaigns)
            ->map(function (array $campaign) use ($adsetsByCampaign) {
                return [
                    'id' => (string) ($campaign['id'] ?? ''),
                    'name' => (string) ($campaign['name'] ?? ''),
                    'status' => (string) ($campaign['status'] ?? 'UNKNOWN'),
                    'level' => 'campaign',
                    'adsets' => $adsetsByCampaign->get($campaign['id'], []),
                ];
            })
            ->values()
            ->all();
    }

    public function pauseAd(int $companyId, string $adId): array
    {
        return $this->pauseTarget($companyId, 'ad', $adId);
    }

    public function pauseAdSet(int $companyId, string $adsetId): array
    {
        return $this->pauseTarget($companyId, 'adset', $adsetId);
    }

    public function pauseCampaign(int $companyId, string $campaignId): array
    {
        return $this->pauseTarget($companyId, 'campaign', $campaignId);
    }

    public function getTargetStatuses(int $companyId, array $targets): array
    {
        $normalizedTargets = collect($targets)
            ->filter(fn(array $target) => !empty($target['id']))
            ->map(fn(array $target) => [
                'id' => (string) $target['id'],
                'level' => (string) ($target['level'] ?? 'unknown'),
            ])
            ->unique(fn(array $target) => $target['level'] . ':' . $target['id'])
            ->values();

        if ($normalizedTargets->isEmpty()) {
            return [];
        }

        try {
            $integration = $this->resolveActiveIntegration($companyId);
        } catch (\Throwable $exception) {
            Log::warning('MetaAdsService: getTargetStatuses fallback to unknown', [
                'company_id' => $companyId,
                'error' => $exception->getMessage(),
            ]);

            return $normalizedTargets
                ->mapWithKeys(fn(array $target) => [$target['level'] . ':' . $target['id'] => 'unknown'])
                ->all();
        }

        $statuses = [];
        $missingTargets = [];

        foreach ($normalizedTargets as $target) {
            $cacheKey = $this->buildTargetStatusCacheKey($companyId, $target['level'], $target['id']);
            $cachedStatus = Cache::get($cacheKey);

            if (is_string($cachedStatus) && $cachedStatus !== '') {
                $statuses[$target['level'] . ':' . $target['id']] = $cachedStatus;
                continue;
            }

            $missingTargets[] = $target;
        }

        if (!empty($missingTargets)) {
            $fetchedStatuses = $this->fetchTargetStatusesBatch($integration->access_token, $missingTargets);

            foreach ($missingTargets as $target) {
                $key = $target['level'] . ':' . $target['id'];
                $status = $fetchedStatuses[$key] ?? 'unknown';
                $statuses[$key] = $status;

                Cache::put(
                    $this->buildTargetStatusCacheKey($companyId, $target['level'], $target['id']),
                    $status,
                    now()->addMinutes(5)
                );
            }
        }

        return $statuses;
    }

    // ── Trocar código por token de longa duração ──────────────────────────────
    // Usado uma única vez quando o gestor conecta a conta.
    // O token curto (60min) é trocado por um de longa duração (~60 dias).

    public function getLongLivedToken(
        string $appId,
        string $appSecret,
        string $shortLivedToken
    ): ?string {
        $response = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $appId,
            'client_secret'     => $appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getLongLivedToken failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        return $response->json('access_token');
    }

    // ── Verificar validade do token ───────────────────────────────────────────
    public function debugToken(string $accessToken, string $appToken): array
    {
        $response = Http::get(self::GRAPH_URL . '/debug_token', [
            'input_token'  => $accessToken,
            'access_token' => $appToken,
        ]);

        return $response->json('data', []);
    }

    private function normalizeTargeting(array $targeting, array $automation): array
    {
        $locations = collect($targeting['geo_locations']['countries'] ?? [])
            ->filter(fn($item) => is_string($item) && trim($item) !== '')
            ->map(fn($item) => strtoupper($item) === 'PT' ? 'Portugal' : $item)
            ->values()
            ->all();

        $interests = collect($targeting['interests'] ?? [])
            ->merge($targeting['flexible_spec'][0]['interests'] ?? [])
            ->filter(fn($item) => is_array($item) && !empty($item['name']))
            ->map(fn($item) => trim((string) $item['name']))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $genders = collect($targeting['genders'] ?? [])
            ->map(function ($gender) {
                return match ((int) $gender) {
                    1 => 'male',
                    2 => 'female',
                    default => 'unknown',
                };
            })
            ->filter(fn($item) => $item !== 'unknown')
            ->values()
            ->all();

        $audienceMode = !empty($automation)
            ? 'advantage_plus'
            : (!empty($targeting['targeting_automation']) ? 'advantage_plus' : 'manual');

        return [
            'location' => $locations,
            'age_min' => isset($targeting['age_min']) ? (int) $targeting['age_min'] : null,
            'age_max' => isset($targeting['age_max']) ? (int) $targeting['age_max'] : null,
            'genders' => $genders,
            'interests' => $interests,
            'audience_mode' => $audienceMode,
        ];
    }

    private function getCampaigns(string $accessToken, string $accountId): array
    {
        $response = Http::get(self::GRAPH_URL . "/act_{$accountId}/campaigns", [
            'access_token' => $accessToken,
            'fields' => 'id,name,status',
            'limit' => 200,
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getCampaigns failed', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json('data', []);
    }

    private function getAds(string $accessToken, string $accountId): array
    {
        $response = Http::get(self::GRAPH_URL . "/act_{$accountId}/ads", [
            'access_token' => $accessToken,
            'fields' => 'id,name,status,campaign_id,adset_id',
            'limit' => 500,
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getAds failed', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json('data', []);
    }

    private function fetchTargetStatusesBatch(string $accessToken, array $targets): array
    {
        $batch = array_map(
            fn(array $target) => [
                'method' => 'GET',
                'relative_url' => $target['id'] . '?fields=status',
            ],
            $targets
        );

        $response = Http::asForm()->post(self::GRAPH_URL, [
            'access_token' => $accessToken,
            'batch' => json_encode($batch),
        ]);

        if ($response->failed()) {
            Log::warning('MetaAdsService: fetchTargetStatusesBatch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return collect($targets)
                ->mapWithKeys(fn(array $target) => [$target['level'] . ':' . $target['id'] => 'unknown'])
                ->all();
        }

        $responses = $response->json();

        if (!is_array($responses)) {
            return collect($targets)
                ->mapWithKeys(fn(array $target) => [$target['level'] . ':' . $target['id'] => 'unknown'])
                ->all();
        }

        $statuses = [];

        foreach ($targets as $index => $target) {
            $key = $target['level'] . ':' . $target['id'];
            $body = $responses[$index]['body'] ?? null;
            $payload = is_string($body) ? json_decode($body, true) : null;
            $statuses[$key] = strtoupper((string) ($payload['status'] ?? 'unknown'));
        }

        return $statuses;
    }

    private function buildTargetStatusCacheKey(int $companyId, string $level, string $targetId): string
    {
        return implode(':', ['meta-target-status', $companyId, $level, $targetId]);
    }

    private function pauseTarget(int $companyId, string $level, string $targetId): array
    {
        $targetId = trim($targetId);

        if ($targetId === '') {
            throw new DomainException('O target da Meta Ads é obrigatório para executar a pausa.');
        }

        if (!in_array($level, ['ad', 'adset', 'campaign'], true)) {
            throw new DomainException('Nível inválido para pausa na Meta Ads.');
        }

        $integration = $this->resolveActiveIntegration($companyId);

        Log::info('[MetaPause] REQUEST', [
            'company_id' => $companyId,
            'target_id' => $targetId,
            'level' => $level,
        ]);

        $response = Http::asForm()->post(self::GRAPH_ACTIONS_URL . "/{$targetId}", [
            'access_token' => $integration->access_token,
            'status' => 'PAUSED',
        ]);

        $responseBody = $response->json() ?: ['raw' => $response->body()];

        Log::info('[MetaPause] RESPONSE', [
            'company_id' => $companyId,
            'target_id' => $targetId,
            'level' => $level,
            'response' => $responseBody,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $this->handleIntegrationError($integration, $response->status(), $response->body());

            Log::error('[MetaPause] ERROR', [
                'company_id' => $companyId,
                'target_id' => $targetId,
                'level' => $level,
                'error' => $this->extractGraphErrorMessage($response->json(), $response->body()),
                'response' => $responseBody,
                'status_code' => $response->status(),
            ]);

            Log::error('MetaAdsService: pauseTarget failed', [
                'company_id' => $companyId,
                'platform' => 'meta',
                'target_level' => $level,
                'target_id' => $targetId,
                'status_code' => $response->status(),
                'body' => $responseBody,
            ]);

            throw new RuntimeException($this->extractGraphErrorMessage($response->json(), $response->body()));
        }

        $payload = $responseBody;

        Log::info('MetaAdsService: pauseTarget succeeded', [
            'company_id' => $companyId,
            'platform' => 'meta',
            'target_level' => $level,
            'target_id' => $targetId,
            'response' => $payload,
        ]);

        return [
            'success' => true,
            'platform' => 'meta',
            'target_level' => $level,
            'target_id' => $targetId,
            'meta_response' => $payload,
        ];
    }

    private function resolveActiveIntegration(int $companyId): CompanyIntegration
    {
        $integration = CompanyIntegration::query()
            ->where('company_id', $companyId)
            ->where('platform', 'meta')
            ->where('status', 'active')
            ->first();

        if (!$integration) {
            throw new DomainException('A empresa não tem uma integração Meta Ads ativa.');
        }

        if (empty($integration->access_token)) {
            $integration->update([
                'status' => 'error',
                'error_message' => 'Integração Meta Ads sem access token.',
            ]);

            throw new DomainException('A integração Meta Ads ativa não tem access token válido.');
        }

        if ($integration->isTokenExpired()) {
            $integration->update([
                'status' => 'expired',
                'error_message' => 'Token Meta Ads expirado.',
            ]);

            throw new DomainException('O token da integração Meta Ads expirou.');
        }

        return $integration;
    }

    private function handleIntegrationError(CompanyIntegration $integration, int $statusCode, string $body): void
    {
        $payload = json_decode($body, true);
        $error = is_array($payload) ? ($payload['error'] ?? []) : [];
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = $this->extractGraphErrorMessage($payload, $body);

        $isAuthError = $statusCode === 401
            || in_array($code, [190, 102], true)
            || in_array($subcode, [463, 467], true);

        if (!$isAuthError) {
            return;
        }

        $integration->update([
            'status' => $integration->isTokenExpired() ? 'expired' : 'error',
            'error_message' => $message,
        ]);
    }

    private function extractGraphErrorMessage(?array $payload, string $fallback): string
    {
        $message = trim((string) ($payload['error']['message'] ?? ''));

        if ($message !== '') {
            return $message;
        }

        $fallback = trim($fallback);

        return $fallback !== '' ? $fallback : 'A Meta Ads devolveu um erro inesperado.';
    }
}
