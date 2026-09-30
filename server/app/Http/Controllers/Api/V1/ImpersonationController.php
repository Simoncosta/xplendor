<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — IMPERSONATION. start: SÓ root (gated por ensure_super_admin) → emite um token
 * Sanctum PARA o user-alvo (TTL curto, ability ['impersonation']) e regista a sessão. O
 * token autentica COMO o alvo → tenancy uniforme, sem superpoderes de root lá dentro. stop:
 * termina a sessão e revoga o token. current: verdade do backend para o banner do frontend.
 */
class ImpersonationController extends Controller
{
    /** Vida do token de impersonation (min). Sem auto-renovação: expira → reinicia-se. */
    private const TTL_MINUTES = 30;

    /** POST /admin/impersonation/start — o ensure_super_admin garante que o requester é root. */
    public function start(Request $request)
    {
        $root = Auth::user();
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'reason'  => ['nullable', 'string', 'max:255'],
        ]);

        $target = User::find($data['user_id']);
        if (! $target) {
            return ApiResponse::error('Utilizador não encontrado.', 404);
        }
        if ($target->role === 'root') {
            return ApiResponse::error('Não é possível impersonar outro administrador.', 422);
        }
        if (! $target->company_id) {
            return ApiResponse::error('Utilizador sem empresa associada.', 422);
        }

        // Token PARA o alvo: TTL curto + ability restrita (NÃO full-power).
        $newToken = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(self::TTL_MINUTES));

        ImpersonationSession::create([
            'root_id'        => $root->id,
            'target_user_id' => $target->id,
            'company_id'     => $target->company_id,
            'token_id'       => $newToken->accessToken->getKey(),
            'reason'         => $data['reason'] ?? null,
            'ip'             => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
            'started_at'     => now(),
        ]);

        $target->loadMissing('company');

        return ApiResponse::success([
            'token'        => $newToken->plainTextToken,
            'expires_at'   => optional($newToken->accessToken->expires_at)->toISOString(),
            'user'         => $this->publicUser($target),
            'company'      => ['id' => $target->company?->id, 'name' => $target->company?->fiscal_name],
            'impersonator' => ['id' => $root->id, 'name' => $root->name],
        ], 'Impersonation iniciada.');
    }

    /** POST /impersonation/stop — termina a sessão ativa e revoga o token de impersonation. */
    public function stop(Request $request)
    {
        $user = Auth::user();
        $token = $user?->currentAccessToken();

        if ($token) {
            ImpersonationSession::where('token_id', $token->getKey())
                ->whereNull('ended_at')->update(['ended_at' => now()]);
            // Revoga o token de impersonation (se for um PAT com delete()).
            if (method_exists($token, 'delete')) {
                $token->delete();
            }
        }

        return ApiResponse::success(null, 'Impersonation terminada.');
    }

    /** GET /impersonation/current — verdade do backend para o banner (reconciliação). */
    public function current(Request $request)
    {
        $user = Auth::user();
        $session = ImpersonationSession::activeFor($user);

        if (! $session) {
            return ApiResponse::success(['impersonating' => false], 'Sem impersonation ativa.');
        }

        $user->loadMissing('company');
        $root = User::find($session->root_id);

        return ApiResponse::success([
            'impersonating' => true,
            'user'          => $this->publicUser($user),
            'company'       => ['id' => $user->company?->id, 'name' => $user->company?->fiscal_name],
            'impersonator'  => ['id' => $root?->id, 'name' => $root?->name],
            'expires_at'    => optional($user->currentAccessToken()?->expires_at)->toISOString(),
        ], 'Impersonation ativa.');
    }

    private function publicUser(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'role' => $u->role, 'company_id' => $u->company_id, 'avatar' => $u->avatar,
        ];
    }
}
