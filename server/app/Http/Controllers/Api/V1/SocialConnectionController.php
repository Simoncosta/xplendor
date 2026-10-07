<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CollaboratorService;
use App\Services\Social\MetaSocialClient;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Redes sociais (Instagram e Facebook) nas Integrações. Ver: qualquer utilizador da
 * empresa (o tenant já validou o {id}). Ligar, escolher contas e desligar: só o
 * administrador da própria empresa e nunca em impersonation (a rota também tem
 * block_when_impersonating, como as outras credenciais de integrações).
 */
class SocialConnectionController extends Controller
{
    public function __construct(private readonly SocialConnectionService $social) {}

    // GET /companies/{id}/integrations/social
    public function show(Request $request, int $companyId)
    {
        return ApiResponse::success(
            $this->social->status($companyId) + ['can_manage' => CollaboratorService::canConfigureIntegrations($request->user(), $companyId)],
            'Estado das redes sociais.'
        );
    }

    // GET /companies/{id}/integrations/social/auth-url
    public function authUrl(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);

        return ApiResponse::success(['url' => $this->social->authUrl($companyId, $request->user())]);
    }

    // GET /companies/{id}/integrations/social/candidates
    public function candidates(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);

        return ApiResponse::success($this->social->candidates($companyId), 'Contas disponíveis.');
    }

    // PUT /companies/{id}/integrations/social/accounts
    public function saveAccounts(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);
        $data = $request->validate([
            'facebook' => ['present', 'array', 'max:50'],
            'facebook.*' => ['string', 'regex:/^\d{1,40}$/'],
            'instagram' => ['present', 'array', 'max:50'],
            'instagram.*' => ['string', 'regex:/^\d{1,40}$/'],
            'primary_facebook' => ['nullable', 'string'],
            'primary_instagram' => ['nullable', 'string'],
        ]);

        $this->social->saveSelection(
            $companyId,
            array_values(array_unique($data['facebook'])),
            array_values(array_unique($data['instagram'])),
            $data['primary_facebook'] ?? null,
            $data['primary_instagram'] ?? null,
        );

        return ApiResponse::success(
            $this->social->status($companyId) + ['can_manage' => true],
            'Contas guardadas. A ler os seguidores.'
        );
    }

    // DELETE /companies/{id}/integrations/social   Body (opcional): { purge, confirmation }
    public function disconnect(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);
        $data = $request->validate([
            'purge' => 'sometimes|boolean',
            'confirmation' => 'nullable|string',
        ]);
        $purge = (bool) ($data['purge'] ?? false);
        if ($purge && ($data['confirmation'] ?? null) !== SocialConnectionService::PURGE_CONFIRMATION) {
            return ApiResponse::error('Para apagar o histórico de seguidores, confirme escrevendo ' . SocialConnectionService::PURGE_CONFIRMATION . '.', 422);
        }

        $result = $this->social->disconnect($companyId, $purge);
        Log::info('[Redes sociais] Desligado.', ['company_id' => $companyId] + $result);

        return ApiResponse::success(
            $result + ['state' => $this->social->status($companyId) + ['can_manage' => true]],
            $purge ? 'Redes sociais desligadas e histórico automático apagado.' : 'Redes sociais desligadas. O histórico foi mantido.'
        );
    }

    // GET /api/oauth/meta/social/callback?code&state (público: navegação do browser)
    public function callback(Request $request): RedirectResponse
    {
        $base = self::appBase();
        [$companyId, $signal] = $this->social->handleCallback(
            (string) $request->query('state', ''),
            $request->filled('code') ? (string) $request->query('code') : null,
            $request->filled('error') ? (string) $request->query('error') : null,
        );

        if ($companyId === null) {
            return redirect()->away($base . '/?social=error&reason=state');
        }
        [$kind, $reason] = array_pad(explode(':', $signal, 2), 2, null);
        $query = $kind === 'choose' ? 'social=choose' : 'social=error&reason=' . $reason;

        return redirect()->away($base . '/companies/' . $companyId . '?' . $query);
    }

    /** https://host/api/oauth/meta/social/callback → https://host/app (fallback: APP_URL). */
    private static function appBase(): string
    {
        $redirect = MetaSocialClient::redirectUri();
        $origin = preg_replace('#/api/oauth/meta/social/callback/?$#', '', $redirect);
        if ($origin === null || $origin === '' || $origin === $redirect) {
            $origin = rtrim((string) config('app.url'), '/');
        }

        return rtrim($origin, '/') . '/app';
    }

    private function assertCanManage(Request $request, int $companyId): void
    {
        if (! CollaboratorService::canConfigureIntegrations($request->user(), $companyId)) {
            abort(403, 'Só o administrador da empresa ou a agência gestora pode ligar ou desligar as redes sociais.');
        }
    }
}
