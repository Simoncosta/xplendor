<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\SyncTransferOrdersJob;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinStockWrite;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRoute;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeReportService;
use App\Services\Restaurant\StockReportService;
use App\Services\Restaurant\StockUnits;
use App\Services\Restaurant\Transfers\TransferBlocked;
use App\Services\Restaurant\Transfers\TransferService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 7c: Restauração › Transferências. Ver pedidos, envios e o circuito (restauracao.ver);
 * enviar (restauracao.editar, o gestor do Economato); receber no restaurante (restauracao.criar);
 * anular (restauracao.apagar); configurar o circuito (restauracao.configurar). As escritas no PingWin
 * passam pelas guardas (STOCK_TRANSFER_SEND_PROVEN e PINGWIN_STOCKDOC_WRITE_ALLOWLIST): 403 com o porquê.
 */
class CompanyStockTransferController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $service = new TransferService($companyId);
        $routes = $service->routes();
        $names = $service->warehouseNames();
        $active = StockTransfer::where('company_id', $companyId)->whereNotIn('status', ['anulado', 'falhou_envio'])->whereNotNull('request_id')->pluck('request_id')->all();
        $since = CarbonImmutable::today()->subDays((int) config('stock_transfers.orders_days', 60))->toDateString();
        $requests = StockTransferRequest::where('company_id', $companyId)->where('doc_date', '>=', $since)->with('lines')->orderByDesc('doc_date')->orderByDesc('id')->get();
        $pending = $requests->filter(fn ($r) => $r->kind === 'encomenda' && ! $r->converted && $r->docstatus_id === '8002' && ! in_array($r->id, $active, true));
        $legacy = $requests->filter(fn ($r) => $r->kind === 'requisicao')->take(20);
        $transfers = StockTransfer::where('company_id', $companyId)->with(['lines', 'request:id,document', 'creator:id,name'])->latest('id')->limit(60)->get();

        return ApiResponse::success($this->forUser([
            'routes' => $routes->map(fn ($r) => $this->route($r, $names))->values(),
            'pending' => $pending->map(fn ($r) => $this->request($r, $routes))->values(),
            'legacy' => $legacy->map(fn ($r) => $this->request($r, $routes) + ['label' => 'Pedido antigo (Requisição interna)'])->values(),
            'transfers' => $transfers->map(fn ($t) => $this->transfer($t, $names))->values(),
            'orders_sync' => Cache::get(SyncTransferOrdersJob::cacheKey($companyId)),
            'last_orders_sync' => StockTransferRequest::where('company_id', $companyId)->max('synced_at'),
            'flags' => ['send_proven' => (bool) config('stock_transfers.send_proven'), 'new_guide_proven' => (bool) config('stock_transfers.new_guide_proven')],
            'can' => [
                'send' => $this->can($companyId, 'restauracao.editar'), 'receive' => $this->can($companyId, 'restauracao.criar'),
                'void' => $this->can($companyId, 'restauracao.apagar'), 'configure' => $this->can($companyId, 'restauracao.configurar'),
            ],
        ], CompassFinancials::visibleTo($request->user(), $companyId)), 'Transferências carregadas.');
    }

    /** Relê as encomendas no worker (SÓ LEITURA). O ecrã acompanha pelo index (orders_sync). */
    public function refreshOrders(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        Cache::put(SyncTransferOrdersJob::cacheKey($companyId), ['status' => 'queued'], now()->addHour());
        SyncTransferOrdersJob::dispatch($companyId);

        return ApiResponse::success(['orders_sync' => ['status' => 'queued']], 'A ler as encomendas do PingWin.', 202);
    }

    /** Artigos para um envio sem encomenda (os contáveis), com as unidades. */
    public function products(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $q = trim((string) $request->query('search', ''));
        if (mb_strlen($q) < 2) {
            return ApiResponse::success(['products' => []], 'Escreva pelo menos 2 letras.');
        }
        $units = new StockUnits($companyId);
        $rows = PingwinCountableProduct::where('company_id', $companyId)->where(fn ($x) => $x->where('description', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%"))
            ->orderBy('description')->limit(20)->get()
            ->map(fn ($p) => ['product_id' => (string) $p->product_id, 'code' => $p->code, 'description' => $p->description] + ['units' => $units->for((string) $p->product_id)['units']]);

        return ApiResponse::success(['products' => $rows->values()], 'Artigos.');
    }

    /** As contas e os bloqueios de um envio (SÓ LEITURA). */
    public function prepare(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$route, $req, $lines] = $this->input($request, $companyId);
        $service = new TransferService($companyId);
        $prep = $service->prepare($route, $lines);
        $prep['send_block'] = $service->sendBlock($route, array_map(fn ($l) => (string) $l['code'], $prep['lines']), $req !== null);
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);
        if (! $finance) {
            foreach ($prep['lines'] as &$l) {
                if ($l['confirm']) {
                    $l['confirm']['text'] = 'O preço deste artigo precisa de confirmação de quem vê as Finanças.';
                }
            }
            unset($l);
        }

        return ApiResponse::success($this->forUser(['prepare' => $prep], $finance), 'Envio preparado.');
    }

    /** ⚠️ Envia (UM envio por ação): Guia no Economato e, se o circuito recebe sozinho, Entrada no restaurante. */
    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$route, $req, $lines] = $this->input($request, $companyId);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:250']])['note'] ?? null;
        try {
            $t = (new TransferService($companyId))->send($route, $req, $lines, (int) $request->user()?->id, $note);
        } catch (TransferBlocked $e) {
            return $this->blocked($e);
        }

        return ApiResponse::success(['transfer' => ['id' => $t->id, 'status' => $t->status]], 'A enviar para o PingWin.', 202);
    }

    public function show(Request $request, int $companyId, int $transferId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $t = StockTransfer::where('company_id', $companyId)->with(['lines', 'request:id,document,doc_date,store_name,employee_name', 'creator:id,name'])->find($transferId);
        if (! $t) {
            return ApiResponse::error('Envio não encontrado.', 404);
        }
        $service = new TransferService($companyId);
        $writes = PingwinStockWrite::where('company_id', $companyId)->whereIn('kind', ['guia_saida', 'entrada_armazem', 'anular_documento'])
            ->where('payload->transfer_id', $t->id)->orderBy('id')->get()->map(fn ($w) => ['kind' => $w->kind] + StockReportService::writeSummary($w));

        return ApiResponse::success($this->forUser(['transfer' => $this->transfer($t, $service->warehouseNames(), true), 'writes' => $writes->values()],
            CompassFinancials::visibleTo($request->user(), $companyId)), 'Envio.');
    }

    /** Receção no restaurante (circuito sem receção automática) ou "Repetir só a entrada". */
    public function receive(Request $request, int $companyId, int $transferId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $t = StockTransfer::where('company_id', $companyId)->with('lines')->find($transferId);
        if (! $t) {
            return ApiResponse::error('Envio não encontrado.', 404);
        }
        $data = $request->validate(['received' => ['nullable', 'array'], 'received.*' => ['nullable', 'numeric', 'min:0']]);
        try {
            $w = (new TransferService($companyId))->receive($t, (int) $request->user()?->id, (array) ($data['received'] ?? []));
        } catch (TransferBlocked $e) {
            return $this->blocked($e);
        }

        return ApiResponse::success(['write' => StockReportService::writeSummary($w)], 'A gravar a entrada no PingWin.', 202);
    }

    public function void(Request $request, int $companyId, int $transferId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $t = StockTransfer::where('company_id', $companyId)->with('lines')->find($transferId);
        if (! $t) {
            return ApiResponse::error('Envio não encontrado.', 404);
        }
        try {
            $w = (new TransferService($companyId))->void($t, (int) $request->user()?->id);
        } catch (TransferBlocked $e) {
            return $this->blocked($e);
        }

        return ApiResponse::success(['write' => StockReportService::writeSummary($w)], 'A anular no PingWin.', 202);
    }

    /** O circuito: nome, documento de saída, receção automática, moradas de carga e descarga, ligado. */
    public function updateRoute(Request $request, int $companyId, int $routeId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $route = StockTransferRoute::where('company_id', $companyId)->find($routeId);
        if (! $route) {
            return ApiResponse::error('Circuito não encontrado.', 404);
        }
        $addr = fn (string $k) => ["{$k}" => ['nullable', 'array'], "{$k}.address" => ['nullable', 'string', 'max:120'], "{$k}.postalcode" => ['nullable', 'string', 'max:20'],
            "{$k}.postalcode_description" => ['nullable', 'string', 'max:80'], "{$k}.city" => ['nullable', 'string', 'max:80']];
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'], 'auto_receive' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'],
            'exit_docconfig_id' => ['sometimes', Rule::in(array_map('strval', array_keys((array) config('stock_transfers.exit_docconfigs'))))],
        ] + $addr('load_address') + $addr('unload_address'));
        foreach (['load_address', 'unload_address'] as $k) {
            if (array_key_exists($k, $data) && is_array($data[$k])) {
                $data[$k] = array_map(fn ($v) => trim((string) $v), array_intersect_key($data[$k], array_flip(['address', 'postalcode', 'postalcode_description', 'city'])));
            }
        }
        $route->update($data);

        return ApiResponse::success(['route' => $this->route($route->fresh(), (new TransferService($companyId))->warehouseNames())], 'Circuito guardado.');
    }

    /** @return array{0: StockTransferRoute, 1: ?StockTransferRequest, 2: list<array<string, mixed>>} */
    private function input(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'route_id' => ['required', 'integer'], 'request_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.product_id' => ['required', 'string', 'max:32'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'], 'lines.*.unit' => ['nullable', 'string', 'max:80'],
            'lines.*.request_line_id' => ['nullable', 'integer'], 'lines.*.price_confirmed' => ['nullable', 'boolean'], 'lines.*.factor_ack' => ['nullable', 'boolean'],
        ]);
        $route = StockTransferRoute::where('company_id', $companyId)->findOrFail($data['route_id']);
        $req = isset($data['request_id']) ? StockTransferRequest::where('company_id', $companyId)->findOrFail($data['request_id']) : null;
        if ($req) {
            $own = $req->lines()->pluck('id')->all();
            foreach ($data['lines'] as $l) {
                if (isset($l['request_line_id']) && ! in_array((int) $l['request_line_id'], $own, true)) {
                    abort(422, 'Linha de outra encomenda.');
                }
            }
        }

        return [$route, $req, array_values($data['lines'])];
    }

    private function blocked(TransferBlocked $e)
    {
        return ApiResponse::error($e->getMessage(), 403);
    }

    private function route(StockTransferRoute $r, array $names): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'origin_warehouse_id' => $r->origin_warehouse_id, 'origin_name' => $names[$r->origin_warehouse_id] ?? $r->origin_warehouse_id,
            'dest_warehouse_id' => $r->dest_warehouse_id, 'dest_name' => $names[$r->dest_warehouse_id] ?? $r->dest_warehouse_id,
            'origin_store_id' => $r->origin_store_id, 'dest_store_id' => $r->dest_store_id, 'exit_docconfig_id' => $r->exit_docconfig_id,
            'exit_doc_label' => config('stock_transfers.exit_docconfigs')[$r->exit_docconfig_id] ?? $r->exit_docconfig_id,
            'auto_receive' => $r->auto_receive, 'active' => $r->active, 'load_address' => $r->load_address, 'unload_address' => $r->unload_address,
            'addresses_ok' => StockTransferRoute::addressComplete($r->load_address) && StockTransferRoute::addressComplete($r->unload_address),
        ];
    }

    private function request(StockTransferRequest $r, $routes): array
    {
        return [
            'id' => $r->id, 'pingwin_doc_id' => $r->pingwin_doc_id, 'kind' => $r->kind, 'document' => $r->document,
            'doctype' => config('stock_transfers.order_docconfigs')[$r->docconfig_id] ?? (config('stock_transfers.legacy_docconfigs')[$r->docconfig_id] ?? null),
            'store_id' => $r->store_id, 'store_name' => $r->store_name, 'doc_date' => $r->doc_date, 'employee_name' => $r->employee_name,
            'age_days' => $r->doc_date ? (int) CarbonImmutable::parse($r->doc_date)->diffInDays(CarbonImmutable::today()) : null,
            'route_id' => $r->route_id ?? $routes->firstWhere('dest_store_id', $r->store_id)?->id, 'lines_status' => $r->lines_status,
            'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'product_id' => $l->product_id, 'code' => $l->code, 'description' => $l->description,
                'qnt' => $l->qnt, 'unit_desc' => $l->unit_desc, 'unit_value' => $l->unit_value, 'qnt_base' => $l->qnt_base, 'qnt_delivered' => $l->qnt_delivered])->values(),
        ];
    }

    private function transfer(StockTransfer $t, array $names, bool $full = false): array
    {
        return [
            'id' => $t->id, 'status' => $t->status, 'method' => $t->method, 'route_id' => $t->route_id, 'auto_receive' => $t->auto_receive,
            'origin_warehouse_id' => $t->origin_warehouse_id, 'origin_name' => $names[$t->origin_warehouse_id] ?? $t->origin_warehouse_id,
            'dest_warehouse_id' => $t->dest_warehouse_id, 'dest_name' => $names[$t->dest_warehouse_id] ?? $t->dest_warehouse_id,
            'request' => $t->request ? ['id' => $t->request->id, 'document' => $t->request->document] + ($full ? ['doc_date' => $t->request->doc_date,
                'store_name' => $t->request->store_name, 'employee_name' => $t->request->employee_name] : []) : null,
            'exit_doc_number' => $t->exit_doc_number, 'entry_doc_number' => $t->entry_doc_number, 'total_value_eur' => $t->total_value,
            'error' => $t->error_message, 'created_by' => $t->creator?->name, 'created_at' => $t->created_at?->toIso8601String(),
            'sent_at' => $t->sent_at?->toIso8601String(), 'received_at' => $t->received_at?->toIso8601String(), 'voided_at' => $t->voided_at?->toIso8601String(),
            'lines_count' => $t->lines->count(),
            'lines' => $full ? $t->lines->map(fn ($l) => ['id' => $l->id, 'product_id' => $l->product_id, 'code' => $l->code, 'description' => $l->description,
                'qty' => $l->qnt, 'unit' => $l->unit_desc, 'factor' => $l->factor, 'qty_base' => $l->qnt_base, 'received_qty_base' => $l->received_qnt_base,
                'origin_pcm_eur' => $l->origin_pcm, 'price_per_unit_eur' => $l->price, 'sent_price_eur' => $l->sent_price, 'received_price_eur' => $l->received_price,
                'value_eur' => $l->origin_pcm !== null ? round((float) $l->qnt_base * (float) $l->origin_pcm, 2) : null])->values() : null,
        ];
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
