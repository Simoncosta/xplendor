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
}
