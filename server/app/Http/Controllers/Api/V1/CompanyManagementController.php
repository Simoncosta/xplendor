<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ManagementRequest;
use App\Services\Agency\AgencyConnectionsService;
use App\Services\Agency\ManagementEndEffects;
use App\Services\Agency\ManagementRequestService;
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
        $own = $this->isOwnAdmin($user, $companyId);

        return ApiResponse::success([
            'agency' => $m ? ['id' => $m->agency_company_id, 'name' => $m->agency?->trade_name ?: $m->agency?->fiscal_name] : null,
            'since' => $m ? optional($m->responded_at ?? $m->requested_at)->toIso8601String() : null,
            'can_end' => $m !== null && $this->isOwnAdmin($user, $companyId),
            'via_agency' => $this->access->viaAgency($user, $companyId),
            'can_invite_first_admin' => $this->canInviteFirstAdmin($user, $company),
            // O formulário do perfil fica só de leitura quando a pessoa não o pode gravar (regra do CompanyRequest).
            'can_edit_company' => $this->access->canEditCompany($user, $companyId),
            'can_edit_basics_only' => $this->access->agencyEditsBasics($user, $companyId),
            // F1d (só para os admins da própria empresa): pedidos por responder, ligações da
            // agência atual (para a escolha ao terminar) e a escolha pendente depois de um fim.
            'pending_requests' => $own ? ManagementRequest::where('managed_company_id', $companyId)->where('status', ManagementRequest::PENDING)
                ->where('expires_at', '>', now())->count() : 0,
            'agency_connections' => $own && $m ? app(AgencyConnectionsService::class)->agencyMade($companyId, $m->agency_company_id) : [],
            'connections_decision' => $own ? $this->pendingDecision($companyId) : null,
            // A página do pedido de gestão (sem o resto da app) para quem está sem acesso.
            'company_name' => $own ? ($company->trade_name ?: $company->fiscal_name) : null,
            'has_platform_access' => $company->hasPlatformAccess(),
            // Quem produz para a empresa (nos textos da Linha Editorial): "a sua agência (Nome)" para o
            // cliente, "a agência Nome" para quem trabalha pela agência, ou a equipa XPLENDOR.
            'producer_label' => \App\Services\Editorial\EditorialWorkflowService::producerLabel($companyId, ! $this->access->viaAgency($user, $companyId)),
        ], 'Agência gestora.');
    }

    // ── Pedidos de gestão recebidos (só os admins da própria empresa) ────────

    public function requests(Request $request, int $companyId)
    {
        $this->assertOwnAdmin($request, $companyId);
        $rows = ManagementRequest::where('managed_company_id', $companyId)->where('status', ManagementRequest::PENDING)
            ->where('expires_at', '>', now())->orderByDesc('id')->get();

        return ApiResponse::success(['requests' => $rows->map(fn ($r) => ManagementRequestService::presentForCompany($r))->values()], 'Pedidos de gestão.');
    }

    public function accept(Request $request, int $companyId, int $requestId, ManagementRequestService $service)
    {
        $this->assertOwnAdmin($request, $companyId);
        $service->accept(ManagementRequest::findOrFail($requestId), Company::findOrFail($companyId), $request->user());

        return ApiResponse::success(null, 'Pedido aceite. A agência passou a gerir a sua empresa.');
    }

    public function decline(Request $request, int $companyId, int $requestId, ManagementRequestService $service)
    {
        $this->assertOwnAdmin($request, $companyId);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $service->decline(ManagementRequest::findOrFail($requestId), Company::findOrFail($companyId), $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(null, 'Pedido recusado.');
    }

    // POST { decision: keep|disconnect }: a escolha sobre as ligações deixadas pela agência.
    public function decideConnections(Request $request, int $companyId, ManagementEndEffects $effects)
    {
        $this->assertOwnAdmin($request, $companyId);
        $data = $request->validate(['decision' => ['required', 'in:keep,disconnect']]);
        $m = CompanyManagement::where('managed_company_id', $companyId)->where('connections_decision', ManagementEndEffects::DECISION_PENDING)->latest('id')->first();
        if (! $m) {
            throw ValidationException::withMessages(['decision' => ['Não há ligações por decidir.']]);
        }
        $effects->decideConnections($m, $data['decision']);

        return ApiResponse::success(null, $data['decision'] === 'disconnect' ? 'Ligações da agência desligadas.' : 'Ligações mantidas.');
    }

    private function pendingDecision(int $companyId): ?array
    {
        $m = CompanyManagement::where('managed_company_id', $companyId)->where('connections_decision', ManagementEndEffects::DECISION_PENDING)
            ->with('agency:id,fiscal_name,trade_name')->latest('id')->first();
        if (! $m) {
            return null;
        }

        return ['agency' => $m->agency?->trade_name ?: $m->agency?->fiscal_name,
            'connections' => app(AgencyConnectionsService::class)->agencyMade($companyId, $m->agency_company_id)];
    }

    private function assertOwnAdmin(Request $request, int $companyId): void
    {
        abort_unless($this->isOwnAdmin($request->user(), $companyId) && $request->user()->role === 'admin', 403, 'Só os administradores da empresa decidem sobre a gestão por agências.');
    }

    public function end(Request $request, int $companyId)
    {
        if (! $this->isOwnAdmin($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode terminar a relação com a agência.', 403);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'connections' => ['nullable', 'in:keep,disconnect']]);
        $this->service->endByCompany(Company::findOrFail($companyId), $request->user(), $data['reason'] ?? null, $data['connections'] ?? null);

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
