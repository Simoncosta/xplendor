<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * DMS sub-fase 1c.1 — CRUD de Fornecedores (scoped por company).
 *
 * Guard de tenancy em 2 camadas (padrão StockPromotionController):
 *  1. o utilizador pertence à empresa da rota (ou é root);
 *  2. no show/update/destroy, o fornecedor pertence mesmo a essa empresa
 *     (impede admin da empresa A tocar em /companies/A/suppliers/{idDaB}).
 */
class SupplierController extends Controller
{
    public function __construct(protected SupplierService $supplierService) {}

    private const RELATIONS = ['district', 'municipality', 'parish'];

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    /** Fornecedor da empresa da rota, ou null se não existir / for de outra empresa. */
    private function findScoped(int $companyId, int $id): ?Supplier
    {
        return Supplier::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->find($id);
    }

    public function index(PaginateRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // withCount('expenses') alimenta o `can_delete` do Resource (1c.2b).
        $query = Supplier::query()
            ->with(self::RELATIONS)
            ->withCount('expenses')
            ->where('company_id', $companyId)
            ->orderBy('name');

        // Pesquisa por nome (útil para o react-select das despesas).
        if ($search = $request->input('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        // Selector de fornecedores para novas despesas pode pedir só os activos.
        if ($request->boolean('only_active')) {
            $query->where('archived', false);
        }

        if ($request->input('perPage')) {
            $suppliers = $query->paginate((int) $request->input('perPage'));
            $suppliers->through(fn (Supplier $s) => (new SupplierResource($s))->resolve());
            return ApiResponse::success($suppliers, 'Suppliers fetched successfully.');
        }

        return ApiResponse::success(
            SupplierResource::collection($query->get())->resolve(),
            'Suppliers fetched successfully.'
        );
    }

    public function store(SupplierRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $supplier = $this->supplierService->store($data);
        $supplier->load(self::RELATIONS);

        return ApiResponse::success(
            (new SupplierResource($supplier))->resolve(),
            'Supplier created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $supplier = $this->findScoped($companyId, $id);

        if (! $supplier) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        return ApiResponse::success(
            (new SupplierResource($supplier))->resolve(),
            'Supplier fetched successfully.'
        );
    }

    public function update(SupplierRequest $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $supplier = $this->findScoped($companyId, $id);

        if (! $supplier) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        $data = $request->validated();
        // company_id nunca vem do request — deriva da rota/tenant e é imutável.
        unset($data['company_id']);

        $updated = $this->supplierService->update($id, $data);
        $updated->load(self::RELATIONS);

        return ApiResponse::success(
            (new SupplierResource($updated))->resolve(),
            'Supplier updated successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $supplier = $this->findScoped($companyId, $id);

        if (! $supplier) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        // Regra (1c.2b activada): sem despesas → elimina; com despesas → bloqueia
        // (deve arquivar-se, para manter o histórico das despesas que o usam).
        $deleted = $this->supplierService->deleteOrBlock($supplier);

        if (! $deleted) {
            return ApiResponse::error(
                'Este fornecedor tem despesas associadas. Arquive-o em vez de o eliminar.',
                422
            );
        }

        return ApiResponse::success(null, 'Supplier deleted successfully.');
    }
}
