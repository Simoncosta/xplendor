<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\PlanResource;
use App\Services\CompanyService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
        protected UserService $userService
    ) {}

    public function index(PaginateRequest $request)
    {
        $user = Auth::user();

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $filter = $user->role === 'root' ? [] : ['company_id' => $user->company_id];

        $companies = $this->companyService->getAll(
            ['*'],
            [],
            $paginate,
            $filter
        );

        return ApiResponse::success($companies, 'Companies fetched successfully.');
    }

    public function store(CompanyRequest $request)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilziador não tem permissão para criar empresa.', 403);
        }

        $data = $request->validated();
        $data['public_api_token'] = Str::uuid()->toString();

        $company = $this->companyService->store($data);
        $user = $this->userService->store([
            'name' => $data['name_user'],
            'email' => $data['email_user'],
            'company_id' => $company->id,
            'fiscal_name' => $company->fiscal_name,
            'role' => 'admin',
        ]);

        return ApiResponse::success($company, 'Company created successfully.');
    }

    public function show(int $id)
    {
        $company = $this->companyService->findOrFail($id, 'id');
        return ApiResponse::success($company, 'Company fetched successfully.');
    }

    public function update(CompanyRequest $request, int $id)
    {
        $company = $this->companyService->update($id, $request->validated());
        return ApiResponse::success($company, 'Company updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->companyService->destroy($id);
        return ApiResponse::success(null, 'Company deleted successfully.');
    }
}
