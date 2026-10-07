<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ManagedCompanyRequest;
use App\Models\User;
use App\Services\Agency\AgencyEditorialService;
use App\Services\Agency\ManagedCompanyRequestService;
use App\Services\Tenancy\CompanyManagementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista da agência (/agencies/{agency}/…, middleware "agency"): a Linha Editorial de todos
 * os clientes que a pessoa vê e da própria agência, o painel por cliente, as atribuições
 * (só o admin da agência) e os pedidos de nova empresa gerida (só o admin da agência pede).
 */
class AgencyController extends Controller
{
    public function __construct(
        private readonly AgencyEditorialService $editorial,
        private readonly ManagedCompanyRequestService $requests,
        private readonly CompanyManagementService $managements,
    ) {}

    private function agency(Request $request): Company
    {
        return $request->attributes->get('agency');
    }

    /** @return int[]|null */
    private function only(Request $request): ?array
    {
        $data = $request->validate(['company_ids' => ['nullable', 'array', 'max:200'], 'company_ids.*' => ['integer']]);

        return $data['company_ids'] ?? null;
    }

    private function month(Request $request): string
    {
        return $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']])['month'];
    }

    // GET /agencies/{agency}/companies : as empresas da vista (filtro por cliente)
    public function companies(Request $request)
    {
        $agency = $this->agency($request);
        $all = $this->editorial->companies($agency, $request->user(), null, false);
        $editorial = $this->editorial->companies($agency, $request->user())->pluck('id')->all();

        return ApiResponse::success($all->map(fn (Company $c) => AgencyEditorialService::present($c, $agency)
            + ['has_editorial' => in_array($c->id, $editorial, true)])->values(), 'Empresas da agência.');
    }

    // GET /agencies/{agency}/editorial/calendar|board?month=YYYY-MM&company_ids[]=
    public function posts(Request $request)
    {
        $agency = $this->agency($request);
        $month = $this->month($request);
        $companies = $this->editorial->companies($agency, $request->user(), $this->only($request));

        return ApiResponse::success(['month' => $month, 'posts' => $this->editorial->posts($companies, $agency, $request->user(), $month)], 'Publicações da agência.');
    }

    // GET /agencies/{agency}/editorial/today
    public function today(Request $request)
    {
        $agency = $this->agency($request);

        return ApiResponse::success($this->editorial->today($this->editorial->companies($agency, $request->user(), $this->only($request)), $agency, $request->user()), 'Para publicar hoje.');
    }

    // GET /agencies/{agency}/editorial/awaiting
    public function awaiting(Request $request)
    {
        $agency = $this->agency($request);

        return ApiResponse::success(['posts' => $this->editorial->awaiting($this->editorial->companies($agency, $request->user(), $this->only($request)), $agency)], 'À espera de aprovação.');
    }

    // GET /agencies/{agency}/editorial/results?month=
    public function results(Request $request)
    {
        $agency = $this->agency($request);
        $month = $this->month($request);

        return ApiResponse::success(['month' => $month, 'rows' => $this->editorial->results($this->editorial->companies($agency, $request->user(), $this->only($request)), $agency, $month)], 'Resultados do mês.');
    }

    // GET /agencies/{agency}/panel
    public function panel(Request $request)
    {
        $agency = $this->agency($request);

        return ApiResponse::success(['rows' => $this->editorial->panel($this->editorial->companies($agency, $request->user()), $agency)], 'Painel da agência.');
    }

    // ── Atribuições (só o admin da agência e o root) ─────────────────────────

    public function assignments(Request $request)
    {
        $agency = $this->agency($request);
        $this->assertAgencyAdmin($request);
        $members = User::where('company_id', $agency->id)->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name', 'role']);
        $rows = CompanyManagement::active()->where('agency_company_id', $agency->id)->with(['managed:id,fiscal_name,trade_name,logo_path', 'members:id,management_id,user_id'])->get()
            ->sortBy(fn ($m) => mb_strtolower(AgencyEditorialService::name($m->managed)))->values()
            ->map(fn (CompanyManagement $m) => [
                'company' => AgencyEditorialService::present($m->managed, $agency),
                'team_scope' => $m->team_scope,
                'member_ids' => $m->members->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ]);

        return ApiResponse::success(['members' => $members, 'clients' => $rows], 'Atribuições.');
    }

    // PUT /agencies/{agency}/assignments/{companyId} { team_scope: all|assigned, member_ids[] }
    public function assign(Request $request, int $agencyId, int $companyId)
    {
        $agency = $this->agency($request);
        $this->assertAgencyAdmin($request);
        $data = $request->validate([
            'team_scope' => ['required', Rule::in([CompanyManagement::SCOPE_ALL, CompanyManagement::SCOPE_ASSIGNED])],
            'member_ids' => ['array'], 'member_ids.*' => ['integer'],
        ]);
        $m = CompanyManagement::active()->where('agency_company_id', $agency->id)->where('managed_company_id', $companyId)->first();
        abort_unless($m !== null, 404, 'Cliente não encontrado nesta agência.');
        $m->update(['team_scope' => $data['team_scope']]);
        $this->managements->syncMembers($m, $data['member_ids'] ?? [], $request->user());

        return ApiResponse::success(['team_scope' => $m->team_scope, 'member_ids' => $m->members()->pluck('user_id')->all()], 'Atribuições guardadas.');
    }

