<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DMS — CRUD de Clientes (scoped por company). Molde do SupplierController.
 * Guard de tenancy em 2 camadas (empresa da rota + o cliente pertencer a ela).
 */
class CustomerController extends Controller
{
    private const RELATIONS = ['district', 'municipality', 'parish'];

    public function __construct(protected CustomerService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?Customer
    {
        return Customer::with(self::RELATIONS)->where('company_id', $companyId)->find($id);
    }

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $query = Customer::query()
            ->with(self::RELATIONS)
            ->withCount('sales')
            ->where('company_id', $companyId)
            ->orderBy('name');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        // Selector de novas vendas pode pedir só os activos.
        if ($request->boolean('only_active')) {
            $query->where('archived', false);
        }

        if ($request->input('perPage')) {
            $customers = $query->paginate((int) $request->input('perPage'));
            $customers->through(fn (Customer $c) => (new CustomerResource($c))->resolve());
            return ApiResponse::success($customers, 'Customers fetched successfully.');
        }

        return ApiResponse::success(
            CustomerResource::collection($query->get())->resolve(),
            'Customers fetched successfully.'
        );
    }

    public function store(CustomerRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $customer = $this->service->store($data);
        $customer->load(self::RELATIONS);

        return ApiResponse::success(
            (new CustomerResource($customer))->resolve(),
            'Customer created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $customer = $this->findScoped($companyId, $id);

        if (! $customer) {
            return ApiResponse::error('Cliente não encontrado.', 404);
        }

        return ApiResponse::success(
            (new CustomerResource($customer))->resolve(),
            'Customer fetched successfully.'
        );
    }

    public function update(CustomerRequest $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $customer = $this->findScoped($companyId, $id);

        if (! $customer) {
            return ApiResponse::error('Cliente não encontrado.', 404);
        }

        $data = $request->validated();
        unset($data['company_id']); // deriva da rota/tenant — imutável

        $updated = $this->service->update($id, $data);
        $updated->load(self::RELATIONS);

        return ApiResponse::success(
            (new CustomerResource($updated))->resolve(),
            'Customer updated successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $customer = $this->findScoped($companyId, $id);

        if (! $customer) {
            return ApiResponse::error('Cliente não encontrado.', 404);
        }

        // Regra: sem vendas → elimina; com vendas → bloqueia (deve arquivar-se).
        $deleted = $this->service->deleteOrBlock($customer);

        if (! $deleted) {
            return ApiResponse::error(
                'Este cliente tem vendas associadas. Arquive-o em vez de o eliminar.',
                422
            );
        }

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }
}
