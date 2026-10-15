<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\WritePingwinBomJob;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnit;
use App\Models\RecipeDraft;
use App\Models\RecipeWrite;
use App\Services\OcrArticleSearchService;
use App\Services\PingwinService;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeReportService;
use App\Services\Restaurant\RecipeWriteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 4: o editor das fichas técnicas. O rascunho fica na XPLENDOR (recipe_drafts) e o
 * custo XPLENDOR atualiza-se enquanto se edita (preview). "Gravar na PingWin" cria um RecipeWrite e
 * põe-no na fila (WritePingwinBomJob): uma ficha de cada vez, confirmada por releitura. Tenancy
 * primeiro; escrever pede restauracao.editar (submódulo restauracao_artigos); os euros só para quem
 * vê as Finanças.
 */
class CompanyRecipeEditController extends Controller
{
    private function forUser(array $payload, Request $request, int $companyId): array
    {
        if (CompassFinancials::visibleTo($request->user(), $companyId)) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }

    private function known(int $companyId, string $productId): bool
    {
        return PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->exists();
    }

    /** Tudo o que o editor precisa: versões (com as unidades de cada componente), lojas, rascunho, gravação em curso. */
    public function context(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->known($companyId, $productId)) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $svc = new RecipeWriteService($companyId);
        $snapshot = $svc->snapshot($productId);
        $components = collect($snapshot['versions'])->flatMap(fn ($v) => array_column($v['lines'], 'component_id'))->unique()->values()->all();
        $cost = (new RecipeCostService($companyId))->load([$productId], $components);
        foreach ($snapshot['versions'] as &$v) {
            foreach ($v['lines'] as &$l) {
                $l['units'] = $cost->unitsFor($l['component_id'], $l['unit_id']);
            }
        }
        unset($v, $l);
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $inForce = RecipeCostService::versionInForce(array_map(fn ($v) => ['bom_id' => $v['id']] + $v, $snapshot['versions']), $today);
        $draft = RecipeDraft::where('company_id', $companyId)->where('product_pingwin_id', $productId)->where('user_id', $request->user()->id)->first();
        $active = RecipeWrite::where('company_id', $companyId)->where('product_pingwin_id', $productId)->whereIn('status', RecipeWrite::ACTIVE)->latest('id')->first();
        $item = PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->first();

