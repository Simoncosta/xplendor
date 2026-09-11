<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\ExpenseCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DMS sub-fase 1c.2a — CRUD de Categorias de Despesa (scoped por company).
 *
 * Guard de tenancy em 2 camadas (padrão Supplier/StockPromotion):
 *  1. o utilizador pertence à empresa da rota (ou é root);
 *  2. no show/update/destroy, a categoria pertence mesmo a essa empresa.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(protected ExpenseCategoryService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?ExpenseCategory
    {
        return ExpenseCategory::where('company_id', $companyId)->find($id);
    }

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $query = ExpenseCategory::query()
            ->withCount('expenses') // alimenta can_delete (1c.2b)
            ->where('company_id', $companyId);

        // Selector de novas despesas (1c.2b) pode pedir só as activas.
        if ($request->boolean('only_active')) {
            $query->where('archived', false);
        }

        $categories = $query->orderBy('name')->get();

        return ApiResponse::success(
            ExpenseCategoryResource::collection($categories)->resolve(),
            'Expense categories fetched successfully.'
        );
    }

    public function store(ExpenseCategoryRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;
        $data['archived'] = $data['archived'] ?? false;

        $category = $this->service->store($data);

        return ApiResponse::success(
            (new ExpenseCategoryResource($category))->resolve(),
            'Expense category created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $category = $this->findScoped($companyId, $id);

        if (! $category) {
            return ApiResponse::error('Categoria não encontrada.', 404);
        }

        return ApiResponse::success(
            (new ExpenseCategoryResource($category))->resolve(),
            'Expense category fetched successfully.'
        );
    }

    public function update(ExpenseCategoryRequest $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $category = $this->findScoped($companyId, $id);

        if (! $category) {
            return ApiResponse::error('Categoria não encontrada.', 404);
        }

        $data = $request->validated();
        unset($data['company_id']); // deriva da rota/tenant — imutável

        $updated = $this->service->update($id, $data);

        return ApiResponse::success(
            (new ExpenseCategoryResource($updated))->resolve(),
            'Expense category updated successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $category = $this->findScoped($companyId, $id);

        if (! $category) {
            return ApiResponse::error('Categoria não encontrada.', 404);
        }

        // Regra: sem despesas → elimina; com despesas → bloqueia (deve arquivar-se).
        $deleted = $this->service->deleteOrBlock($id);

        if (! $deleted) {
            return ApiResponse::error(
                'Esta categoria tem despesas associadas. Arquive-a em vez de a eliminar.',
                422
            );
        }

        return ApiResponse::success(null, 'Expense category deleted successfully.');
    }

    /**
     * Lista das categorias sugeridas (para pré-visualizar ANTES de importar).
     * Fonte única de verdade — o frontend não duplica os nomes.
     */
    public function suggested(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(
            ['suggested' => ExpenseCategoryService::SUGGESTED],
            'Suggested expense categories fetched successfully.'
        );
    }

    /**
     * Importa as 14 categorias sugeridas em falta (idempotente).
     */
    public function importSuggested(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $created = $this->service->importSuggested($companyId);

        return ApiResponse::success(
            [
                'created_count' => count($created),
                'created'       => ExpenseCategoryResource::collection(collect($created))->resolve(),
            ],
            'Suggested expense categories imported successfully.'
        );
    }
}
