<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\MetaAdsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MetaOAuthController extends Controller
{
    public function __construct(
        private readonly MetaAdsService $metaAds
    ) {}

    // ── Passo 1: Gerar URL de autorização ─────────────────────────────────────
    // GET /companies/{id}/integrations/meta/oauth-url
    // O frontend abre esta URL numa popup ou redirect

    public function getAuthUrl(int $companyId): JsonResponse
    {
        $params = http_build_query([
            'client_id'     => config('services.meta.app_id'),
            'redirect_uri'  => config('services.meta.redirect_uri'),
            'scope'         => 'ads_read,business_management',
            'response_type' => 'code',
            'state'         => base64_encode(json_encode([
                'company_id' => $companyId,
                'csrf'       => csrf_token(),
            ])),
        ]);

        $url = 'https://www.facebook.com/v25.0/dialog/oauth?' . $params;

        return ApiResponse::success(['url' => $url]);
    }

    // ── Passo 2: Callback do Meta ─────────────────────────────────────────────
    // POST /integrations/meta/callback
    // O frontend chama este endpoint com o code recebido do Meta
    // Body: { code, state, account_id }

    public function handleCallback(Request $request): JsonResponse
    {
        $request->validate([
            'code'       => 'required|string',
            'state'      => 'required|string',
            'account_id' => 'required|string',
        ]);

        // Validar state e extrair company_id
        try {
            $state     = json_decode(base64_decode($request->state), true);
            $companyId = $state['company_id'] ?? null;
        } catch (\Throwable $e) {
            return ApiResponse::error('State inválido.', 422);
        }

        if (!$companyId) {
            return ApiResponse::error('company_id em falta no state.', 422);
        }

        // Trocar code por token de curta duração
        $shortTokenResponse = \Illuminate\Support\Facades\Http::post(
            'https://graph.facebook.com/v25.0/oauth/access_token',
            [
                'client_id'     => config('services.meta.app_id'),
                'client_secret' => config('services.meta.app_secret'),
                'redirect_uri'  => config('services.meta.redirect_uri'),
                'code'          => $request->code,
            ]
        );

        if ($shortTokenResponse->failed()) {
            Log::error('MetaOAuth: falha ao trocar code', [
                'status' => $shortTokenResponse->status(),
                'body'   => $shortTokenResponse->body(),
            ]);
            return ApiResponse::error('Falha ao obter token do Meta. Tenta novamente.', 422);
        }

        $shortToken = $shortTokenResponse->json('access_token');

        // Trocar por token de longa duração (~60 dias)
        $longToken = $this->metaAds->getLongLivedToken(
            config('services.meta.app_id'),
            config('services.meta.app_secret'),
            $shortToken
        );

        if (!$longToken) {
            return ApiResponse::error('Falha ao obter token de longa duração.', 422);
        }

        // Verificar expiração
        $appToken  = config('services.meta.app_id') . '|' . config('services.meta.app_secret');
        $tokenInfo = $this->metaAds->debugToken($longToken, $appToken);
        $expiresAt = isset($tokenInfo['expires_at'])
            ? \Carbon\Carbon::createFromTimestamp($tokenInfo['expires_at'])
            : now()->addDays(60);

        // Guardar na base de dados
        CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'meta'],
            [
                'access_token'     => $longToken,
                'account_id'       => $request->account_id,
                'token_expires_at' => $expiresAt,
                'status'           => 'active',
                'error_message'    => null,
            ]
        );

        return ApiResponse::success([], 'Meta Ads conectado com sucesso.');
    }
}
