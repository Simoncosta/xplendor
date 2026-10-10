<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\User;
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
        abort_unless($user && $user->isRoot(), 403);

        return ApiResponse::success([
            'ok'   => true,
            'role' => $user->role,
            'name' => $user->name,
        ], 'Admin console reachable.');
    }

    /**
     * Contagens transversais da plataforma para o dashboard root (todas as empresas).
     *  · cars_in_stock: viaturas EM STOCK (Car::IN_STOCK_STATUSES, a fonte única) de empresas
     *    ATIVAS (Company::active, a mesma definição do guard de acesso e do Stock global).
     *  · cars_total e users_total: totais reais, sem filtros (todos os estados e empresas),
     *    mostrados como referência ("total da plataforma").
     * Empresas vêm de /admin/companies; tickets/quotes dos seus próprios summaries.
     * Root-only (defesa em profundidade além do middleware).
     */
    public function platformSummary()
    {
        abort_unless(Auth::user()?->isRoot(), 403);

        return ApiResponse::success([
            'users_total'   => User::count(),
            'cars_total'    => Car::count(),
            'cars_in_stock' => Car::query()
                ->whereIn('status', Car::IN_STOCK_STATUSES)
                ->whereHas('company', fn ($q) => $q->active())
                ->count(),
        ], 'Resumo da plataforma.');
    }
}
