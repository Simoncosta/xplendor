<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinWarehouse;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RealConsumptionService;
use App\Services\Restaurant\RecipeReportService;
use App\Services\Restaurant\StockCountService;
use App\Services\Restaurant\StockMovementsService;
use App\Services\Restaurant\StockReportService;
use App\Services\Restaurant\StockUnits;
use App\Services\Restaurant\WasteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 7b: Restauração › Stock › Contagens. Contar (restauracao.ver; cega por omissão: quem
 * só conta não vê o teórico), rever, pedir recontagem, aprovar e enviar, e anular no PingWin
 * (restauracao.editar, o gestor). Também os Movimentos de um artigo e o consumo real × teórico.
 * Tenancy primeiro; os euros só para quem vê as Finanças.
 */
class CompanyStockCountController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $warehouses = PingwinWarehouse::where('company_id', $companyId)->where('deleted', false)->orderBy('code')->get(['pingwin_id', 'code', 'description'])
            ->map(fn ($w) => ['id' => (string) $w->pingwin_id, 'code' => $w->code, 'name' => $w->description])->values();
        $counts = StockCount::where('company_id', $companyId)->with('creator:id,name')->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('qty_base')])
            ->latest('id')->limit(60)->get()->map(fn ($c) => self::summary($c))->values();

        return ApiResponse::success([
            'counts' => $counts, 'warehouses' => $warehouses,
            'zones' => \App\Models\StockCountZone::where('company_id', $companyId)->orderBy('position')->get(['id', 'warehouse_id', 'name']),
            'countable_refreshed_at' => PingwinCountableProduct::where('company_id', $companyId)->max('refreshed_at'),
            'can_manage' => $this->can($companyId, 'restauracao.editar'),
            'dates' => ['antes_abertura' => StockCountService::inventoryDate('antes_abertura'), 'depois_fecho' => StockCountService::inventoryDate('depois_fecho')],
        ], 'Contagens carregadas.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'warehouse_id' => ['required', 'string', 'max:32'],
            'scope' => ['required', Rule::in(StockCount::SCOPES)],
            'zone_id' => ['nullable', 'integer'],
            'moment' => ['required', Rule::in(StockCount::MOMENTS)],
            'blind' => ['sometimes', 'boolean'],
        ]);
        // Cega por omissão: só o gestor a desliga.
        $blind = $this->can($companyId, 'restauracao.editar') ? (bool) ($data['blind'] ?? true) : true;
        $count = (new StockCountService($companyId))->create($data['warehouse_id'], $data['scope'], $data['zone_id'] ?? null, $data['moment'], $blind, $request->user()?->id);

        return ApiResponse::success(['count' => self::summary($count->loadCount(['lines']))], 'Contagem criada.', 201);
    }

    public function show(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        if (! $count) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        $manager = $this->can($companyId, 'restauracao.editar');
        $showTheory = $manager && ! $count->blind;
        $units = new StockUnits($companyId);
        $zones = \App\Models\StockCountZone::where('company_id', $companyId)->where('warehouse_id', $count->warehouse_id)->orderBy('position')->get(['id', 'name', 'position']);
        $lines = $count->lines()->orderByRaw('CASE WHEN zone_id IS NULL THEN 1 ELSE 0 END')->orderBy('position')->orderBy('description')->get()->map(fn ($l) => [
            'id' => $l->id, 'product_id' => $l->product_id, 'code' => $l->code, 'description' => $l->description, 'abc' => $l->abc_class, 'zone_id' => $l->zone_id,
            'unit' => $l->stock_unit, 'units' => $units->for((string) $l->product_id)['units'], 'entries' => $l->entries ?? [], 'qty_base' => $l->qty_base,
            'counted_at' => $l->counted_at?->toIso8601String(), 'recount_requested' => $l->recount_requested,
            'theoretical' => $showTheory ? $l->theoretical_snapshot : null, 'confirm_status' => $l->confirm_status, 'confirmed_qty' => $l->confirmed_qty,
        ])->values();
        $payload = ['count' => self::summary($count->loadCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('qty_base')])),
            'lines' => $lines, 'zones' => $zones, 'can_manage' => $manager, 'shows_theoretical' => $showTheory];
        if ($manager && in_array($count->status, ['em_revisao', 'a_enviar', 'enviada', 'falhou', 'anulada'], true)) {
            $payload['review'] = (new StockCountService($companyId))->review($count);
        }

        return ApiResponse::success($this->forUser($payload, CompassFinancials::visibleTo($request->user(), $companyId)), 'Contagem carregada.');
    }

    /** Grava o que se contou numa linha (gravação automática por linha). */
    public function saveLine(Request $request, int $companyId, int $countId, int $lineId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        $line = $count ? StockCountLine::where('count_id', $count->id)->find($lineId) : null;
        if (! $line) {
            return ApiResponse::error('Linha não encontrada.', 404);
        }
        $data = $request->validate(['entries' => ['present', 'array', 'max:10'], 'entries.*.qty' => ['nullable'], 'entries.*.unit' => ['nullable', 'string', 'max:60']]);
        $line = (new StockCountService($companyId))->saveLine($count, $line, $data['entries'], $request->user()?->id);

        return ApiResponse::success(['line' => ['id' => $line->id, 'entries' => $line->entries ?? [], 'qty_base' => $line->qty_base, 'counted_at' => $line->counted_at?->toIso8601String()]], 'Gravado.');
    }

    public function finish(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        if (! $count) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        (new StockCountService($companyId))->finish($count);

        return ApiResponse::success(['count' => self::summary($count->fresh())], 'Contagem terminada: passa para revisão.');
    }

    public function recount(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        if (! $count) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        $data = $request->validate(['line_ids' => ['required', 'array', 'min:1'], 'line_ids.*' => ['integer']]);
        $n = (new StockCountService($companyId))->requestRecount($count, $data['line_ids']);

        return ApiResponse::success(['recount' => $n, 'count' => self::summary($count->fresh())], 'Recontagem pedida.');
    }

    public function send(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        if (! $count) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        $write = (new StockCountService($companyId))->approveAndSend($count, $request->user()?->id);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A enviar para o PingWin.', 202);
    }

    public function void(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! StockCount::where('company_id', $companyId)->whereKey($countId)->exists()) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        $write = (new WasteService($companyId))->void('contagem', $countId, $request->user()?->id);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A anular no PingWin.', 202);
    }

    /** Desistir de uma contagem que ainda não foi enviada (fica anulada só na XPLENDOR). */
    public function destroy(Request $request, int $companyId, int $countId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $count = StockCount::where('company_id', $companyId)->find($countId);
        if (! $count) {
            return ApiResponse::error('Contagem não encontrada.', 404);
        }
        if (! in_array($count->status, ['em_contagem', 'em_revisao', 'falhou'], true)) {
            return ApiResponse::error('Esta contagem já foi enviada: anule-a no PingWin.', 422);
        }
        $count->update(['status' => 'anulada', 'voided_at' => now()]);

        return ApiResponse::success(['count' => self::summary($count)], 'Contagem anulada na XPLENDOR.');
    }

    /** Os artigos contáveis (pesquisa) e a releitura da lista no PingWin. */
    public function countable(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $q = trim((string) $request->query('search', ''));
        $rows = PingwinCountableProduct::where('company_id', $companyId)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('description', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%")))
            ->orderBy('description')->limit(30)->get(['product_id', 'code', 'description', 'family']);

        return ApiResponse::success(['products' => $rows], 'Artigos contáveis.');
    }

    /** Relê a lista no PingWin (no worker). */
    public function refreshCountable(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        \App\Jobs\RefreshCountableProductsJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A ler os artigos contáveis no PingWin. Atualize a página dentro de momentos.', 202);
    }

    /** Os Movimentos de um artigo (relatório do PingWin, cache de 10 min). */
    public function movements(Request $request, int $companyId, string $productId, StockMovementsService $movements)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'warehouse_id' => ['nullable', 'string', 'max:32']]);
        $to = $data['to'] ?? now('Europe/Lisbon')->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            return ApiResponse::error('Escolha um intervalo de até um ano.', 422);
        }
        $code = StockMovementsService::codeOf($companyId, $productId);
        if ($code === '') {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        // O pedido não fala com o PingWin: lê-se no worker e o ecrã pergunta até estar pronto.
        $res = $movements->cachedOrQueue($companyId, $from, $to, $code, $code);
        if (! $res['ready']) {
            return ApiResponse::success(['from' => $from, 'to' => $to, 'pending' => true, 'movements' => null], 'A ler os movimentos no PingWin.', 202);
        }
        if ($res['error']) {
            return ApiResponse::error('Não foi possível ler os movimentos no PingWin. Tente de novo dentro de momentos.', 502);
        }

        return ApiResponse::success(['from' => $from, 'to' => $to, 'pending' => false,
            'movements' => StockMovementsService::onlyProduct($res['rows'], $code, $data['warehouse_id'] ?? null)], 'Movimentos carregados.');
    }

    /** Consumo real × teórico de um armazém (com duas contagens enviadas e confirmadas). */
    public function consumption(Request $request, int $companyId, StockMovementsService $movements)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['warehouse_id' => ['required', 'string', 'max:32']]);
        $service = new RealConsumptionService($companyId, $movements);
        $range = $service->movementRange($data['warehouse_id']);
        if ($range !== null) {
            $res = $movements->cachedOrQueue($companyId, ...$range);
            if (! $res['ready']) {
                return ApiResponse::success(['pending' => true, 'consumption' => null], 'A ler os movimentos no PingWin.', 202);
            }
            if ($res['error']) {
                return ApiResponse::error('Não foi possível ler os movimentos no PingWin. Tente de novo dentro de momentos.', 502);
            }
        }

        return ApiResponse::success($this->forUser(['pending' => false, 'consumption' => $service->compute($data['warehouse_id'])],
            CompassFinancials::visibleTo($request->user(), $companyId)), 'Consumo real × teórico.');
    }

    public static function summary(StockCount $c): array
    {
        return [
            'id' => $c->id, 'warehouse_id' => $c->warehouse_id, 'warehouse' => PingwinWarehouse::where('company_id', $c->company_id)->where('pingwin_id', $c->warehouse_id)->value('description'),
            'scope' => $c->scope, 'zone_id' => $c->zone_id, 'moment' => $c->moment, 'inventory_date' => $c->dateString(), 'blind' => $c->blind, 'status' => $c->status,
            'lines' => $c->lines_count ?? null, 'counted' => $c->counted_count ?? null, 'created_by' => $c->creator?->name, 'created_at' => $c->created_at?->toIso8601String(),
            'pingwin_doc_number' => $c->pingwin_doc_number, 'error_message' => $c->error_message, 'confirmed_at' => $c->confirmed_at?->toIso8601String(),
            'send_block' => in_array($c->status, ['em_revisao'], true) ? (new StockCountService((int) $c->company_id))->sendBlock($c) : null,
        ];
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return self::redactEuros($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }

    /** Apaga os campos *_eur (como as fichas) e os custos unitários. */
    private static function redactEuros(array $data): array
    {
        return RecipeReportService::redact($data);
    }
}
