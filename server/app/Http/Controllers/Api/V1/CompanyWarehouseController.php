<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinStock;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\RestaurantStoreWarehouse;
use App\Services\PingwinService;
use App\Services\Restaurant\StockConsumptionService;
use App\Services\Restaurant\StockReportService;
use App\Services\Restaurant\StockWriteService;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Fatia 7a: Restauração › Armazéns. A lista (do espelho), criar, editar (nome, lojas
 * servidas, valorização) e apagar (só sem stock, com dupla confirmação) no PingWin, uma escrita de cada
 * vez e com releitura; e o armazém por omissão de cada loja (só na XPLENDOR, para o consumo teórico).
 */
class CompanyWarehouseController extends Controller
{
    public function index(Request $request, int $companyId, StockWriteService $writes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $withStock = PingwinStock::where('company_id', $companyId)->where('qnt', '!=', 0)
            ->selectRaw('warehouse_pingwin_id, COUNT(*) as n')->groupBy('warehouse_pingwin_id')->pluck('n', 'warehouse_pingwin_id');
        $lastEntry = PingwinStock::where('company_id', $companyId)->selectRaw('warehouse_pingwin_id, MAX(valorization_date) as d')
            ->groupBy('warehouse_pingwin_id')->pluck('d', 'warehouse_pingwin_id');
        $consumption = new StockConsumptionService($companyId);
        $stores = array_values($consumption->storeWarehouses());

        $rows = PingwinWarehouse::where('company_id', $companyId)->orderBy('deleted')->orderBy('code')->get()->map(function ($w) use ($withStock, $lastEntry, $stores) {
            $id = (string) $w->pingwin_id;

            return [
                'id' => $id, 'code' => $w->code, 'description' => $w->description, 'deleted' => (bool) $w->deleted,
                'inv_costmethod' => $w->inv_costmethod, 'exit_costmethod' => $w->exit_costmethod,
                'inv_costmethod_label' => PingwinWarehouse::COSTMETHODS[$w->inv_costmethod] ?? null,
                'exit_costmethod_label' => PingwinWarehouse::COSTMETHODS[$w->exit_costmethod] ?? null,
                'stores' => array_values((array) $w->stores),
                'default_for' => array_values(array_map(fn ($s) => $s['store_name'], array_filter($stores, fn ($s) => $s['warehouse_id'] === $id))),
                'articles_with_stock' => (int) ($withStock[$id] ?? 0),
                'last_valorization' => isset($lastEntry[$id]) ? substr((string) $lastEntry[$id], 0, 10) : null,
                'synced_at' => $w->synced_at?->toIso8601String(),
                'write_guard' => PingwinService::warehouseWriteGuard($w->description),
            ];
        })->values()->all();
        $recent = PingwinStockWrite::where('company_id', $companyId)->where('kind', 'like', 'armazem_%')->with('user:id,name')
            ->latest('id')->limit(5)->get()->map(fn ($w) => StockReportService::writeSummary($w))->all();

        return ApiResponse::success([
            'warehouses' => $rows,
            'stores' => $stores,
            'known_stores' => collect($writes->knownStores($companyId))->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name])->values(),
            'costmethods' => collect(PingwinWarehouse::COSTMETHODS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'create_guard' => PingwinService::warehouseWriteGuard(null),
            'allowed_names' => PingwinService::warehouseWriteAllowlist(),
            'writes' => $recent,
            'can_edit' => $this->can($companyId, 'restauracao.editar'),
        ], 'Armazéns carregados.');
    }

    /** ⚠️ Criar um armazém no PingWin (em fila; relê no fim). */
    public function store(Request $request, int $companyId, StockWriteService $writes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'description' => ['required', 'string', 'max:50'],
            'store_ids' => ['sometimes', 'nullable', 'array'],
            'store_ids.*' => ['string', 'max:32'],
            'inv_costmethod' => ['sometimes', 'nullable', 'integer'],
            'exit_costmethod' => ['sometimes', 'nullable', 'integer'],
        ], ['description.required' => 'O nome do armazém é obrigatório.', 'description.max' => 'O nome tem no máximo 50 caracteres.']);
        if ($guard = PingwinService::warehouseWriteGuard(trim($data['description']))) {
            return ApiResponse::error($guard['reason'], 403);
        }
        $write = $writes->requestCreate($companyId, $request->user()?->id, $data);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A criar o armazém no PingWin.', 202);
    }

    /** ⚠️ Editar um armazém no PingWin (em fila; relê no fim). */
    public function update(Request $request, int $companyId, string $warehouseId, StockWriteService $writes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:50'],
            'store_ids' => ['sometimes', 'nullable', 'array'],
            'store_ids.*' => ['string', 'max:32'],
            'inv_costmethod' => ['sometimes', 'nullable', 'integer'],
            'exit_costmethod' => ['sometimes', 'nullable', 'integer'],
        ], ['description.max' => 'O nome tem no máximo 50 caracteres.']);
        if ($guard = $this->guardFor($companyId, $warehouseId, $data['description'] ?? null)) {
            return ApiResponse::error($guard['reason'], 403);
        }
        $write = $writes->requestUpdate($companyId, $request->user()?->id, $warehouseId, $data);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A gravar o armazém no PingWin.', 202);
    }

    /** ⚠️ Apagar um armazém no PingWin: só sem stock e com o nome escrito outra vez. */
    public function destroy(Request $request, int $companyId, string $warehouseId, StockWriteService $writes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:50']],
            ['confirm_name.required' => 'Para confirmar, escreva o nome do armazém.']);
        if ($guard = $this->guardFor($companyId, $warehouseId, null)) {
            return ApiResponse::error($guard['reason'], 403);
        }
        $write = $writes->requestDelete($companyId, $request->user()?->id, $warehouseId, $data['confirm_name']);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A apagar o armazém no PingWin.', 202);
    }

    /** A guarda do dev para um armazém existente (pelo nome que tem e, se mudar, pelo novo). */
    private function guardFor(int $companyId, string $warehouseId, ?string $newName): ?array
    {
        $current = PingwinWarehouse::where('company_id', $companyId)->where('pingwin_id', $warehouseId)->value('description');
        if ($current === null) {
            return null; // o serviço responde "não encontrado"
        }

        return PingwinService::warehouseWriteGuard($current) ?? ($newName !== null ? PingwinService::warehouseWriteGuard(trim($newName)) : null);
    }

    /** O armazém por omissão de uma loja (só na XPLENDOR; null = voltar ao que o PingWin diz). */
    public function storeDefault(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'store_id' => ['required', 'string', 'max:32'],
            'warehouse_id' => ['present', 'nullable', 'string', 'max:32'],
        ]);
        $consumption = new StockConsumptionService($companyId);
        if (! array_key_exists($data['store_id'], $consumption->storeWarehouses())) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }
        if ($data['warehouse_id'] === null) {
            RestaurantStoreWarehouse::where('company_id', $companyId)->where('store_pingwin_id', $data['store_id'])->delete();
        } else {
            if (! PingwinWarehouse::where('company_id', $companyId)->where('pingwin_id', $data['warehouse_id'])->where('deleted', false)->exists()) {
                return ApiResponse::error('Armazém não encontrado.', 404);
            }
            RestaurantStoreWarehouse::updateOrCreate(['company_id' => $companyId, 'store_pingwin_id' => $data['store_id']],
                ['warehouse_pingwin_id' => $data['warehouse_id'], 'set_by' => $request->user()?->id]);
        }

        return ApiResponse::success(['stores' => array_values((new StockConsumptionService($companyId))->storeWarehouses())], 'Armazém da loja guardado.');
    }
}
