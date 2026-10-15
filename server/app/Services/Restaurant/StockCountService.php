<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Jobs\WritePingwinCountJob;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinStock;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockCountZone;
use App\Models\StockCountZoneItem;
use App\Services\PingwinService;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Fatia 7b: contagens de stock. Contar (no telemóvel, cego por omissão), rever (o gestor
 * vê o teórico do último sync, a diferença em € e o que pede recontagem), aprovar e enviar para o
 * PingWin (uma Contagem 1106 Fechada, no WritePingwinCountJob) e confirmar por releitura.
 *
 *  · Data: "Antes da abertura" = o dia; "Depois do fecho" = o dia seguinte (o PingWin só guarda o dia).
 *  · Artigos: os contáveis (a lista do PingWin) com stock ou movimento no armazém nos últimos 90 dias.
 *  · ABC por armazém: valor = stock × PCM + consumo teórico de 30 dias × PCM; A até 80% do acumulado,
 *    B até 95%, C o resto.
 *  · Quantidades: unidade de stock ou conversões confirmadas na XPLENDOR (StockUnits); vazio = não
 *    contado, 0 = zero contado.
 *  · Envio bloqueado enquanto não estiver provado que a Contagem só muda os artigos listados
 *    (config stock_counts.unlisted_untouched_proven).
 */
final class StockCountService
{
    public function __construct(private int $companyId) {}

    /** A data da contagem pelo momento (em Lisboa). */
    public static function inventoryDate(string $moment, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now('Europe/Lisbon');

        return ($moment === 'depois_fecho' ? $now->addDay() : $now)->toDateString();
    }

    /**
     * ABC por armazém: product_id → A | B | C (só os artigos com valor > 0).
     *
     * @return array<string, string>
     */
    public function abc(string $warehouseId): array
    {
        $avg = (new StockConsumptionService($this->companyId))->averages();
        $values = [];
        foreach (PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $warehouseId)->get() as $s) {
            $pcm = (float) ($s->pcm ?? 0);
            $perDay = (float) ($avg['components'][(string) $s->product_pingwin_id]['by_warehouse'][$warehouseId] ?? 0);
            $uv = $s->unit_value && $s->unit_value > 0 ? $s->unit_value : 1.0;
            $values[(string) $s->product_pingwin_id] = max(0.0, $s->qnt) * $pcm + ($perDay / $uv) * 30 * $pcm;
        }

        return self::classify($values, (float) config('stock_counts.abc_a', 80), (float) config('stock_counts.abc_b', 95));
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, string>
     */
    public static function classify(array $values, float $a = 80, float $b = 95): array
    {
        $values = array_filter($values, fn ($v) => $v > 0);
        arsort($values);
        $total = array_sum($values);
        $out = [];
        $cum = 0.0;
        foreach ($values as $pid => $v) {
            $before = $total > 0 ? $cum / $total * 100 : 0;
            $cum += $v;
            // Entra na classe pelo acumulado ANTES dele (o artigo que atravessa os 80% ainda é A).
            $out[(string) $pid] = $before < $a ? 'A' : ($before < $b ? 'B' : 'C');
        }

        return $out;
    }

