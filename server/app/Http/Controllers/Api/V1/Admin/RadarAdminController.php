<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\CompanyModuleEvent;
use App\Models\RadarItem;
use App\Models\RadarRelevance;
use App\Models\RadarSignal;
use App\Models\RadarRun;
use App\Models\RadarTopic;
use App\Services\Radar\Filter\Lexicon;
use App\Services\Radar\RadarCollector;
use App\Services\Radar\Sources\RadarSources;
use Carbon\CarbonInterface;
use App\Services\CompanyModuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * /admin/radar: o painel do root do Radar de oportunidade (R1). Tudo o que entrou dos feeds
 * públicos, o que o filtro de segurança tirou e porquê, a última recolha de cada fonte e as
 * tendências recentes. Só o root (grupo /admin, ensure_super_admin). Só título, ligação, fonte,
 * hora e volume: nenhum dado pessoal.
 */
class RadarAdminController extends Controller
{
    public function __construct(private readonly RadarSources $sources, private readonly RadarCollector $collector) {}

    // GET /admin/radar
    public function summary()
    {
        $day = now()->subDay();
        $month = now()->subDays((int) config('radar.retention_days', 30));

        $sources = [];
        foreach ($this->sources->all() as $key => $source) {
            $config = $source->config();
            $last = RadarRun::where('source_key', $key)->where('from_fixture', false)->orderByDesc('started_at')->orderByDesc('id')->first();
            $lastOk = RadarRun::where('source_key', $key)->where('status', 'ok')->where('dry_run', false)->where('from_fixture', false)->max('started_at');
            $sources[] = [
                'key' => $key,
                'label' => $source->label(),
                'url' => $source->url(),
                'type' => $source->type(),
                'enabled' => $source->enabled(),
                'disabled_reason' => $config['disabled_reason'] ?? null,
                'interval_minutes' => $source->intervalMinutes(),
                'attribution' => $source->attribution(),
                'terms_url' => $config['terms_url'] ?? null,
                'terms_checked_at' => $config['terms_checked_at'] ?? null,
                'terms_note' => $config['terms_note'] ?? null,
                'requests_last_hour' => $this->collector->requestsLastHour($key),
                'last_run' => $last ? [
                    'started_at' => self::iso($last->started_at),
                    'status' => $last->status,
                    'dry_run' => $last->dry_run,
                    'http_status' => $last->http_status,
                    'items_seen' => $last->items_seen,
                    'items_new' => $last->items_new,
                    'items_blocked' => $last->items_blocked,
                    'error' => $last->error,
                ] : null,
                'last_ok_at' => $lastOk ? self::iso(\Carbon\CarbonImmutable::parse($lastOk)) : null,
                'items_24h' => RadarItem::where('source_key', $key)->where('fetched_at', '>=', $day)->count(),
            ];
        }

        $reasons = array_fill_keys(Lexicon::REASONS, 0);
        $flags = array_fill_keys(Lexicon::FLAGS, 0);
        RadarItem::where('fetched_at', '>=', $month)->select(['id', 'filter_reasons', 'filter_flags'])->chunkById(1000, function ($items) use (&$reasons, &$flags) {
            foreach ($items as $item) {
                foreach ((array) $item->filter_reasons as $r) {
                    $reasons[$r] = ($reasons[$r] ?? 0) + 1;
                }
                foreach ((array) $item->filter_flags as $f) {
                    $flags[$f] = ($flags[$f] ?? 0) + 1;
                }
            }
        });

        $topics = RadarTopic::orderByDesc('last_seen_at')->orderByDesc('id')->limit(20)->withCount('items')->get()->map(fn (RadarTopic $t) => [
            'id' => $t->id,
            'kind' => $t->kind,
            'title' => $t->title,
            'first_seen_at' => self::iso($t->first_seen_at),
            'last_seen_at' => self::iso($t->last_seen_at),
            'window_until' => self::iso($t->window_until),
            'open' => $t->window_until?->isFuture() ?? false,
            'approx_volume' => $t->approx_volume,
            'approx_volume_label' => $t->approx_volume_label,
            'districts' => $t->districts ?? [],
            'verdict' => $t->filter_verdict,
            'reasons' => $t->filter_reasons ?? [],
            'flags' => $t->filter_flags ?? [],
            'items_count' => (int) $t->items_count,
            'source_names' => array_values(array_unique(array_filter(array_map(fn ($s) => $s['source_name'] ?? null, (array) $t->sources)))),
        ])->all();

        return ApiResponse::success([
            'radar_enabled' => RadarCollector::enabled(),
            'sources' => $sources,
            'totals' => [
                'items_24h' => RadarItem::where('fetched_at', '>=', $day)->count(),
                'blocked_24h' => RadarItem::where('fetched_at', '>=', $day)->where('filter_verdict', RadarItem::VERDICT_BLOCKED)->count(),
                'items_30d' => RadarItem::where('fetched_at', '>=', $month)->count(),
                'blocked_30d' => RadarItem::where('fetched_at', '>=', $month)->where('filter_verdict', RadarItem::VERDICT_BLOCKED)->count(),
                'topics_open' => RadarTopic::where('window_until', '>', now())->count(),
                'topics_open_passed' => RadarTopic::where('window_until', '>', now())->where('filter_verdict', RadarItem::VERDICT_PASSED)->count(),
            ],
            'blocked_by_reason' => collect($reasons)->map(fn ($n, $r) => ['reason' => $r, 'label' => Lexicon::LABELS[$r] ?? $r, 'count' => $n])->values()->all(),
            'flags' => collect($flags)->map(fn ($n, $f) => ['flag' => $f, 'label' => Lexicon::LABELS[$f] ?? $f, 'count' => $n])->values()->all(),
            'topics' => $topics,
            'relevance' => $this->relevanceBySector(),
            'signals' => $this->signalsByCompany(),
            'companies' => $this->companies(),
            'labels' => Lexicon::LABELS,
            'limits' => [
                'max_requests_per_hour' => (int) config('radar.max_requests_per_hour', 4),
                'retention_days' => (int) config('radar.retention_days', 30),
            ],
        ], 'Radar: resumo.');
    }

