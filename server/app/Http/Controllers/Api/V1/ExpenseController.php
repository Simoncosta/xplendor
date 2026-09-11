<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DMS sub-fase 1c.2b — CRUD de Despesas (scoped por company) + filtros + totais.
 *
 * Guard de tenancy em 2 camadas (padrão Supplier/ExpenseCategory).
 */
class ExpenseController extends Controller
{
    public function __construct(protected ExpenseService $service) {}

    private const RELATIONS = ['category', 'supplier', 'car.brand', 'car.model'];

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?Expense
    {
        return Expense::with(self::RELATIONS)->where('company_id', $companyId)->find($id);
    }

    /** Aplica os filtros partilhados por index() e summary(). */
    private function applyFilters(Builder $query, Request $request, int $companyId): Builder
    {
        $query->where('company_id', $companyId);

        if ($request->filled('expense_category_id')) {
            $query->where('expense_category_id', (int) $request->input('expense_category_id'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('car_id')) {
            $query->where('car_id', (int) $request->input('car_id'));
        }
        if ($request->has('is_paid') && $request->input('is_paid') !== '') {
            $query->where('is_paid', $request->boolean('is_paid'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }
        // Arquivadas: por defeito escondidas; include_archived=1 mostra-as (histórico).
        if (! $request->boolean('include_archived')) {
            $query->where('archived', false);
        }

        return $query;
    }

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $query = $this->applyFilters(Expense::query()->with(self::RELATIONS), $request, $companyId)
            ->orderByDesc('date')
            ->orderByDesc('id');

        $perPage = (int) $request->input('perPage', 15);
        $expenses = $query->paginate($perPage);
        $expenses->through(fn (Expense $e) => (new ExpenseResource($e))->resolve());

        return ApiResponse::success($expenses, 'Expenses fetched successfully.');
    }

    /** Totais sobre os MESMOS filtros (todos os registos, não só a página). */
    public function summary(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $base = $this->applyFilters(Expense::query(), $request, $companyId);

        $total = (float) (clone $base)->sum('amount');
        $paid  = (float) (clone $base)->where('is_paid', true)->sum('amount');
        $open  = (float) (clone $base)->where('is_paid', false)->sum('amount');
        $count = (int) (clone $base)->count();

        return ApiResponse::success([
            'total_amount' => $total,
            'paid_amount'  => $paid,
            'open_amount'  => $open,
            'count'        => $count,
        ], 'Expense summary fetched successfully.');
    }

    public function store(ExpenseRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $this->service->normalizePaidState($request->validated());
        $data['company_id'] = $companyId;

        $expense = $this->service->store($data);
        $expense->load(self::RELATIONS);

        return ApiResponse::success(
            (new ExpenseResource($expense))->resolve(),
            'Expense created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $expense = $this->findScoped($companyId, $id);

        if (! $expense) {
            return ApiResponse::error('Despesa não encontrada.', 404);
        }

        return ApiResponse::success(
            (new ExpenseResource($expense))->resolve(),
            'Expense fetched successfully.'
        );
    }

    public function update(ExpenseRequest $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $expense = $this->findScoped($companyId, $id);

        if (! $expense) {
            return ApiResponse::error('Despesa não encontrada.', 404);
        }

        $data = $this->service->normalizePaidState($request->validated());
        unset($data['company_id']);

        $updated = $this->service->update($id, $data);
        $updated->load(self::RELATIONS);

        return ApiResponse::success(
            (new ExpenseResource($updated))->resolve(),
            'Expense updated successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $expense = $this->findScoped($companyId, $id);

        if (! $expense) {
            return ApiResponse::error('Despesa não encontrada.', 404);
        }

        // Regra: sem vínculo → elimina; com vínculo → bloqueia (deve arquivar-se).
        $deleted = $this->service->deleteOrBlock($expense);

        if (! $deleted) {
            return ApiResponse::error(
                'Esta despesa está vinculada (viatura/fornecedor/categoria). Arquive-a em vez de a eliminar.',
                422
            );
        }

        return ApiResponse::success(null, 'Expense deleted successfully.');
    }
}
