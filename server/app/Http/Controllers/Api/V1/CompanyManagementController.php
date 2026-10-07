<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\CollaboratorService;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\CompanyManagementService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gestão por agências, lado da EMPRESA GERIDA:
 *  · qualquer pessoa com acesso vê a agência gestora;
 *  · só o admin da própria empresa termina a relação (acesso da agência cortado de
 *    imediato; os dados ficam na empresa);
 *  · a agência (ou o root) convida o PRIMEIRO admin de uma empresa que ainda não tem
 *    nenhum (D5). Fora disso, a agência nunca gere os acessos do cliente.
 */
class CompanyManagementController extends Controller
{
    public function __construct(
        private readonly CompanyManagementService $service,
        private readonly CompanyAccess $access,
    ) {}

    public function show(Request $request, int $companyId)
    {
        $company = Company::findOrFail($companyId);
        $m = $company->activeManagement()->with('agency:id,fiscal_name,trade_name')->first();
        $user = $request->user();

        return ApiResponse::success([
            'agency' => $m ? ['id' => $m->agency_company_id, 'name' => $m->agency?->trade_name ?: $m->agency?->fiscal_name] : null,
            'since' => $m ? optional($m->responded_at ?? $m->requested_at)->toIso8601String() : null,
            'can_end' => $m !== null && $this->isOwnAdmin($user, $companyId),
            'via_agency' => $this->access->viaAgency($user, $companyId),
            'can_invite_first_admin' => $this->canInviteFirstAdmin($user, $company),
        ], 'Agência gestora.');
    }

    public function end(Request $request, int $companyId)
    {
        if (! $this->isOwnAdmin($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode terminar a relação com a agência.', 403);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->service->endByCompany(Company::findOrFail($companyId), $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(null, 'Relação terminada. A agência deixou de ter acesso a esta empresa.');
    }

    /** D5: o primeiro admin de uma empresa sem nenhum, convidado pela agência ou pelo root. */
    public function inviteFirstAdmin(Request $request, int $companyId, UserService $users)
    {
        $company = Company::findOrFail($companyId);
        $user = $request->user();
        if (! $this->access->viaAgency($user, $companyId) && $user->role !== 'root') {
            return ApiResponse::error('Só a agência gestora convida o primeiro administrador.', 403);
        }
        if (! $this->canInviteFirstAdmin($user, $company)) {
            throw ValidationException::withMessages(['email' => ['Esta empresa já tem um administrador (ou um convite pendente).']]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);
        $invite = $users->store(['name' => $data['name'], 'email' => $data['email'], 'company_id' => $companyId, 'role' => 'admin']);

        return ApiResponse::success($invite, 'Convite enviado ao administrador da empresa.');
    }

    private function isOwnAdmin(User $user, int $companyId): bool
    {
        return CollaboratorService::canManageAccess($user, $companyId);
    }

    private function canInviteFirstAdmin(User $user, Company $company): bool
    {
        if (! $this->access->viaAgency($user, $company->id) && $user->role !== 'root') {
            return false;
        }

        return ! User::where('company_id', $company->id)->where('role', 'admin')->whereNull('deactivated_at')->exists()
            && ! UserInvite::where('company_id', $company->id)->where('role', 'admin')->whereNull('accepted_at')->where('expires_at', '>', now())->exists();
    }
}