    /** @return list<string> os artigos a contar num armazém (contáveis com stock ou movimento em 90 dias) */
    public function candidates(string $warehouseId): array
    {
        $countable = PingwinCountableProduct::where('company_id', $this->companyId)->pluck('product_id')->map(fn ($v) => (string) $v)->all();
        $withStock = PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $warehouseId)->where('qnt', '!=', 0)
            ->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all();
        $since = CarbonImmutable::now('Europe/Lisbon')->subDays((int) config('stock_counts.movement_days', 90))->toDateString();
        $bought = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->companyId)->where('l.warehouse_pingwin_id', $warehouseId)
            ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)->where('d.doc_date', '>=', $since)
            ->whereNotNull('l.product_pingwin_id')->distinct()->pluck('l.product_pingwin_id')->map(fn ($v) => (string) $v)->all();
        $avg = (new StockConsumptionService($this->companyId))->averages();
        $consumed = array_keys(array_filter($avg['components'], fn ($c) => ($c['by_warehouse'][$warehouseId] ?? 0) > 0));
        $moving = array_fill_keys(array_merge($withStock, $bought, array_map('strval', $consumed)), true);
        // Sem a lista de contáveis (ainda não lida), vale o que mexe stock.
        $ids = $countable ? array_values(array_filter($countable, fn ($p) => isset($moving[$p]))) : array_keys($moving);
        sort($ids, SORT_STRING);

        return array_values(array_map('strval', $ids));
    }

    /** Cria uma contagem com as linhas do âmbito escolhido. */
    public function create(string $warehouseId, string $scope, ?int $zoneId, string $moment, bool $blind, ?int $userId): StockCount
    {
        if (! PingwinWarehouse::where('company_id', $this->companyId)->where('pingwin_id', $warehouseId)->where('deleted', false)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Armazém não encontrado.']]);
        }
        $zone = null;
        if ($scope === 'zona') {
            $zone = StockCountZone::where('company_id', $this->companyId)->where('warehouse_id', $warehouseId)->find($zoneId);
            if (! $zone) {
                throw ValidationException::withMessages(['zone_id' => ['Escolha uma zona deste armazém.']]);
            }
        }
        $abc = $this->abc($warehouseId);
        $ids = $this->candidates($warehouseId);
        if ($scope === 'artigos_a') {
            $ids = array_values(array_filter($ids, fn ($p) => ($abc[$p] ?? null) === 'A'));
        } elseif ($zone) {
            $ids = $zone->items()->pluck('product_id')->map(fn ($v) => (string) $v)->all();
        }
        if (! $ids) {
            throw ValidationException::withMessages(['scope' => ['Não há artigos para contar neste âmbito.']]);
        }

        return DB::transaction(function () use ($warehouseId, $scope, $zone, $moment, $blind, $userId, $ids, $abc) {
            $count = StockCount::create([
                'company_id' => $this->companyId, 'warehouse_id' => $warehouseId, 'scope' => $scope, 'zone_id' => $zone?->id,
                'moment' => $moment, 'inventory_date' => self::inventoryDate($moment), 'blind' => $blind, 'status' => 'em_contagem', 'created_by' => $userId,
            ]);
            $zones = $this->zoneOrder($warehouseId);
            $stock = PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $warehouseId)->whereIn('product_pingwin_id', $ids)->get()->keyBy(fn ($s) => (string) $s->product_pingwin_id);
            $names = PingwinCountableProduct::where('company_id', $this->companyId)->whereIn('product_id', $ids)->get()->keyBy(fn ($p) => (string) $p->product_id);
            $catalog = PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $ids)->get()->keyBy(fn ($p) => (string) $p->pingwin_id);
            $units = new StockUnits($this->companyId);
            foreach ($ids as $pid) {
                $s = $stock[$pid] ?? null;
                $u = $units->for($pid);
                StockCountLine::create([
                    'count_id' => $count->id, 'product_id' => $pid,
                    'code' => $names[$pid]->code ?? $catalog[$pid]->code ?? null, 'description' => $names[$pid]->description ?? $catalog[$pid]->description ?? $pid,
                    'abc_class' => $abc[$pid] ?? 'C', 'zone_id' => $zones[$pid]['zone_id'] ?? null, 'position' => $zones[$pid]['position'] ?? 0,
                    'stock_unit' => $u['stock_unit'], 'stock_unit_id' => $u['stock_unit_id'],
                    'theoretical_snapshot' => $s?->qnt ?? 0.0, 'snapshot_at' => $s?->synced_at, 'unit_cost_snapshot' => $s?->pcm,
                ]);
            }

            return $count;
        });
    }

    /** product_id → {zone_id, position}: a ordem das zonas e, dentro de cada uma, a dos artigos. */
    private function zoneOrder(string $warehouseId): array
    {
        $out = [];
        foreach (StockCountZone::where('company_id', $this->companyId)->where('warehouse_id', $warehouseId)->orderBy('position')->orderBy('id')->get() as $zi => $z) {
            foreach (StockCountZoneItem::where('zone_id', $z->id)->orderBy('position')->get() as $ii => $it) {
                $out[(string) $it->product_id] ??= ['zone_id' => $z->id, 'position' => ($zi + 1) * 10000 + $ii];
            }
        }

        return $out;
    }

    /** Grava o que se contou numa linha (entradas em várias unidades). */
    public function saveLine(StockCount $count, StockCountLine $line, array $entries, ?int $userId): StockCountLine
    {
        if ($count->status !== 'em_contagem') {
            throw ValidationException::withMessages(['count' => ['Esta contagem já não está a ser contada.']]);
        }
        $sum = (new StockUnits($this->companyId))->sum((string) $line->product_id, $entries);
        $line->forceFill([
            'entries' => $sum['entries'] ?: null, 'qty_base' => $sum['qty_base'],
            'counted_by' => $sum['qty_base'] === null ? null : $userId, 'counted_at' => $sum['qty_base'] === null ? null : now(),
            'recount_requested' => $sum['qty_base'] === null ? $line->recount_requested : false,
        ])->save();

        return $line;
    }

    public function finish(StockCount $count): StockCount
    {
        if ($count->status !== 'em_contagem') {
            throw ValidationException::withMessages(['count' => ['Esta contagem já não está a ser contada.']]);
        }
        if (! $count->lines()->whereNotNull('qty_base')->exists()) {
            throw ValidationException::withMessages(['count' => ['Ainda não contou nenhum artigo.']]);
        }
        $count->update(['status' => 'em_revisao']);

        return $count;
    }

    /** A revisão: uma linha por artigo, com a diferença em € e o pedido de recontagem sugerido. */
    public function review(StockCount $count): array
    {
        $eur = (float) config('stock_counts.recount_eur', 25);
        $pct = (float) config('stock_counts.recount_pct', 20);
        $minQ = (float) config('stock_counts.recount_min_qty', 1);
        $rows = [];
        $tot = ['counted_eur' => 0.0, 'theoretical_eur' => 0.0, 'diff_eur' => 0.0, 'with_diff' => 0, 'not_counted' => 0, 'counted' => 0];
        foreach ($count->lines()->get() as $l) {
            $cost = (float) ($l->unit_cost_snapshot ?? 0);
            $theo = (float) ($l->theoretical_snapshot ?? 0);
            if ($l->qty_base === null) {
                $tot['not_counted']++;
                $rows[] = self::reviewRow($l, null, null, null, false);
                continue;
            }
            $tot['counted']++;
            $diff = $l->qty_base - $theo;
            $diffEur = $diff * $cost;
            $p = abs($theo) > 1e-9 ? $diff / abs($theo) * 100 : ($diff != 0.0 ? 100.0 : 0.0);
            $recount = abs($diffEur) >= $eur || (abs($p) >= $pct && abs($diff) >= $minQ);
            $tot['counted_eur'] += $l->qty_base * $cost;
            $tot['theoretical_eur'] += $theo * $cost;
            $tot['diff_eur'] += $diffEur;
            $tot['with_diff'] += abs($diff) > 1e-9 ? 1 : 0;
            $rows[] = self::reviewRow($l, $diff, $diffEur, $p, $recount);
        }
        usort($rows, fn ($a, $b) => [abs($b['diff_eur'] ?? -1), $a['description']] <=> [abs($a['diff_eur'] ?? -1), $b['description']]);
        $snapshot = $count->lines()->max('snapshot_at');

        return [
            'rows' => $rows,
            'totals' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $tot),
            'theoretical_at' => $snapshot ? \Illuminate\Support\Carbon::parse($snapshot)->toIso8601String() : null,
            'limits' => ['eur' => $eur, 'pct' => $pct, 'min_qty' => $minQ],
            'send_block' => $this->sendBlock($count),
        ];
    }

    private static function reviewRow(StockCountLine $l, ?float $diff, ?float $diffEur, ?float $pct, bool $recount): array
    {
        return [
            'id' => $l->id, 'product_id' => $l->product_id, 'code' => $l->code, 'description' => $l->description, 'abc' => $l->abc_class,
            'unit' => $l->stock_unit, 'counted' => $l->qty_base, 'entries' => $l->entries, 'previous' => $l->previous_qty_base,
            'theoretical' => $l->theoretical_snapshot, 'unit_cost_eur' => $l->unit_cost_snapshot,
            'diff' => $diff !== null ? round($diff, 5) : null, 'diff_eur' => $diffEur !== null ? round($diffEur, 2) : null,
            'diff_pct' => $pct !== null ? round($pct, 1) : null, 'recount_suggested' => $recount, 'recount_requested' => $l->recount_requested,
            'pingwin' => $l->confirm_status ? ['theoretical' => $l->pingwin_theoretical_qnt, 'difference' => $l->pingwin_qnt_difference,
                'price_difference_eur' => $l->pingwin_price_difference, 'confirmed_qty' => $l->confirmed_qty, 'status' => $l->confirm_status] : null,
        ];
    }

    /** Pede recontagem: as linhas voltam ao contador, cegas, e guarda-se o valor anterior. */
    public function requestRecount(StockCount $count, array $lineIds): int
    {
        if (! in_array($count->status, ['em_revisao', 'em_contagem'], true)) {
            throw ValidationException::withMessages(['count' => ['Esta contagem já não pode ser recontada.']]);
        }
        $n = 0;
        foreach ($count->lines()->whereIn('id', $lineIds)->get() as $l) {
            $l->forceFill(['previous_qty_base' => $l->qty_base, 'qty_base' => null, 'entries' => null, 'counted_by' => null, 'counted_at' => null,
                'recount_requested' => true])->save();
            $n++;
        }
        if ($n) {
            $count->update(['status' => 'em_contagem', 'blind' => true]);
        }

        return $n;
    }

    /**
     * Porque não se pode enviar (null = pode). Inclui a guarda do dev e a regra do PASSO 0.3.
     *
     * @return array{mode:string, reason:string}|null
     */
    public function sendBlock(StockCount $count): ?array
    {
        if (! config('stock_counts.unlisted_untouched_proven')) {
            return ['mode' => 'por_provar', 'reason' => 'O envio está bloqueado: ainda não ficou provado no PingWin que uma Contagem só altera os artigos que lista. Até lá, os artigos não contados poderiam ficar a zero.'];
        }
        $codes = $count->lines()->whereNotNull('qty_base')->pluck('code')->map(fn ($v) => (string) $v)->all();

        return PingwinService::stockDocWriteGuard($codes);
    }

    /** Aprovar e enviar: um documento por ação de um gestor; nunca se reenvia sozinho. */
    public function approveAndSend(StockCount $count, ?int $userId): PingwinStockWrite
    {
        if ($count->status !== 'em_revisao') {
            throw ValidationException::withMessages(['count' => ['Só se envia uma contagem em revisão.']]);
        }
        if ($block = $this->sendBlock($count)) {
            throw ValidationException::withMessages(['pingwin' => [$block['reason']]]);
        }
        $lines = $count->lines()->whereNotNull('qty_base')->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['count' => ['Não há artigos contados.']]);
        }
        $busy = PingwinStockWrite::where('company_id', $this->companyId)->whereIn('kind', ['contagem', 'desperdicio', 'anular_documento'])
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subMinutes(15))->exists();
        if ($busy) {
            throw ValidationException::withMessages(['pingwin' => ['Já há um documento de stock a ser gravado: aguarde que termine.']]);
        }
        $write = PingwinStockWrite::create([
            'company_id' => $this->companyId, 'user_id' => $userId, 'kind' => 'contagem', 'warehouse_pingwin_id' => $count->warehouse_id,
            'payload' => ['count_id' => $count->id, 'inventory_date' => $count->dateString(), 'lines' => $lines->count()], 'status' => 'queued',
        ]);
        $count->update(['status' => 'a_enviar', 'approved_by' => $userId, 'approved_at' => now(), 'error_message' => null]);
        WritePingwinCountJob::dispatch($write->id);

        return $write;
    }
}
