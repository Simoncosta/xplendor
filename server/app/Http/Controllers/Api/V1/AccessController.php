<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Access\Access;
use App\Access\Permissions;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Services\CompanyModuleService;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Http\Request;

/**
 * ACL (F4): o que a pessoa pode fazer na empresa em que trabalha, para o ecrã esconder o que
 * o backend já recusa. As decisões são as do Access (as mesmas do middleware permission),
 * com o motivo de cada recusa. Os módulos vêm à parte: o menu junta os dois.
 *   GET /companies/{id}/my-access
 */
class AccessController extends Controller
{
    /** Permissões em que a impersonation conta (as decisões do cliente, os acessos e as integrações). */
    private const SENSITIVE = ['utilizadores.configurar', 'integracoes.configurar'];

    public function __construct(
        private readonly Access $access,
        private readonly CompanyAccess $companies,
        private readonly CompanyModuleService $modules,
    ) {}

    public function show(Request $request, int $companyId)
    {
        $user = $request->user();
        $permissions = [];
        $reasons = [];
        foreach (Permissions::all() as $permission) {
            $sensitive = in_array($permission, self::SENSITIVE, true) || Permissions::isClientDecision($permission);
            $decision = $this->access->can($user, $companyId, $permission, ['sensitive' => $sensitive]);
            $permissions[$permission] = $decision->allowed;
            if ($decision->denied()) {
                $reasons[$permission] = $decision->reason;
            }
        }
        $kind = $this->companies->kind($user, $companyId);
        $homeIsAgency = $user->company_id && Company::whereKey($user->company_id)->whereNotNull('agency_enabled_at')->exists();

        return ApiResponse::success([
            'company_id' => $companyId,
            'kind' => $kind,
            'is_root' => $user->isRoot(),
            'impersonating' => ImpersonationSession::activeFor($user) !== null,
            'modules' => $this->modules->enabledKeys($companyId),
            'permissions' => $permissions,
            'reasons' => (object) $reasons,
            'profile' => $user->profile ? ['id' => $user->profile->id, 'name' => $user->profile->name] : null,
            'agency_profile' => $kind === CompanyAccess::AGENCY && $user->agencyProfile ? ['id' => $user->agencyProfile->id, 'name' => $user->agencyProfile->name] : null,
            // Painel da agência (a própria agência): quem a administra gere atribuições, pedidos e faturação.
            'agency_admin' => $homeIsAgency && $this->companies->isAgencyAdmin($user, (int) $user->company_id),
        ], 'Acessos.');
    }
}
