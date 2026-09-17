<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\CarSale;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\LeadMatchService;
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

    /**
     * Fase 3 — FICHA-HUB: tudo o que se liga ao cliente, agregado e scoped à
     * empresa. Vendas (por customer_id), Leads (por match de CONTACTO, reutilizando
     * o LeadMatchService da Fase 2), Documentos (não são guardados → vazio) e um
     * Histórico DERIVADO das vendas + leads (não há entidade de atividades).
     */
    public function hub(int $companyId, int $id, LeadMatchService $leadMatch)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $customer = Customer::where('company_id', $companyId)->find($id);
        if (! $customer) {
            return ApiResponse::error('Cliente não encontrado.', 404);
        }

        // VENDAS — ligação direta por customer_id.
        $sales = CarSale::with(['car:id,version,car_brand_id,car_model_id', 'car.brand:id,name', 'car.model:id,name'])
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->orderByDesc('sold_at')
            ->get()
            ->map(function (CarSale $s) {
                $car = $s->car;
                $carName = $car ? trim(($car->brand->name ?? '') . ' ' . ($car->model->name ?? '') . ' ' . ($car->version ?? '')) : null;
                return [
                    'id' => $s->id,
                    'car_id' => $s->car_id,
                    'car' => $carName ?: '—',
                    'sold_at' => optional($s->sold_at)->toIso8601String(),
                    'sale_price' => $s->sale_price !== null ? (float) $s->sale_price : null,
                    'advertised_price' => $s->advertised_price !== null ? (float) $s->advertised_price : null,
                    'discount_amount' => $s->discount_amount !== null ? (float) $s->discount_amount : null,
                    'has_trade_in' => $s->has_trade_in,
                    'trade_in_value' => $s->trade_in_value !== null ? (float) $s->trade_in_value : null,
                    'has_financing' => $s->has_financing,
                    'financed_amount' => $s->financed_amount !== null ? (float) $s->financed_amount : null,
                    'first_motorhome' => $s->first_motorhome,
                ];
            });

        // LEADS — match de CONTACTO (best-effort), TODAS as fases (não só abertas).
        $leads = $leadMatch->byContact($companyId, $customer->email, $customer->phone, onlyOpen: false)
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'status' => $l->status,
                'channel' => $l->channel,
                'utm_source' => $l->utm_source,
                'utm_campaign' => $l->utm_campaign,
                'car_id' => $l->car_id,
                'created_at' => optional($l->created_at)->toIso8601String(),
            ]);

        // HISTÓRICO — derivado (não há entidade de atividades). Vendas + leads.
        $history = collect();
        foreach ($sales as $s) {
            $history->push(['type' => 'sale', 'date' => $s['sold_at'], 'title' => 'Venda — ' . $s['car'], 'amount' => $s['sale_price']]);
        }
        foreach ($leads as $l) {
            $history->push(['type' => 'lead', 'date' => $l['created_at'], 'title' => 'Lead' . ($l['channel'] ? ' (' . $l['channel'] . ')' : ''), 'status' => $l['status']]);
        }
        $history = $history->filter(fn ($h) => $h['date'])->sortByDesc('date')->values();

        return ApiResponse::success([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'nif' => $customer->nif,
            ],
            'sales' => $sales,
            'leads' => $leads,
            // Documentos não são guardados (gerados ad-hoc do modelo+venda) → vazio.
            'documents' => [],
            'history' => $history,
        ], 'Customer hub fetched successfully.');
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