    // GET /admin/radar/items?verdict=&source=&days=&page=&per_page=
    public function items(Request $request)
    {
        $data = $request->validate([
            'verdict' => ['nullable', Rule::in([RadarItem::VERDICT_PASSED, RadarItem::VERDICT_BLOCKED])],
            'source' => ['nullable', Rule::in(array_keys((array) config('radar.sources', [])))],
            'days' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('radar.retention_days', 30)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $types = array_map(fn ($s) => $s['type'] ?? null, (array) config('radar.sources', []));

        $page = RadarItem::query()
            ->with('topic:id,kind,title,filter_verdict')
            ->where('fetched_at', '>=', now()->subDays((int) ($data['days'] ?? 1)))
            ->when($data['verdict'] ?? null, fn ($q, $v) => $q->where('filter_verdict', $v))
            ->when($data['source'] ?? null, fn ($q, $s) => $q->where('source_key', $s))
            ->orderByDesc('fetched_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn (RadarItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'url' => $i->url,
                'source_key' => $i->source_key,
                'source_type' => $types[$i->source_key] ?? null,
                'source_name' => $i->source_name,
                'published_at' => self::iso($i->published_at),
                'fetched_at' => self::iso($i->fetched_at),
                'approx_volume' => $i->approx_volume,
                'approx_volume_label' => $i->approx_volume_label,
                'verdict' => $i->filter_verdict,
                'reasons' => $i->filter_reasons ?? [],
                'flags' => $i->filter_flags ?? [],
                'topic' => $i->topic ? ['id' => $i->topic->id, 'kind' => $i->topic->kind, 'title' => $i->topic->title, 'verdict' => $i->topic->filter_verdict] : null,
            ])->all(),
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0,
            ],
            'labels' => Lexicon::LABELS,
        ], 'Radar: itens.');
    }

    // GET /admin/radar/relevances?sector=&days=
    public function relevances(Request $request)
    {
        $data = $request->validate(['sector' => ['nullable', 'string', 'max:40'], 'days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $rows = RadarRelevance::with('topic:id,kind,title,first_seen_at')
            ->when($data['sector'] ?? null, fn ($q, $v) => $q->where('sector_slug', $v))
            ->where('created_at', '>=', now()->subDays((int) ($data['days'] ?? 1)))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (RadarRelevance $r) => [
                'id' => $r->id, 'sector' => $r->sector_slug, 'topic' => $r->topic?->title, 'kind' => $r->topic?->kind,
                'relevant' => $r->relevant, 'score' => $r->score, 'theme' => $r->theme, 'reason' => $r->reason,
                'model' => $r->model, 'cost_usd' => round($r->cost_usd, 6), 'created_at' => self::iso($r->created_at),
            ])->all();

        return ApiResponse::success(['rows' => $rows], 'Radar: relevância.');
    }

    // POST /admin/radar/companies/{company}/switch {enabled}
    public function switchCompany(Request $request, int $company)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        Company::findOrFail($company);
        $modules = app(CompanyModuleService::class);
        $data['enabled'] ? $modules->enable($company, 'radar', CompanyModuleEvent::SOURCE_MANUAL, $request->user()->id)
            : $modules->disable($company, 'radar', CompanyModuleEvent::SOURCE_MANUAL, $request->user()->id);

        return ApiResponse::success(['companies' => $this->companies()], $data['enabled'] ? 'Radar ligado nesta empresa.' : 'Radar desligado nesta empresa.');
    }

    /** Por ramo: tendências avaliadas e relevantes (30 dias) e o custo da IA do mês. */
    private function relevanceBySector(): array
    {
        $since = now()->subDays((int) config('radar.retention_days', 30));

        return RadarRelevance::where('created_at', '>=', $since)
            ->selectRaw('sector_slug, count(*) as evaluated, sum(case when relevant then 1 else 0 end) as relevant')
            ->groupBy('sector_slug')->orderBy('sector_slug')->get()
            ->map(fn ($r) => ['sector' => $r->sector_slug, 'evaluated' => (int) $r->evaluated, 'relevant' => (int) $r->relevant,
                'cost_month_usd' => round((float) RadarRelevance::where('sector_slug', $r->sector_slug)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 4)])
            ->all();
    }

    /** Por empresa com sinais: criados hoje e em 30 dias, por estado, e o custo do "porque lhe interessa" no mês. */
    private function signalsByCompany(): array
    {
        $today = now('Europe/Lisbon')->startOfDay()->utc();
        $rows = RadarSignal::selectRaw('company_id, status, count(*) as n')->groupBy('company_id', 'status')->get()->groupBy('company_id');
        $names = Company::whereIn('id', $rows->keys())->get()->mapWithKeys(fn ($c) => [$c->id => $c->trade_name ?: $c->fiscal_name]);

        return $rows->map(fn ($g, $id) => [
            'company_id' => (int) $id, 'company' => $names[$id] ?? null,
            'today' => RadarSignal::where('company_id', $id)->where('created_at', '>=', $today)->count(),
            'by_status' => $g->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'cost_month_usd' => round((float) AiRequest::where('company_id', $id)->where('mode', 'radar_porque')->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 4),
        ])->values()->all();
    }

    /** Todas as empresas, com o ramo e o interruptor do Radar (o módulo radar). */
    private function companies(): array
    {
        $on = DB::table('company_modules')->where('module_key', 'radar')->pluck('company_id')->flip();

        return Company::with('contentSector:id,name')->orderBy('id')->get(['id', 'trade_name', 'fiscal_name', 'content_sector_id'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->trade_name ?: $c->fiscal_name, 'sector' => $c->contentSector?->name, 'radar_enabled' => $on->has($c->id)])
            ->all();
    }

    private static function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->toIso8601ZuluString();
    }
}
