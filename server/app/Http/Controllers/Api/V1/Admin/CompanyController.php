<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\ModuleRegistry;
use App\Services\CompanyModuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Gestão de empresas, lado ADMIN (super-admin / root). Ativar/inativar
 * uma empresa. Vive no grupo /api/v1/admin, atrás do EnsureSuperAdmin.
 *
 * Inativar = subscription_status 'cancelled' → hasPlatformAccess() passa a false.
 * A partir daí, de forma CONSISTENTE e num só conceito:
 *   · os utilizadores da empresa perdem acesso (middleware CheckCompanySubscription);
 *   · a empresa e os seus veículos somem das vistas de admin (Company::scopeActive).
 * Ativar = 'active' (repõe o acesso). Defesa em profundidade: reconfirma root.
 */
class CompanyController extends Controller
{
    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    /**
     * TODAS as empresas (transversal, root-only): nome, plano, estado, nº de utilizadores.
     * Base da página root → escolher empresa → utilizadores → "entrar como" (impersonation).
     */
    public function index()
    {
        $this->ensureRoot();

        $companies = Company::query()
            ->withCount('users')
            ->with(['plan:id,name', 'activeManagement.agency:id,fiscal_name,trade_name,agency_enabled_at,subscription_status,trial_ends_at'])
            ->orderBy('fiscal_name')
            ->get()
            ->map(fn (Company $c) => [
                'id'                  => $c->id,
                'name'                => $c->fiscal_name ?: $c->trade_name,
                'plan'                => $c->plan?->name,
                'subscription_status' => $c->subscription_status,
                'has_access'          => $c->hasPlatformAccess(),
                'trial_ends_at'       => optional($c->trial_ends_at)->toDateString(),
                'users_count'         => $c->users_count,
                'is_agency'           => $c->isAgency(),
                'managed_by'          => $c->activeManagement ? [
                    'id'   => $c->activeManagement->agency_company_id,
                    'name' => $c->activeManagement->agency?->trade_name ?: $c->activeManagement->agency?->fiscal_name,
                ] : null,
            ]);

        return ApiResponse::success(['companies' => $companies], 'Empresas carregadas.');
    }

    /**
     * Utilizadores de UMA empresa (transversal, root-only). ⚠️ TEM de ser por aqui: o
     * UserController normal recusa (403) o root a ver users de outra empresa. Este grupo
     * /admin (ensure_super_admin) é o ponto único de acesso transversal.
     */
    public function users(int $companyId)
    {
        $this->ensureRoot();

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        $users = \App\Models\User::where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'avatar']);

        return ApiResponse::success([
            'company' => ['id' => $company->id, 'name' => $company->fiscal_name ?: $company->trade_name],
            'users'   => $users,
        ], 'Utilizadores carregados.');
    }

    public function setStatus(Request $request, int $companyId)
    {
        $this->ensureRoot();

        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        if ($data['active']) {
            $company->update([
                'subscription_status' => Company::SUBSCRIPTION_STATUS_ACTIVE,
                'subscription_ends_at' => null,
            ]);
        } else {
            $company->update([
                'subscription_status' => Company::SUBSCRIPTION_STATUS_CANCELLED,
                'subscription_ends_at' => now(),
            ]);
        }

        return ApiResponse::success(
            $company->fresh(),
            $data['active'] ? 'Empresa ativada.' : 'Empresa inativada.'
        );
    }

    // ── Módulos por empresa (Incremento 1) ────────────────────────────────────

    /** Estado de todos os módulos desta empresa (para a UI de gestão). */
    public function modules(int $companyId, CompanyModuleService $service)
    {
        $this->ensureRoot();

        if (! Company::whereKey($companyId)->exists()) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        return ApiResponse::success([
            'modules' => $service->overview($companyId),
            'presets' => array_keys(ModuleRegistry::PRESETS),
            'history' => $service->history($companyId),
        ], 'Módulos carregados.');
    }

    /** Liga/desliga um módulo (respeitando a teia de dependências). */
    public function setModule(Request $request, int $companyId, CompanyModuleService $service)
    {
        $this->ensureRoot();

        if (! Company::whereKey($companyId)->exists()) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        $data = $request->validate([
            'module_key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        // ValidationException (422) sobe automaticamente se o desligar for bloqueado.
        if ($data['enabled']) {
            $service->enable($companyId, $data['module_key'], 'manual', $request->user()->id);
        } else {
            $service->disable($companyId, $data['module_key'], 'manual', $request->user()->id);
        }

        return ApiResponse::success([
            'modules' => $service->overview($companyId),
            'history' => $service->history($companyId),
        ], 'Módulo atualizado.');
    }

    /** Aplica um preset de ramo (atalho; ajustável depois). */
    public function applyModulePreset(Request $request, int $companyId, CompanyModuleService $service)
    {
        $this->ensureRoot();

        if (! Company::whereKey($companyId)->exists()) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        $data = $request->validate([
            'preset' => ['required', 'string'],
        ]);

        $service->applyPreset($companyId, $data['preset'], $request->user()->id);

        return ApiResponse::success([
            'modules' => $service->overview($companyId),
            'history' => $service->history($companyId),
        ], 'Preset aplicado.');
    }
}
