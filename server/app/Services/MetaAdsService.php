<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsService
{
    private const GRAPH_URL = 'https://graph.facebook.com/v25.0';

    // ── Métricas de performance por adset/campanha ─────────────────────────────
    // Devolve spend, impressions, clicks, cpm, ctr, cpc, reach, frequency
    // para o período especificado.

    public function getAdsetInsights(
        string $accessToken,
        string $adsetId,
        string $dateStart,
        string $dateStop
    ): array {
        $response = Http::get(self::GRAPH_URL . "/{$adsetId}/insights", [
            'access_token' => $accessToken,
            'fields'       => 'spend,impressions,clicks,cpm,ctr,cpc,reach,frequency',
            'time_range'   => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'level'        => 'adset',
        ]);

        if ($response->failed()) {
            Log::error('MetaAdsService: getAdsetInsights failed', [
                'adset_id' => $adsetId,
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
}