        return ApiResponse::success([
            'product' => ['product_id' => $productId, 'code' => $item?->code, 'description' => $item?->description],
            'versions' => $snapshot['versions'],
            'signature' => RecipeWriteService::signature($svc->snapshot($productId)),
            'in_force' => $inForce['id'] ?? null,
            'stores' => $svc->storeUniverse($snapshot),
            'today' => $today,
            'draft' => $draft ? ['payload' => $draft->payload, 'base_signature' => $draft->base_signature, 'updated_at' => $draft->updated_at?->toIso8601String()] : null,
            'active_write' => $active ? $this->writeShape($active) : null,
            'write_allowed' => ! PingwinService::bomWriteAllowlist() || in_array($productId, PingwinService::bomWriteAllowlist(), true),
        ], 'Editor da ficha técnica.');
    }

    /** Pesquisa de componentes (código, descrição, fornecedor), como no ArticlePicker. */
    public function components(Request $request, int $companyId, OcrArticleSearchService $search)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return ApiResponse::success(['components' => []], 'Pesquise por código, descrição ou fornecedor.');
        }
        $hits = $search->search($companyId, $q, null, 20);
        $items = PingwinCatalogItem::where('company_id', $companyId)->whereIn('id', array_column($hits, 'id'))->get()->keyBy('id');
        $withBom = DB::table('pingwin_product_boms')->where('company_id', $companyId)
            ->whereIn('product_pingwin_id', $items->pluck('pingwin_id'))->distinct()->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all();
        $cost = (new RecipeCostService($companyId))->load([], $items->pluck('pingwin_id')->map(fn ($v) => (string) $v)->all());

        return ApiResponse::success(['components' => array_values(array_filter(array_map(function ($h) use ($items, $withBom, $cost) {
            $i = $items[$h['id']] ?? null;
            $units = $i ? $cost->unitsFor((string) $i->pingwin_id) : [];

            return $i ? [
                'units' => $units, 'default_unit_id' => $units[0]['id'] ?? null,
                'component_id' => (string) $i->pingwin_id, 'code' => $i->code ? ltrim((string) $i->code, "\u{FEFF}") : null,
                'description' => $i->description, 'family' => RecipeReportService::family($i->family), 'unit' => $h['unit'],
                'inactive' => ! $i->is_active || $i->product_status === 'Inativo', 'is_semi' => in_array((string) $i->pingwin_id, $withBom, true),
                'last_purchase' => $h['last_purchase'] ? ['date' => $h['last_purchase']['date'] ?? null, 'supplier' => $h['last_purchase']['supplier'] ?? null] : null,
                'approximate' => $h['approximate'],
            ] : null;
        }, $hits)))], 'Componentes.');
    }

    private function draftRules(): array
    {
        return [
            'mode' => ['required', Rule::in(RecipeWriteService::MODES)],
            'bom_id' => ['nullable', 'string', 'max:32'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'stores' => ['array'],
            'stores.*' => ['boolean'],
            'lines' => ['array', 'max:' . RecipeWriteService::MAX_LINES],
            'lines.*.key' => ['nullable', 'string', 'max:40'],
            'lines.*.component_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.unit_id' => ['required', 'string', 'max:32'],
            'lines.*.qnt' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.waste' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.recovery' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** O custo XPLENDOR do rascunho, as unidades e os avisos de cada linha, e o efeito nas lojas. */
    public function preview(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->known($companyId, $productId)) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $draft = $request->validate($this->draftRules());
        $lines = $draft['lines'] ?? [];
        $svc = new RecipeWriteService($companyId);
        $snapshot = $svc->snapshot($productId);
        $cost = (new RecipeCostService($companyId))->load([$productId], array_column($lines, 'component_id'));
        $baseVersion = ($draft['mode'] === 'corrigir' && ! empty($draft['bom_id']))
            ? collect($cost->versionsOf($productId))->firstWhere('bom_id', $draft['bom_id']) : null;
        $costed = $cost->costDraft($productId, $lines, $baseVersion);
        $report = new RecipeReportService($companyId);
        $shaped = array_map(function ($l, $i) use ($report, $cost, $lines) {
            $row = $report->shapeLine($l);
            $row['units'] = $cost->unitsFor($l['component_id'], $l['unit_id']);
            $row['input_key'] = $lines[$i]['key'] ?? null;
            $row['yield'] = $l['sub']['yield'] ?? null;

            return $row;
        }, $costed['lines'], array_keys($costed['lines']));

        // Preço sem IVA (90 dias), para o food cost.
        $since = CarbonImmutable::now('Europe/Lisbon')->subDays(RecipeCostService::WINDOW_DAYS)->toDateString();
        $sales = DB::table('pingwin_item_sales_daily')->where('company_id', $companyId)->where('product_pingwin_id', $productId)
            ->where('business_date', '>=', $since)->selectRaw('SUM(quantity) q, SUM(net_cents) n')->first();
        $price = ($sales && (float) $sales->q > 0) ? ((int) $sales->n) / 100 / (float) $sales->q : null;
        $from = $draft['valid_from'] ?? CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $effects = $svc->storeEffects($snapshot, RecipeWriteService::versionsAfterDraft($snapshot, $draft + ['valid_from' => $from]), $from);

        return ApiResponse::success($this->forUser([
            'lines' => $shaped,
            'cost_eur' => $costed['cost'] !== null ? round($costed['cost'], 4) : null,
            'cost_complete' => $costed['complete'],
            'low_confidence' => $costed['low_confidence'],
            'price_eur' => $price !== null ? round($price, 4) : null,
            'food_cost_pct' => $price && $costed['cost'] !== null ? round($costed['cost'] / $price * 100, 1) : null,
            'margin_eur' => $price !== null && $costed['cost'] !== null ? round($price - $costed['cost'], 4) : null,
            'store_effects' => $effects,
        ], $request, $companyId), 'Pré-visualização.');
    }

    public function draft(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = RecipeDraft::where('company_id', $companyId)->where('product_pingwin_id', $productId)->where('user_id', $request->user()->id)->first();

        return ApiResponse::success(['draft' => $d ? ['payload' => $d->payload, 'base_signature' => $d->base_signature, 'updated_at' => $d->updated_at?->toIso8601String()] : null], 'Rascunho.');
    }

    public function saveDraft(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->known($companyId, $productId)) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $data = $request->validate(['payload' => ['required', 'array'], 'base_signature' => ['nullable', 'string', 'max:64']]);
        $d = RecipeDraft::updateOrCreate(
            ['company_id' => $companyId, 'product_pingwin_id' => $productId, 'user_id' => $request->user()->id],
            ['payload' => $data['payload'], 'base_signature' => $data['base_signature'] ?? null],
        );

        return ApiResponse::success(['updated_at' => $d->updated_at?->toIso8601String()], 'Rascunho guardado.');
    }

    public function deleteDraft(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        RecipeDraft::where('company_id', $companyId)->where('product_pingwin_id', $productId)->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['deleted' => true], 'Rascunho descartado.');
    }

    /** Recusas comuns a todas as escritas: artigo, guarda do teste real, gravação em curso, espelho mudado. */
    private function refuse(int $companyId, string $productId, ?string $signature, array $snapshot)
    {
        if (! $this->known($companyId, $productId)) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $allow = PingwinService::bomWriteAllowlist();
        if ($allow && ! in_array($productId, $allow, true)) {
            return ApiResponse::error('Gravar fichas técnicas na PingWin só está autorizado no artigo de teste.', 403);
        }
        if (RecipeWrite::where('company_id', $companyId)->where('product_pingwin_id', $productId)->whereIn('status', RecipeWrite::ACTIVE)->exists()) {
            return ApiResponse::error('Já há uma gravação desta ficha em curso: aguarde que termine.', 409);
        }
        if ($signature !== RecipeWriteService::signature($snapshot)) {
            return ApiResponse::error('A ficha mudou desde que a abriu (outra gravação ou a leitura noturna): recarregue antes de gravar.', 409);
        }

        return null;
    }

    /** "Gravar na PingWin": criar a ficha, uma versão nova ou corrigir uma versão. */
    public function write(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $draft = $request->validate(array_merge($this->draftRules(), [
            'base_signature' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:' . RecipeWriteService::MAX_LINES],
            'stores' => ['required', 'array', 'min:1'],
        ]));
        $svc = new RecipeWriteService($companyId);
        $snapshot = $svc->snapshot($productId);
        if ($r = $this->refuse($companyId, $productId, $draft['base_signature'], $snapshot)) {
            return $r;
        }

        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $has = (bool) $snapshot['versions'];
        $mode = $draft['mode'];
        if ($mode === 'criar' && $has) {
            return ApiResponse::error('Este artigo já tem ficha: crie uma versão nova ou corrija uma versão.', 422);
        }
        if ($mode === 'nova_versao' && ! $has) {
            $mode = 'criar';
        }
        if ($mode !== 'corrigir' && (empty($draft['valid_from']) || $draft['valid_from'] < $today)) {
            return ApiResponse::error('A data da versão nova tem de ser hoje ou uma data futura.', 422);
        }
        if ($mode === 'corrigir' && ! collect($snapshot['versions'])->firstWhere('id', $draft['bom_id'] ?? null)) {
            return ApiResponse::error('Versão não encontrada.', 404);
        }
        if (! in_array(true, array_map('boolval', $draft['stores']), true)) {
            return ApiResponse::error('Escolha pelo menos uma loja.', 422);
        }
        $known = array_column($svc->storeUniverse($snapshot), 'store_id');
        if ($unknown = array_diff(array_keys($draft['stores']), $known)) {
            return ApiResponse::error('Lojas desconhecidas: ' . implode(', ', $unknown) . '.', 422);
        }
        // Componentes desta empresa, nunca o próprio artigo, e só unidades possíveis de cada um.
        $components = array_column($draft['lines'], 'component_id');
        if (in_array($productId, $components, true)) {
            return ApiResponse::error('Um artigo não pode ser componente da sua própria ficha.', 422);
        }
        $cost = (new RecipeCostService($companyId))->load([$productId], $components);
        $found = PingwinCatalogItem::where('company_id', $companyId)->whereIn('pingwin_id', $components)->pluck('description', 'pingwin_id');
        foreach ($draft['lines'] as $i => $l) {
            if (! $found->has($l['component_id'])) {
                return ApiResponse::error("Linha " . ($i + 1) . ': componente desconhecido.', 422);
            }
            if (! in_array($l['unit_id'], array_column($cost->unitsFor($l['component_id'], $l['unit_id']), 'id'), true)) {
                return ApiResponse::error("Linha " . ($i + 1) . ': unidade que não é deste componente.', 422);
            }
            $draft['lines'][$i]['description'] = $found[$l['component_id']];
        }

        $draft['mode'] = $mode;
        $plan = $svc->plan($snapshot, $draft);
        if (! $plan['ops']) {
            return ApiResponse::error('Não há nada para gravar: a versão já está assim na PingWin.', 422);
        }
        $write = RecipeWrite::create([
            'company_id' => $companyId, 'product_pingwin_id' => $productId, 'user_id' => $request->user()->id,
            'operation' => $mode === 'nova_versao' ? 'nova_versao' : $mode,
            'bom_pingwin_id' => $plan['target'], 'valid_from' => $mode === 'corrigir' ? null : $draft['valid_from'],
            'before' => $snapshot,
            'after' => ['valid_from' => $mode === 'corrigir' ? null : $draft['valid_from'], 'stores' => $draft['stores'],
                'lines' => array_map(fn ($l) => RecipeWriteService::lineFields($l), $draft['lines'])],
            'plan' => ['expected' => $snapshot, 'ops' => $plan['ops']],
            'status' => 'queued', 'progress' => ['steps' => [], 'current' => null],
        ]);
        WritePingwinBomJob::dispatch($write->id);

        return ApiResponse::success(['write' => $this->writeShape($write->fresh())], 'A gravar na PingWin…');
    }

    /** Apagar uma versão (confirmado; diz o que muda em cada loja). */
    public function deleteVersion(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['bom_id' => ['required', 'string', 'max:32'], 'base_signature' => ['required', 'string'], 'confirm' => ['required', 'accepted']]);
        $snapshot = (new RecipeWriteService($companyId))->snapshot($productId);
        if ($r = $this->refuse($companyId, $productId, $data['base_signature'], $snapshot)) {
            return $r;
        }
        if (! collect($snapshot['versions'])->firstWhere('id', $data['bom_id'])) {
            return ApiResponse::error('Versão não encontrada.', 404);
        }

        return $this->queueDelete($request, $companyId, $productId, $snapshot, [$data['bom_id']], 'apagar_versao');
    }

    /** Apagar a ficha inteira = apagar todas as versões (dupla confirmação). */
    public function deleteAll(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['base_signature' => ['required', 'string'], 'confirm' => ['required', 'accepted'], 'confirm_all' => ['required', 'accepted']]);
        $snapshot = (new RecipeWriteService($companyId))->snapshot($productId);
        if ($r = $this->refuse($companyId, $productId, $data['base_signature'], $snapshot)) {
            return $r;
        }
        if (! $snapshot['versions']) {
            return ApiResponse::error('Este artigo não tem ficha.', 422);
        }

        return $this->queueDelete($request, $companyId, $productId, $snapshot, array_column($snapshot['versions'], 'id'), 'apagar_ficha');
    }

    private function queueDelete(Request $request, int $companyId, string $productId, array $snapshot, array $ids, string $operation)
    {
        $write = RecipeWrite::create([
            'company_id' => $companyId, 'product_pingwin_id' => $productId, 'user_id' => $request->user()->id,
            'operation' => $operation, 'bom_pingwin_id' => count($ids) === 1 ? $ids[0] : null,
            'before' => $snapshot, 'after' => ['deleted_versions' => $ids],
            'plan' => ['expected' => $snapshot, 'ops' => array_map(fn ($id) => ['op' => 'delete_version', 'version' => $id], $ids)],
            'status' => 'queued', 'progress' => ['steps' => [], 'current' => null],
        ]);
        WritePingwinBomJob::dispatch($write->id);

        return ApiResponse::success(['write' => $this->writeShape($write->fresh())], 'A apagar na PingWin…');
    }

    /** O efeito de apagar uma versão (ou todas) em cada loja, para a confirmação. */
    public function deleteEffects(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $svc = new RecipeWriteService($companyId);
        $snapshot = $svc->snapshot($productId);
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('bom_ids', '')))));
        // "*" = apagar a ficha inteira (todas as versões).
        $after = $ids === ['*'] ? [] : array_values(array_filter($snapshot['versions'], fn ($v) => ! in_array($v['id'], $ids, true)));
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();

        return ApiResponse::success(['store_effects' => $svc->storeEffects($snapshot, $after, $today)], 'Efeito nas lojas.');
    }

    public function writeStatus(int $companyId, int $writeId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $w = RecipeWrite::where('company_id', $companyId)->find($writeId);

        return $w ? ApiResponse::success(['write' => $this->writeShape($w)], 'Gravação.') : ApiResponse::error('Gravação não encontrada.', 404);
    }

    /** O histórico de alterações de uma ficha (quem, quando, versão, antes e depois, resultado). */
    public function history(int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $rows = RecipeWrite::where('company_id', $companyId)->where('product_pingwin_id', $productId)->with('user:id,name')->latest('id')->limit(50)->get();
        $units = PingwinUnit::where('company_id', $companyId)->pluck('description', 'pingwin_id');
        $names = PingwinCatalogItem::where('company_id', $companyId)->whereIn('pingwin_id', $rows->flatMap(fn ($w) => array_merge(
            array_column($w->after['lines'] ?? [], 'component_id'),
            collect($w->before['versions'] ?? [])->flatMap(fn ($v) => array_column($v['lines'] ?? [], 'component_id'))->all(),
        ))->unique()->all())->pluck('description', 'pingwin_id');

        return ApiResponse::success(['history' => $rows->map(fn ($w) => $this->writeShape($w) + [
            'before' => $w->before, 'after' => $w->after,
            'names' => ['components' => $names, 'units' => $units],
        ])->all()], 'Histórico.');
    }

    private function writeShape(RecipeWrite $w): array
    {
        return [
            'id' => $w->id, 'operation' => $w->operation, 'status' => $w->status,
            'bom_id' => $w->bom_pingwin_id, 'valid_from' => $w->valid_from?->toDateString(),
            'progress' => $w->progress, 'result' => $w->result, 'error' => $w->error,
            'user' => $w->user?->name, 'created_at' => $w->created_at?->toIso8601String(),
            'started_at' => $w->started_at?->toIso8601String(), 'finished_at' => $w->finished_at?->toIso8601String(),
            'ops' => count($w->plan['ops'] ?? []),
        ];
    }
}
