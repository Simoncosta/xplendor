<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinWarehouse;
use App\Models\WasteBatch;
use App\Models\WasteEntry;
use App\Services\PingwinService;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeReportService;
use App\Services\Restaurant\StockConsumptionService;
use App\Services\Restaurant\StockReportService;
use App\Services\Restaurant\WasteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 7b: Restauração › Stock › Desperdício. Registar, editar e apagar (restauracao.ver)
 * enquanto não se envia; enviar e anular no PingWin (restauracao.editar, o gestor); o relatório.
 * Os euros (estimativa × PCM) só para quem vê as Finanças.
 */
class CompanyWasteController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['warehouse_id' => ['nullable', 'string', 'max:32'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now('Europe/Lisbon')->toDateString();
        $service = new WasteService($companyId);
        $warehouses = PingwinWarehouse::where('company_id', $companyId)->where('deleted', false)->orderBy('code')->get()
            ->map(fn ($w) => ['id' => (string) $w->pingwin_id, 'code' => $w->code, 'name' => $w->description])->values();
        $wid = $data['warehouse_id'] ?? null;
        $entries = WasteEntry::where('company_id', $companyId)->when($wid, fn ($q) => $q->where('warehouse_id', $wid))
            ->where(fn ($q) => $q->whereNull('batch_id')->orWhereBetween('registered_at', [$date . ' 00:00:00', $date . ' 23:59:59']))
            ->with(['registrant:id,name', 'batch:id,status,pingwin_doc_number'])->orderByDesc('registered_at')->limit(300)->get();
        $pending = $entries->whereNull('batch_id');
        $rows = $entries->map(fn ($e) => [
            'id' => $e->id, 'warehouse_id' => $e->warehouse_id, 'product_id' => $e->product_id, 'code' => $e->code, 'description' => $e->description,
            'is_dish' => (bool) $e->source_product_id, 'qty' => $e->qty, 'unit' => $e->unit, 'qty_base' => $e->qty_base, 'reason' => $e->reason,
            'reason_label' => WasteEntry::REASONS[$e->reason] ?? $e->reason, 'note' => $e->note, 'value_eur' => $e->value_estimate,
            'registered_by' => $e->registrant?->name, 'registered_at' => $e->registered_at?->toIso8601String(),
            'sent' => (bool) $e->batch_id, 'batch' => $e->batch ? ['id' => $e->batch->id, 'status' => $e->batch->status, 'document' => $e->batch->pingwin_doc_number] : null,
            'explosion' => $e->source_product_id && ! $e->batch_id ? $service->explode((string) $e->source_product_id, (float) $e->qty_base, (string) $e->warehouse_id, substr((string) $e->registered_at, 0, 10)) : null,
        ])->values();
        $batches = WasteBatch::where('company_id', $companyId)->when($wid, fn ($q) => $q->where('warehouse_id', $wid))->latest('id')->limit(20)->get()
            ->map(fn ($b) => ['id' => $b->id, 'warehouse_id' => $b->warehouse_id, 'date' => substr((string) $b->date, 0, 10), 'status' => $b->status,
                'document' => $b->pingwin_doc_number, 'total_eur' => $b->pingwin_total, 'lines' => count((array) $b->lines), 'error' => $b->error_message]);
        $guardCodes = $service->documentLines($pending->load('registrant:id,name'));

        return ApiResponse::success($this->forUser([
            'date' => $date, 'warehouses' => $warehouses, 'entries' => $rows, 'batches' => $batches,
            'reasons' => collect(WasteEntry::REASONS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values(),
            'pending' => ['entries' => $pending->count(), 'value_eur' => round((float) $pending->sum('value_estimate'), 2)],
            'send_guard' => WasteService::sendBlock() ?? ($guardCodes ? PingwinService::stockDocWriteGuard(array_map(fn ($l) => (string) $l['code'], $guardCodes)) : null),
            'store_warehouses' => array_values((new StockConsumptionService($companyId))->storeWarehouses()),
            'can_manage' => $this->can($companyId, 'restauracao.editar'),
        ], CompassFinancials::visibleTo($request->user(), $companyId)), 'Desperdício carregado.');
    }

    /** Artigos para registar: contáveis e pratos com ficha (pesquisa), com as unidades. */
    public function products(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $q = trim((string) $request->query('search', ''));
        if (mb_strlen($q) < 2) {
            return ApiResponse::success(['products' => []], 'Escreva pelo menos 2 letras.');
        }
        $service = new WasteService($companyId);
        $countable = PingwinCountableProduct::where('company_id', $companyId)->where(fn ($x) => $x->where('description', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%"))
            ->orderBy('description')->limit(15)->get()->map(fn ($p) => ['product_id' => (string) $p->product_id, 'code' => $p->code, 'description' => $p->description, 'is_dish' => false]);
        $withBom = (new StockConsumptionService($companyId))->productsWithBom();
        $dishes = PingwinCatalogItem::where('company_id', $companyId)->whereIn('pingwin_id', $withBom)->where('forsale', true)
            ->where(fn ($x) => $x->where('description', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%"))->orderBy('description')->limit(10)->get()
            ->map(fn ($p) => ['product_id' => (string) $p->pingwin_id, 'code' => $p->code, 'description' => $p->description, 'is_dish' => true]);
        $rows = $countable->concat($dishes)->map(fn ($p) => $p + ['units' => $service->units($p['product_id'], $p['is_dish'])['units']])->values();

        return ApiResponse::success(['products' => $rows], 'Artigos.');
    }

    public function store(Request $request, int $companyId)
    {
        return $this->save($request, $companyId, null);
    }

    public function update(Request $request, int $companyId, int $entryId)
    {
        $entry = WasteEntry::where('company_id', $companyId)->find($entryId);
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $entry) {
            return ApiResponse::error('Registo não encontrado.', 404);
        }

        return $this->save($request, $companyId, $entry);
    }

    private function save(Request $request, int $companyId, ?WasteEntry $entry)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'warehouse_id' => ['required', 'string', 'max:32'], 'product_id' => ['required', 'string', 'max:32'],
            'qty' => ['required'], 'unit' => ['nullable', 'string', 'max:60'],
            'reason' => ['required', Rule::in(array_keys(WasteEntry::REASONS))], 'note' => ['nullable', 'string', 'max:255'],
        ], ['reason.required' => 'Escolha um motivo.', 'qty.required' => 'Indique a quantidade.']);
        $saved = (new WasteService($companyId))->save($entry, $data, $request->user()?->id);

        return ApiResponse::success($this->forUser(['entry' => ['id' => $saved->id, 'qty_base' => $saved->qty_base, 'value_eur' => $saved->value_estimate]],
            CompassFinancials::visibleTo($request->user(), $companyId)), 'Registo gravado.', $entry ? 200 : 201);
    }

    public function destroy(Request $request, int $companyId, int $entryId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $entry = WasteEntry::where('company_id', $companyId)->find($entryId);
        if (! $entry) {
            return ApiResponse::error('Registo não encontrado.', 404);
        }
        if ($entry->batch_id) {
            return ApiResponse::error('Este registo já foi enviado para o PingWin: não se pode apagar.', 422);
        }
        $entry->delete();

        return ApiResponse::success([], 'Registo apagado.');
    }

    public function send(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['warehouse_id' => ['required', 'string', 'max:32']]);
        $write = (new WasteService($companyId))->send($data['warehouse_id'], $request->user()?->id);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A enviar para o PingWin.', 202);
    }

    public function void(Request $request, int $companyId, int $batchId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! WasteBatch::where('company_id', $companyId)->whereKey($batchId)->exists()) {
            return ApiResponse::error('Documento não encontrado.', 404);
        }
        $write = (new WasteService($companyId))->void('desperdicio', $batchId, $request->user()?->id);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A anular no PingWin.', 202);
    }

    public function report(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'warehouse_id' => ['nullable', 'string', 'max:32']]);
        $to = $data['to'] ?? now('Europe/Lisbon')->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(27)->toDateString();

        return ApiResponse::success($this->forUser(['report' => (new WasteService($companyId))->report($from, $to, $data['warehouse_id'] ?? null)],
            CompassFinancials::visibleTo($request->user(), $companyId)), 'Relatório do desperdício.');
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
