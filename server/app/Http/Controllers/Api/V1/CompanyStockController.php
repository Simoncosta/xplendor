<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\SyncPingwinStockJob;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinStockRun;
use App\Models\PingwinStockWrite;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeReportService;
use App\Services\Restaurant\StockReportService;
use App\Services\Restaurant\StockSyncService;
use App\Services\Restaurant\StockWriteService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 7a: Restauração › Stock. A lista por Pareto do valor (só o espelho), o stock de um
 * artigo por armazém, a escrita do mínimo/máximo e do "Não usa genéricos" (no PingWin, com releitura)
 * e a releitura de um artigo. Tenancy primeiro; os euros só para quem vê as Finanças.
 */
class CompanyStockController extends Controller
{
    private const SORTS = [
        'pareto' => 'pareto', 'description' => 'description', 'family' => 'family', 'qnt' => 'qnt', 'per_day' => 'per_day',
        'coverage' => 'coverage_days', 'valorization' => 'valorization_date', 'min' => 'min_stock', 'max' => 'max_stock',
    ];
    private const EURO_SORTS = ['value' => 'value_eur', 'pcm' => 'pcm_eur', 'pcu' => 'pcu_eur'];

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);
        $sorts = $finance ? self::SORTS + self::EURO_SORTS : self::SORTS;
        $data = Validator::make($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'warehouse_id' => ['nullable', 'string', 'max:32'],
            'family' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', Rule::in(StockReportService::STATES)],
            'sort' => ['nullable', 'string', Rule::in(array_keys($sorts))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ], ['sort.in' => 'Ordenação inválida: use ' . implode(', ', array_keys($sorts)) . '.'])->validate();

        $report = new StockReportService($companyId);
        $wid = ($data['warehouse_id'] ?? '') ?: null;
        if ($wid !== null && ! in_array($wid, array_column($report->warehouses(), 'id'), true)) {
            return ApiResponse::error('Armazém não encontrado.', 404);
        }
        $list = $report->list(['warehouse_id' => $wid, 'family' => $data['family'] ?? null, 'state' => $data['state'] ?? null]);
        $rows = $list['rows'];
        if ($s = trim((string) ($data['search'] ?? ''))) {
            $needle = mb_strtolower($s);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower((string) $r['description']), $needle)
                || str_contains(mb_strtolower((string) $r['code']), $needle)));
        }
        $sort = $data['sort'] ?? 'pareto';
        $dir = $data['dir'] ?? 'asc';
        $field = $sorts[$sort];
        usort($rows, function ($a, $b) use ($field, $dir) {
            $va = $a[$field];
            $vb = $b[$field];
            if ($va === null || $vb === null) {
                return ($va === null) <=> ($vb === null) ?: $a['pareto'] <=> $b['pareto'];
            }
            $c = is_string($va) ? strcoll(mb_strtolower($va), mb_strtolower((string) $vb)) : $va <=> $vb;

            return ($dir === 'desc' ? -$c : $c) ?: $a['pareto'] <=> $b['pareto'];
        });
        $perPage = (int) ($data['perPage'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $rows = array_map(function ($r) {
            unset($r['_value']);

            return $r;
        }, $rows);
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);

        $payload = [
            'stock' => $paginator->toArray(),
            'summary' => $list['summary'],
            'families' => $list['families'],
            'warehouses' => $list['warehouses'],
            'warehouse_id' => $wid,
            'states' => collect(StockReportService::STATE_LABELS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'sort' => ['sort' => $sort, 'dir' => $dir],
        ];

        return ApiResponse::success($this->forUser($payload, $finance), 'Stock carregado.');
    }

    public function show(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $detail = (new StockReportService($companyId))->article($productId);
        if ($detail === null) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $detail['can_edit'] = $this->can($companyId, 'restauracao.editar');

        return ApiResponse::success($this->forUser($detail, CompassFinancials::visibleTo($request->user(), $companyId)), 'Stock do artigo carregado.');
    }

    /** ⚠️ Mínimo, máximo e "Não usa genéricos" de um artigo num armazém (escrita no PingWin, em fila). */
    public function limits(Request $request, int $companyId, string $productId, StockWriteService $writes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->exists()) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $data = $request->validate([
            'warehouse_id' => ['required', 'string', 'max:32'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_stock' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'disable_generic' => ['sometimes', 'boolean'],
        ], [
            'min_stock.min' => 'O mínimo não pode ser negativo.',
            'max_stock.min' => 'O máximo não pode ser negativo.',
        ]);
        if ($guard = \App\Services\PingwinService::stockWriteGuard($productId, $companyId)) {
            return ApiResponse::error($guard['reason'], 403);
        }
        $changes = array_intersect_key($data, array_flip(['min_stock', 'max_stock', 'disable_generic']));
        $write = $writes->requestLimits($companyId, $request->user()?->id, $productId, $data['warehouse_id'], $changes);

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'A gravar no PingWin.', 202);
    }

    /** O estado de uma escrita (para o ecrã acompanhar). */
    public function writeStatus(Request $request, int $companyId, int $writeId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $write = PingwinStockWrite::where('company_id', $companyId)->with('user:id,name')->find($writeId);
        if (! $write) {
            return ApiResponse::error('Gravação não encontrada.', 404);
        }

        return ApiResponse::success(['write' => StockReportService::writeSummary($write)], 'Estado da gravação.');
    }

    /** Volta a ler do PingWin o stock de UM artigo (só leitura, em fila). */
    public function refresh(Request $request, int $companyId, string $productId, StockSyncService $sync)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->exists()) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $running = PingwinStockRun::where('company_id', $companyId)->where('status', 'running')->where('created_at', '>=', now()->subMinutes(30))->exists();
        if ($running) {
            return ApiResponse::error('Já há uma leitura do stock a correr: aguarde que termine.', 409);
        }
        $run = $sync->startRun($companyId, 'artigo', 1);
        SyncPingwinStockJob::dispatch($companyId, $run->id, [$productId], false);

        return ApiResponse::success(['run' => self::runSummary($run)], 'A ler o stock no PingWin.', 202);
    }

    /** O estado de uma leitura. */
    public function runStatus(Request $request, int $companyId, int $runId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $run = PingwinStockRun::where('company_id', $companyId)->find($runId);
        if (! $run) {
            return ApiResponse::error('Leitura não encontrada.', 404);
        }

        return ApiResponse::success(['run' => self::runSummary($run)], 'Estado da leitura.');
    }

    private static function runSummary(PingwinStockRun $run): array
    {
        return ['id' => $run->id, 'status' => $run->status, 'origin' => $run->origin, 'products_read' => $run->products_read,
            'products_failed' => $run->products_failed, 'finished_at' => $run->finished_at?->toIso8601String()];
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
