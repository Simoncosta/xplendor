<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Services\Tenancy\CompanyManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Gestão de empresas por agências, lado ADMIN (root): marcar a empresa como agência e
 * definir, mudar ou retirar a agência gestora. Vive no grupo /admin (ensure_super_admin).
 */
class CompanyManagementController extends Controller
{
    public function __construct(private readonly CompanyManagementService $service) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    /** Agências (para o campo "Gerida por"), com o número de empresas geridas ativas. */
    public function agencies()
    {
        $this->ensureRoot();
        $counts = CompanyManagement::active()->selectRaw('agency_company_id, count(*) as n')->groupBy('agency_company_id')->pluck('n', 'agency_company_id');

        $agencies = Company::whereNotNull('agency_enabled_at')->orderBy('fiscal_name')->get(['id', 'fiscal_name', 'trade_name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->trade_name ?: $c->fiscal_name, 'managed_count' => (int) ($counts[$c->id] ?? 0)]);

        return ApiResponse::success(['agencies' => $agencies], 'Agências carregadas.');
    }

    public function show(int $companyId)
    {
        $this->ensureRoot();
        $company = Company::findOrFail($companyId);

        return ApiResponse::success($this->payload($company), 'Gestão carregada.');
    }

    /** Marca ou desmarca "Esta empresa é uma agência". */
    public function setAgency(Request $request, int $companyId)
    {
        $this->ensureRoot();
        $company = Company::findOrFail($companyId);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'notification_email' => ['nullable', 'email', 'max:255'],
        ]);
        $this->service->setAgency($company, (bool) $data['enabled'], $data['notification_email'] ?? null);

        return ApiResponse::success($this->payload($company->fresh()), $data['enabled'] ? 'Empresa marcada como agência.' : 'A empresa deixou de ser agência.');
    }

    /** Define, muda ou retira (null) a agência gestora. */
    public function assign(Request $request, int $companyId)
    {
        $this->ensureRoot();
        $company = Company::findOrFail($companyId);
        $data = $request->validate([
            'agency_company_id' => ['present', 'nullable', 'integer'],
            'team_scope' => ['sometimes', Rule::in([CompanyManagement::SCOPE_ALL, CompanyManagement::SCOPE_ASSIGNED])],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer'],
        ]);
        $m = $this->service->assign($company, $data['agency_company_id'] !== null ? (int) $data['agency_company_id'] : null, $request->user(),
            $data['team_scope'] ?? CompanyManagement::SCOPE_ALL, $data['member_ids'] ?? []);

        return ApiResponse::success($this->payload($company->fresh()), $m ? 'Agência gestora definida.' : 'Gestão retirada.');
    }

    private function payload(Company $company): array
    {
        $current = $company->activeManagement()->first();

        return [
            'company' => ['id' => $company->id, 'name' => $company->trade_name ?: $company->fiscal_name],
            'is_agency' => $company->isAgency(),
            'agency_notification_email' => $company->agency_notification_email,
            'agency_members' => $company->isAgency() ? $company->users()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name', 'role']) : [],
            'current' => $current ? $this->service->present($current) : null,
            'history' => $this->service->history($company),
        ];
    }
}
