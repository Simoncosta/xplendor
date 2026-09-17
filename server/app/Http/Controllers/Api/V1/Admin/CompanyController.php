<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
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
}