    // ── Pedidos de nova empresa gerida ───────────────────────────────────────

    public function requests(Request $request)
    {
        $agency = $this->agency($request);
        $rows = ManagedCompanyRequest::where('agency_company_id', $agency->id)->orderByDesc('id')->limit(100)->get();

        return ApiResponse::success([
            'requests' => $rows->map(fn ($r) => ManagedCompanyRequestService::present($r))->values(),
            'can_request' => $this->isAgencyAdmin($request) && $request->user()->role !== 'root',
        ], 'Pedidos de nova empresa gerida.');
    }

    public function storeRequest(Request $request)
    {
        $agency = $this->agency($request);
        abort_unless($this->isAgencyAdmin($request) && $request->user()->role !== 'root', 403, 'Só o administrador da agência pede novas empresas geridas.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content_sector_id' => ['nullable', 'integer', Rule::exists('content_sectors', 'id')->where('is_selectable', true)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:2000'],
            'authorization_declared' => ['accepted'],
        ], ['authorization_declared.accepted' => 'Confirme que tem autorização do cliente para o gerir na XPLENDOR.']);

        return ApiResponse::success(ManagedCompanyRequestService::present($this->requests->request($agency, $request->user(), $data)), 'Pedido enviado à XPLENDOR.');
    }

    // ── Pedidos de gestão de uma empresa existente (F1d) ─────────────────────

    public function managementRequests(Request $request)
    {
        $agency = $this->agency($request);
        $rows = \App\Models\ManagementRequest::where('agency_company_id', $agency->id)->orderByDesc('id')->limit(100)->get();

        return ApiResponse::success([
            'requests' => $rows->map(fn ($r) => \App\Services\Agency\ManagementRequestService::presentForAgency($r))->values(),
            'can_request' => $this->isAgencyAdmin($request) && $request->user()->role !== 'root',
        ], 'Pedidos de gestão.');
    }

    // POST { nipc | email, message, authorization_declared }: a resposta é sempre a mesma.
    public function storeManagementRequest(Request $request, \App\Services\Agency\ManagementRequestService $service)
    {
        $agency = $this->agency($request);
        abort_unless($this->isAgencyAdmin($request) && $request->user()->role !== 'root', 403, 'Só o administrador da agência pede a gestão de uma empresa.');
        $data = $request->validate([
            'nipc' => ['nullable', 'required_without:email', 'regex:/^\D*(\d\D*){9}$/'],
            'email' => ['nullable', 'required_without:nipc', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'authorization_declared' => ['accepted'],
        ], [
            'nipc.required_without' => 'Indique o NIPC ou o email de um administrador da empresa.',
            'email.required_without' => 'Indique o NIPC ou o email de um administrador da empresa.',
            'nipc.regex' => 'O NIPC tem 9 dígitos.',
            'authorization_declared.accepted' => 'Confirme que tem autorização do cliente para o gerir na XPLENDOR.',
        ]);
        if (! empty($data['nipc']) && ! empty($data['email'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => ['Indique só o NIPC ou só o email.']]);
        }
        $r = $service->request($agency, $request->user(), $data);

        return ApiResponse::success(\App\Services\Agency\ManagementRequestService::presentForAgency($r), \App\Services\Agency\ManagementRequestService::NEUTRAL_REPLY);
    }

    public function withdrawManagementRequest(Request $request, int $agencyId, int $requestId, \App\Services\Agency\ManagementRequestService $service)
    {
        $agency = $this->agency($request);
        abort_unless($this->isAgencyAdmin($request), 403, 'Só o administrador da agência retira pedidos.');
        $r = \App\Models\ManagementRequest::where('agency_company_id', $agency->id)->findOrFail($requestId);

        return ApiResponse::success(\App\Services\Agency\ManagementRequestService::presentForAgency($service->withdraw($r)), 'Pedido retirado.');
    }

    // POST /agencies/{agency}/managed/{companyId}/end { reason }: a agência termina a relação com um cliente.
    public function endManagement(Request $request, int $agencyId, int $companyId)
    {
        $agency = $this->agency($request);
        abort_unless($this->isAgencyAdmin($request) && $request->user()->role !== 'root', 403, 'Só o administrador da agência termina a relação com um cliente.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.required' => 'Indique o motivo.']);
        $company = \App\Models\Company::findOrFail($companyId);
        $this->managements->endByAgency($agency, $company, $request->user(), $data['reason']);

        return ApiResponse::success(null, 'Relação terminada. A agência deixou de ter acesso a esta empresa; os dados ficam na empresa.');
    }

    private function isAgencyAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user->role === 'root' || ($user->role === 'admin' && (int) $user->company_id === $this->agency($request)->id);
    }

    private function assertAgencyAdmin(Request $request): void
    {
        abort_unless($this->isAgencyAdmin($request), 403, 'Só o administrador da agência gere as atribuições.');
    }
}
