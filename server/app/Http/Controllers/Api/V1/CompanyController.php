<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\PlanResource;
use App\Models\Company;
use App\Services\CompanyService;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\CompanyManagementService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
        protected UserService $userService,
        protected CompanyManagementService $managements,
    ) {}

    public function index(PaginateRequest $request)
    {
        $user = Auth::user();

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        // A própria empresa mais as que a agência dela gere (relação ativa); o root vê todas.
        $filter = $user->isRoot() ? [] : ['id' => [(int) $user->company_id, ...app(CompanyAccess::class)->managedCompanyIds($user)]];

        $companies = $this->companyService->getAll(
            ['*'],
            ['activeManagement.agency:id,fiscal_name,trade_name,agency_enabled_at,subscription_status,trial_ends_at'],
            $paginate,
            $filter
        );

        return ApiResponse::success($companies, 'Companies fetched successfully.');
    }

    public function store(CompanyRequest $request)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $user->isRoot()) {
            return ApiResponse::error('Acesso negado: utilziador não tem permissão para criar empresa.', 403);
        }

        $data = $request->validated();
        $data['public_api_token'] = Str::uuid()->toString();
        $agencyId = isset($data['managed_by_company_id']) ? (int) $data['managed_by_company_id'] : null;
        unset($data['managed_by_company_id']);

        // A empresa e a relação de gestão nascem juntas (ou nenhuma).
        $company = DB::transaction(function () use ($data, $agencyId, $user) {
            $company = $this->companyService->store($data);
            if ($agencyId !== null) {
                $this->managements->assign($company, $agencyId, $user);
            }

            return $company;
        });
        if (! empty($data['email_user'])) {
            $this->userService->store([
                'name' => $data['name_user'],
                'email' => $data['email_user'],
                'company_id' => $company->id,
                'fiscal_name' => $company->fiscal_name,
                'role' => 'admin',
            ]);
        }

        return ApiResponse::success($company, 'Company created successfully.');
    }

    public function show(int $id)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($id)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $company = $this->companyService->findOrFail($id, 'id');
        return ApiResponse::success($company, 'Company fetched successfully.');
    }

    /** O que a agência gestora pode alterar (empresas que criou, ainda sem admin do cliente). */
    private const AGENCY_BASIC_FIELDS = [
        'fiscal_name', 'trade_name', 'logo', 'content_sector_id',
        'phone', 'mobile', 'email', 'website', 'address', 'postal_code', 'district_id', 'municipality_id', 'parish_id',
    ];

    public function update(CompanyRequest $request, int $id)
    {
        $data = $request->validated();
        // O CompanyRequest só deixou passar a agência nos dados básicos; o resto não se grava.
        if (app(CompanyAccess::class)->viaAgency(Auth::user(), $id)) {
            $data = array_intersect_key($data, array_flip(self::AGENCY_BASIC_FIELDS));
        }
        // O ramo escolhe-se uma vez (como na Linha Editorial); a troca de ramo é uma fase futura.
        $current = Company::whereKey($id)->value('content_sector_id');
        if (! empty($data['content_sector_id']) && $current && (int) $current !== (int) $data['content_sector_id']) {
            throw ValidationException::withMessages(['content_sector_id' => ['Esta empresa já tem um ramo. A troca de ramo é uma fase futura.']]);
        }
        $company = $this->companyService->update($id, $data);
        return ApiResponse::success($company, 'Company updated successfully.');
    }

    public function destroy(int $id)
    {
        // Apagar uma empresa (e tudo o que dela depende) é só do root: plataforma.configurar, verificada na rota (ACL).

        $this->companyService->destroy($id);
        return ApiResponse::success(null, 'Company deleted successfully.');
    }
}
