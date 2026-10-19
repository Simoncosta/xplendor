<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\ComputeMenuEngineeringJob;
use App\Models\MenuEngineeringExclusion;
use App\Models\MenuEngineeringItem;
use App\Models\MenuEngineeringSetting;
use App\Models\MenuEngineeringSnapshot;
use App\Models\PingwinLocation;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\Menu\MenuEngineeringService;
use App\Services\Restaurant\Menu\MenuMatrix;
use App\Services\Restaurant\RecipeReportService;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 3: Restauração › Engenharia de menu (só leitura do espelho; nada se escreve no
 * PingWin). O ecrã lê o snapshot calculado no ComputeMenuEngineeringJob; sem snapshot para o pedido,
 * põe o cálculo na fila e responde 202. Ver e recalcular: restauracao.ver; interruptores e exclusões à
 * mão: restauracao.editar. Os euros (*_eur) só para quem vê as Finanças.
 */
class CompanyMenuEngineeringController extends Controller
{
    /** A ordenação da tabela (ListSort): chave pública → coluna. */
    private const SORTS = [
        'description' => 'description', 'family' => 'family', 'qty' => 'qty', 'share' => 'share_pct', 'revenue' => 'revenue_cents',
        'price' => 'price', 'cost' => 'cost', 'margin' => 'margin', 'margin_total' => 'margin_total', 'food_cost' => 'food_cost_pct',
        'class' => 'class', 'confidence' => 'confidence', 'pareto' => 'pareto',
    ];

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$snap, $params] = $this->resolve($request, $companyId);
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);
        $base = [
            'locations' => PingwinLocation::where('company_id', $companyId)->withSales()->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->display_name ?: $l->winrest_name])->values(),
            'running' => Cache::get(ComputeMenuEngineeringJob::statusKey($companyId)),
            'settings' => MenuEngineeringSetting::for($companyId)->only(MenuEngineeringSetting::TOGGLES),
            'exclusions' => MenuEngineeringExclusion::where('company_id', $companyId)->orderBy('product_id')->get(['product_id', 'reason', 'created_at']),
            'can_manage' => $this->can($companyId, 'restauracao.editar'),
            'labels' => ['classes' => MenuMatrix::CLASS_LABELS, 'exclusions' => MenuEngineeringService::EXCLUSIONS, 'plays' => config('menu_engineering.plays')],
            'targets' => ['food_cost_pct' => (float) \App\Settings\Settings::company($companyId, 'menu.food_cost_target_pct'), 'confirmed_pct' => (float) config('menu_engineering.compare_min_coverage_pct', 80)],
            'request' => $params,
        ];
        if (! $snap) {
            $this->queue($companyId, $params);

            return ApiResponse::success($this->forUser($base + ['snapshot' => null], $finance), 'A calcular a engenharia de menu.', 202);
        }
        $family = $request->query('family');
        $points = MenuEngineeringItem::where('snapshot_id', $snap->id)->when($family, fn ($q) => $q->where('family', $family))
            ->orderBy('pareto')->get()->map(fn ($i) => $this->row($i, true))->values();

        return ApiResponse::success($this->forUser($base + [
            'snapshot' => ['id' => $snap->id, 'calculated_at' => $snap->calculated_at?->toIso8601String(), 'period_from' => $snap->period_from, 'period_to' => $snap->period_to,
                'location_id' => $snap->location_id, 'price_mode' => $snap->price_mode] + (array) $snap->data,
            'items' => $points,
        ], $finance), 'Engenharia de menu.');
    }

    /** A tabela: filtros, ordenação no servidor (ListSort), paginação ou CSV. */
    public function items(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$snap] = $this->resolve($request, $companyId);
        if (! $snap) {
            return ApiResponse::success(['items' => [], 'total' => 0], 'Ainda sem cálculo.', 202);
        }
        $f = $request->validate([
            'family' => ['nullable', 'string', 'max:120'], 'class' => ['nullable', Rule::in(array_merge(MenuMatrix::CLASSES, ['sem_custo']))],
            'confidence' => ['nullable', Rule::in(MenuEngineeringService::CONFIDENCE)], 'excluded' => ['nullable', Rule::in(['0', '1', 'all'])],
            'search' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:5', 'max:500'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);
        $q = MenuEngineeringItem::query()->where('snapshot_id', $snap->id)
            ->when($f['family'] ?? null, fn ($x, $v) => $x->where('family', $v))
            ->when(($f['class'] ?? null) === 'sem_custo', fn ($x) => $x->where('confidence', 'sem_custo'))
            ->when(in_array($f['class'] ?? null, MenuMatrix::CLASSES, true), fn ($x) => $x->where('class', $f['class']))
            ->when($f['confidence'] ?? null, fn ($x, $v) => $x->where('confidence', $v))
            ->when(($f['excluded'] ?? '0') === '0', fn ($x) => $x->whereNull('excluded'))
            ->when(($f['excluded'] ?? '0') === '1', fn ($x) => $x->whereNotNull('excluded'))
            ->when($f['search'] ?? null, fn ($x, $v) => $x->where(fn ($w) => $w->where('description', 'like', "%{$v}%")->orWhere('code', 'like', "{$v}%")));
        $sort = ListSort::apply($q, $request, self::SORTS, [['revenue', 'desc']]);
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);

        if (($f['format'] ?? 'json') === 'csv') {
            return $this->csv($q->get(), $finance, $snap);
        }
        $page = $q->paginate((int) ($f['perPage'] ?? 50), ['*'], 'page', (int) ($f['page'] ?? 1));

        return ApiResponse::success($this->forUser([
            'items' => collect($page->items())->map(fn ($i) => $this->row($i))->values(), 'total' => $page->total(), 'page' => $page->currentPage(),
            'perPage' => $page->perPage(), 'sort' => $sort,
        ], $finance), 'Artigos.');
    }

    /** O detalhe de um artigo: a ficha, o custo por ingrediente e de onde veio cada preço, a jogada. */
    public function show(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$snap] = $this->resolve($request, $companyId);
        $item = $snap ? MenuEngineeringItem::where('snapshot_id', $snap->id)->where('product_id', $productId)->first() : null;
        if (! $item) {
            return ApiResponse::error('Artigo não encontrado no cálculo.', 404);
        }
        $detail = (new MenuEngineeringService($companyId))->itemDetail($productId, (string) $snap->period_from, (string) $snap->period_to, $snap->location_id, $snap->price_mode);

        return ApiResponse::success($this->forUser(['item' => $this->row($item), 'recipe' => $detail],
            CompassFinancials::visibleTo($request->user(), $companyId)), 'Artigo.');
    }

    public function recalculate(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [, $params] = $this->resolve($request, $companyId, false);
        $this->queue($companyId, $params, true);

        return ApiResponse::success(['running' => Cache::get(ComputeMenuEngineeringJob::statusKey($companyId))], 'A recalcular.', 202);
    }

    public function updateSettings(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(array_fill_keys(MenuEngineeringSetting::TOGGLES, ['sometimes', 'boolean']));
        $s = MenuEngineeringSetting::for($companyId);
        $s->fill($data + ['updated_by' => $request->user()?->id])->save();
        ComputeMenuEngineeringJob::forDefaults($companyId);

        return ApiResponse::success(['settings' => $s->only(MenuEngineeringSetting::TOGGLES)], 'Guardado. A recalcular.', 202);
    }

    public function exclude(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['product_id' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'min:3', 'max:250']]);
        MenuEngineeringExclusion::updateOrCreate(['company_id' => $companyId, 'product_id' => $data['product_id']],
            ['reason' => $data['reason'], 'created_by' => $request->user()?->id]);
        ComputeMenuEngineeringJob::forDefaults($companyId);

        return ApiResponse::success(['product_id' => $data['product_id']], 'Artigo excluído. A recalcular.', 202);
    }

    public function include(int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        MenuEngineeringExclusion::where('company_id', $companyId)->where('product_id', $productId)->delete();
        ComputeMenuEngineeringJob::forDefaults($companyId);

        return ApiResponse::success(['product_id' => $productId], 'Artigo incluído. A recalcular.', 202);
    }

    /** O snapshot do pedido (loja, modo, período; sem período = o por omissão). */
    private function resolve(Request $request, int $companyId, bool $find = true): array
    {
        $d = $request->validate([
            'location_id' => ['nullable', 'integer'], 'mode' => ['nullable', Rule::in(['media', 'ultimo'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $loc = isset($d['location_id']) && PingwinLocation::where('company_id', $companyId)->whereKey($d['location_id'])->exists() ? (int) $d['location_id'] : null;
        $params = ['location_id' => $loc, 'mode' => $d['mode'] ?? 'media', 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null];
        if (! $find) {
            return [null, $params];
        }

        return [MenuEngineeringSnapshot::latestFor($companyId, $loc, $params['mode'], $params['from'], $params['to']), $params];
    }

    private function queue(int $companyId, array $p, bool $force = false): void
    {
        $running = Cache::get(ComputeMenuEngineeringJob::statusKey($companyId));
        if (! $force && in_array($running['status'] ?? null, ['queued', 'running'], true)) {
            return;
        }
        Cache::put(ComputeMenuEngineeringJob::statusKey($companyId), ['status' => 'queued', 'at' => now()->toIso8601String()], now()->addHour());
        if ($p['from'] || $p['to']) {
            ComputeMenuEngineeringJob::dispatch($companyId, $p['from'], $p['to'], [$p['location_id']], [$p['mode']]);
        } else {
            ComputeMenuEngineeringJob::dispatch($companyId);
        }
    }

    private function row(MenuEngineeringItem $i, bool $light = false): array
    {
        $d = (array) $i->detail;
        $row = [
            'product_id' => $i->product_id, 'code' => $i->code, 'description' => $i->description, 'family' => $i->family, 'qty' => $i->qty,
            'revenue_eur' => round($i->revenue_cents / 100, 2), 'vat_pct' => $d['vat_pct'] ?? null, 'price_eur' => $i->price, 'cost_eur' => $i->cost,
            'margin_eur' => $i->margin, 'margin_total_eur' => $i->margin_total, 'food_cost_pct' => $i->food_cost_pct, 'share_pct' => $i->share_pct,
            'revenue_share_pct' => $i->revenue_share_pct, 'confidence' => $i->confidence, 'class' => $i->class, 'popular' => $i->popular,
            'high_margin' => $i->high_margin, 'fragile' => $i->fragile, 'excluded' => $i->excluded, 'excluded_reason' => $d['excluded_reason'] ?? null,
            'pareto' => $i->pareto, 'play' => $d['play'] ?? null, 'changed' => (bool) ($d['changed'] ?? false),
            'missing' => $d['missing'] ?? [], 'fragile_reasons' => $d['fragile_reasons'] ?? [],
        ];
        if (! $light) {
            $row += ['versions' => $d['versions'] ?? [], 'warnings' => $d['warnings'] ?? [], 'stale' => $d['stale'] ?? [], 'reason' => $d['reason'] ?? null];
        }

        return $row;
    }

    private function csv($items, bool $finance, MenuEngineeringSnapshot $snap)
    {
        $labels = MenuMatrix::CLASS_LABELS;
        $conf = ['confirmado' => 'Confirmado', 'parcial' => 'Parcial', 'sem_custo' => 'Sem custo'];
        $num = fn ($v, $dec = 2) => $v === null ? '' : number_format((float) $v, $dec, ',', '');
        $money = fn ($v, $dec = 2) => $finance ? $num($v, $dec) : '';
        $rows = [['Artigo', 'Família', 'Quantidade', 'Quota na família (%)', 'Preço sem IVA (€)', 'Custo (€)', 'Margem unitária (€)', 'Margem total (€)',
            'Food cost (%)', 'Classe', 'Confiança', 'Excluído', 'Jogada']];
        foreach ($items as $i) {
            $d = (array) $i->detail;
            $rows[] = [trim(($i->code ? $i->code . ' ' : '') . $i->description), $i->family, $num($i->qty, 0), $num($i->share_pct, 1), $money($i->price), $money($i->cost),
                $money($i->margin), $money($i->margin_total), $finance ? $num($i->food_cost_pct, 1) : '', $labels[$i->class] ?? '', $conf[$i->confidence] ?? $i->confidence,
                $d['excluded_reason'] ?? '', $d['play'] ?? ''];
        }
        $name = sprintf('engenharia-de-menu-%s-%s.csv', $snap->period_from, $snap->period_to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
