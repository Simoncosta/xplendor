<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\CollaboratorService;
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

    public function getAuthUrl(Request $request, int $companyId): JsonResponse
    {

        // Nonce de uso único em cache, com a empresa e quem iniciou (MetaAdsConnectionService).
        $url = app(\App\Services\Integrations\MetaAdsConnectionService::class)->authUrl($companyId, $request->user()?->id);

        return ApiResponse::success(['url' => $url]);
    }

    /**
     * Base de retorno para a app (/app), derivada do próprio redirect_uri para
     * acertar sempre com o ambiente (dev ngrok ou prod) sem nova env var:
     * https://host/api/oauth/meta/callback → https://host/app
     * Fallback: APP_URL.
     */
    private function appReturnBase(): string
    {
        $redirect = (string) config('services.meta.redirect_uri');
        $origin   = preg_replace('#/api/oauth/meta/callback/?$#', '', $redirect);

        if ($origin === null || $origin === '' || $origin === $redirect) {
            $origin = rtrim((string) config('app.url'), '/');
        }

        return rtrim($origin, '/') . '/app';
    }

    // ── Passo 2 (NOVO): Callback do Meta tratado no BACKEND ───────────────────
    // GET /api/oauth/meta/callback?code&state   (público — redirect do browser)
    //
    // O Meta redireciona o browser para aqui. Validamos o state (nonce em cache),
    // trocamos o code pelo token (secret no backend), guardamos a credencial SEM
    // account_id (escolhido depois em /app) e REDIRECIONAMOS para /app. Nunca
    // devolve JSON — é navegação de browser.
    public function handleCallbackRedirect(Request $request): \Illuminate\Http\RedirectResponse
    {
        $base = $this->appReturnBase();
        [$companyId, $signal, $linkId] = app(\App\Services\Integrations\MetaAdsConnectionService::class)->handleCallback(
            (string) $request->query('state', ''),
            $request->filled('code') ? (string) $request->query('code') : null,
            $request->filled('error') ? (string) $request->query('error') : null,
        );

        if (! $companyId) {
            // State inválido/expirado: não sabemos a empresa → volta à raiz da app.
            return redirect()->away($base . '/?meta=error&reason=state');
        }
        // Iniciado no link de configuração do cliente: volta à página pública (token no fragmento).
        if ($linkId) {
            return redirect()->away(app(\App\Services\Setup\SetupPublicService::class)->afterOAuth($linkId, \App\Models\CompanySetupLink::STEP_META_ADS, $signal, $base));
        }

        [$kind, $reason] = array_pad(explode(':', $signal, 2), 2, null);
        $query = $kind === 'error' ? 'meta=error&reason=' . ($reason === 'not_approved' || $reason === 'scopes' ? 'denied' : $reason) : 'meta=' . $kind;

        return redirect()->away($base . '/companies/' . $companyId . '?' . $query);
    }

    // ── Passo 2 (LEGADO): Callback via POST do frontend ───────────────────────
    // POST /integrations/meta/callback  (mantido por retrocompatibilidade; o
    // fluxo novo usa handleCallbackRedirect acima)
    // Body: { code, state, account_id }

    public function handleCallback(Request $request): JsonResponse
    {
        $request->validate([
            'code'       => 'required|string',
            'state'      => 'required|string',
            'account_id' => 'required|string',
        ]);

        // State = o MESMO nonce de uso único do fluxo GET (emitido por getAuthUrl,
        // que só o dá para a empresa do utilizador). O antigo base64 com
        // company_id era forjável e deixava ligar a Meta de qualquer empresa.
        $state = \Illuminate\Support\Facades\Cache::pull(\App\Services\Integrations\MetaAdsConnectionService::stateKey((string) $request->state));
        // Os links de configuração do cliente nunca passam por aqui.
        $companyId = is_array($state) ? (empty($state['setup_link_id']) ? ($state['company_id'] ?? null) : null) : $state;

        if (!$companyId) {
            return ApiResponse::error('State inválido ou expirado.', 422);
        }

        $user = $request->user();
        // O callback não tem empresa no endereço: a mesma decisão do ACL, com a regra da impersonation.
        if (!$user || app(\App\Access\Access::class)->can($user, (int) $companyId, 'integracoes.configurar', ['sensitive' => true])->denied()) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
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
        // 0 ou ausente = sem data de expiração (NULL), nunca 1970.
        $expiresAt = \App\Support\MetaTokenExpiry::fromDebug($tokenInfo);

        // Guardar na base de dados
        $previousAccount = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')->value('account_id');

        $integration = CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'meta'],
            [
                'access_token'     => $longToken,
                'account_id'       => $request->account_id,
                'token_expires_at' => $expiresAt,
                'status'           => 'active',
                'error_message'    => null,
                'connected_by_user_id' => $user->id,
            ]
        );

        // Ingestão ao nível da conta: backfill de 90 dias (e limpa a conta antiga se mudou).
        app(\App\Services\MetaAccountInsightsService::class)->onAccountChanged($integration, $previousAccount);

        return ApiResponse::success([], 'Meta Ads conectado com sucesso.');
    }
}
