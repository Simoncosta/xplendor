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
        // Ligar os anúncios: admin da própria empresa (o root na sua), fora de impersonation.
        if (! CollaboratorService::canManageAccess($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode ligar os anúncios da Meta.', 403);
        }

        // CSRF/state correcto: um nonce aleatório, guardado server-side (cache)
        // ligado a este company_id e de uso único. Substitui o base64 com
        // company_id+csrf_token() — em API stateless o csrf_token() vinha vazio
        // ("csrf":null) e nunca era validado. O nonce é opaco e inforjável.
        $nonce = \Illuminate\Support\Str::random(40);
        \Illuminate\Support\Facades\Cache::put(
            self::stateCacheKey($nonce),
            $companyId,
            now()->addMinutes(15)
        );

        $params = http_build_query([
            'client_id'     => config('services.meta.app_id'),
            'redirect_uri'  => config('services.meta.redirect_uri'),
            'scope'         => 'ads_read',
            'response_type' => 'code',
            'state'         => $nonce,
        ]);

        $url = 'https://www.facebook.com/v25.0/dialog/oauth?' . $params;

        return ApiResponse::success(['url' => $url]);
    }

    private static function stateCacheKey(string $nonce): string
    {
        return 'meta_oauth_state:' . $nonce;
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

        // 1) State primeiro: identifica a empresa e protege contra CSRF (nonce
        //    de uso único). pull() lê e apaga — não pode ser reutilizado.
        $nonce     = (string) $request->query('state', '');
        $companyId = $nonce !== ''
            ? \Illuminate\Support\Facades\Cache::pull(self::stateCacheKey($nonce))
            : null;

        if (!$companyId) {
            // State inválido/expirado: não sabemos a empresa → volta à raiz da app.
            return redirect()->away($base . '/?meta=error&reason=state');
        }

        $companyReturn = $base . '/companies/' . $companyId;

        // 2) O utilizador recusou / erro do próprio Meta.
        if ($request->filled('error') || !$request->filled('code')) {
            $reason = $request->filled('error') ? 'denied' : 'params';
            return redirect()->away($companyReturn . '?meta=error&reason=' . $reason);
        }

        // 3) Trocar code → token curto (secret SÓ aqui, no backend).
        $shortTokenResponse = \Illuminate\Support\Facades\Http::asForm()->post(
            'https://graph.facebook.com/v25.0/oauth/access_token',
            [
                'client_id'     => config('services.meta.app_id'),
                'client_secret' => config('services.meta.app_secret'),
                'redirect_uri'  => config('services.meta.redirect_uri'),
                'code'          => (string) $request->query('code'),
            ]
        );

        if ($shortTokenResponse->failed()) {
            Log::error('MetaOAuth: falha ao trocar code (redirect)', [
                'status' => $shortTokenResponse->status(),
                'body'   => $shortTokenResponse->body(),
            ]);
            return redirect()->away($companyReturn . '?meta=error&reason=token');
        }

        $shortToken = $shortTokenResponse->json('access_token');

        $longToken = $this->metaAds->getLongLivedToken(
            config('services.meta.app_id'),
            config('services.meta.app_secret'),
            $shortToken
        );

        if (!$longToken) {
            return redirect()->away($companyReturn . '?meta=error&reason=token');
        }

        $appToken  = config('services.meta.app_id') . '|' . config('services.meta.app_secret');
        $tokenInfo = $this->metaAds->debugToken($longToken, $appToken);
        $expiresAt = isset($tokenInfo['expires_at'])
            ? \Carbon\Carbon::createFromTimestamp($tokenInfo['expires_at'])
            : now()->addDays(60);

        // 4) Guardar o token. NÃO tocamos no account_id aqui: fica o que já
        //    existia (reconexão) ou null (primeira vez) — escolhido em /app.
        $integration = CompanyIntegration::firstOrNew([
            'company_id' => $companyId,
            'platform'   => 'meta',
        ]);
        $integration->access_token     = $longToken;
        $integration->token_expires_at = $expiresAt;
        $integration->status           = 'active';
        $integration->error_message    = null;
        $integration->save();

        // 5) Disparar já o BACKFILL de 90 dias (ingestão ao nível da conta). Sem
        //    conta de anúncios fica needs_account e corre quando a conta for escolhida.
        app(\App\Services\MetaAccountInsightsService::class)->scheduleBackfill($integration);

        // 6) Voltar a /app: com conta → connected; sem conta → tem de a escolher.
        $signal = $integration->account_id ? 'connected' : 'choose_account';
        return redirect()->away($companyReturn . '?meta=' . $signal);
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
        $companyId = \Illuminate\Support\Facades\Cache::pull(self::stateCacheKey((string) $request->state));

        if (!$companyId) {
            return ApiResponse::error('State inválido ou expirado.', 422);
        }

        $user = $request->user();
        if (!$user || ! CollaboratorService::canManageAccess($user, (int) $companyId)) {
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
        $expiresAt = isset($tokenInfo['expires_at'])
            ? \Carbon\Carbon::createFromTimestamp($tokenInfo['expires_at'])
            : now()->addDays(60);

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
            ]
        );

        // Ingestão ao nível da conta: backfill de 90 dias (e limpa a conta antiga se mudou).
        app(\App\Services\MetaAccountInsightsService::class)->onAccountChanged($integration, $previousAccount);

        return ApiResponse::success([], 'Meta Ads conectado com sucesso.');
    }
}
