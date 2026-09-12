<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * DMS — Consola de Administração da plataforma (super-admin / root).
 *
 * Base da futura consola (empresas, utilizadores, métricas globais). Vive no
 * grupo /api/v1/admin, atrás do EnsureSuperAdmin — o único portão do acesso
 * transversal. Nesta fundação só existe o ping para provar o portão.
 *
 * Defesa em profundidade: reconfirma role 'root' mesmo já estando atrás do
 * middleware (nunca confiar num só nível para acesso cross-tenant).
 */
class AdminController extends Controller
{
    public function ping()
    {
        $user = Auth::user();
        abort_unless($user && $user->role === 'root', 403);

        return ApiResponse::success([
            'ok'   => true,
            'role' => $user->role,
            'name' => $user->name,
        ], 'Admin console reachable.');
    }
}
