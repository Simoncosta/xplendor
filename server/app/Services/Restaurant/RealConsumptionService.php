<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinStock;
use App\Models\StockCount;
use App\Models\WasteEntry;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Fatia 7b: consumo real × teórico (SÓ LEITURA). Entre as duas últimas contagens enviadas e
 * confirmadas no mesmo armazém, por artigo contado nas duas:
 *   Consumo real = Stock inicial + Entradas − Saídas por transferência − Stock final.
 *   Entradas = movimentos com sinal +1; Saídas por transferência = movimentos −1 dos tipos de guia e
 *   transferência. Só documentos que mexem no stock, de [data da 1.ª, data da 2.ª[, nesse armazém, sem
 *   as próprias contagens (1105 e 1106). Uma só chamada ao relatório Movimentos (do menor ao maior código).
 *   Desvio = Consumo real − Consumo teórico (Fatia 7a) − Desperdício registado; valor × PCM.
 * A quantidade dos movimentos vem na unidade do documento: converte-se só com a unidade de stock ou uma
 * conversão confirmada na XPLENDOR; sem ela, o artigo fica "sem conversão" e fora das contas.
 */
final class RealConsumptionService
{
    public const TRANSFER_OUT = ['1102', '1219', '1220', '1007', '1215', '1216', '1008', '1221', '1222', '1202'];
    public const OWN_COUNTS = ['1105', '1106'];
    public const WARNING = 'Só é fiável com fichas técnicas corretas e transferências registadas no PingWin.';

    public function __construct(private int $companyId, private StockMovementsService $movements) {}

    /** As duas últimas contagens enviadas e confirmadas no armazém (a mais antiga primeiro), ou null. */
    public function pair(string $warehouseId): ?array
    {
        $counts = StockCount::where('company_id', $this->companyId)->where('warehouse_id', $warehouseId)->where('status', 'enviada')
            ->whereNotNull('confirmed_at')->orderByDesc('inventory_date')->orderByDesc('id')->limit(2)->get();

        return $counts->count() === 2 ? [$counts[1], $counts[0]] : null;
    }

    /**
     * O pedido de Movimentos que o cálculo vai fazer: [from, to, código menor, código maior], ou null.
     *
     * @return array{0:string, 1:string, 2:string, 3:string}|null
     */
    public function movementRange(string $warehouseId): ?array
    {
        $pair = $this->pair($warehouseId);
        if (! $pair) {
            return null;
        }
        [$a, $b] = $pair;
        $products = array_intersect($a->lines()->whereNotNull('qty_base')->pluck('product_id')->all(), $b->lines()->whereNotNull('qty_base')->pluck('product_id')->all());
        $codes = array_values(array_filter($b->lines()->whereIn('product_id', $products)->pluck('code')->map(fn ($v) => (string) $v)->all()));
        if (! $codes || $a->dateString() >= $b->dateString()) {
            return null;
        }
        usort($codes, fn ($x, $y) => is_numeric($x) && is_numeric($y) ? (int) $x <=> (int) $y : strcmp($x, $y));

        return [$a->dateString(), CarbonImmutable::parse($b->dateString())->subDay()->toDateString(), $codes[0], end($codes)];
    }

    public function compute(string $warehouseId): ?array
    {
        $pair = $this->pair($warehouseId);
        if (! $pair) {
            return null;
        }
        [$a, $b] = $pair;
        $from = $a->dateString();
        $to = $b->dateString();
        $start = $a->lines()->whereNotNull('qty_base')->get()->keyBy('product_id');
        $end = $b->lines()->whereNotNull('qty_base')->get()->keyBy('product_id');
        $products = array_values(array_intersect($start->keys()->map(fn ($v) => (string) $v)->all(), $end->keys()->map(fn ($v) => (string) $v)->all()));
        if (! $products || $from >= $to) {
            return ['from' => $from, 'to' => $to, 'counts' => [$a->id, $b->id], 'rows' => [], 'warning' => self::WARNING];
        }
        $codes = [];
        foreach ($products as $p) {
            $codes[$p] = (string) ($end[$p]->code ?? $start[$p]->code ?? '');
        }
        $sorted = array_values(array_filter($codes));
        usort($sorted, fn ($x, $y) => is_numeric($x) && is_numeric($y) ? (int) $x <=> (int) $y : strcmp($x, $y));
        $lastDay = CarbonImmutable::parse($to)->subDay()->toDateString();
        $moves = $sorted ? $this->movements->fetch($this->companyId, $from, $lastDay, $sorted[0], end($sorted)) : [];

        $units = new StockUnits($this->companyId);
        $byCode = array_flip($codes);
        $in = $out = array_fill_keys($products, 0.0);
        $noConv = [];
        foreach ($moves as $m) {
            $pid = $byCode[$m['article_code']] ?? null;
            if ($pid === null || (string) $m['warehouse_id'] !== $warehouseId || ! $m['moves_stock'] || in_array((string) $m['docconfig_id'], self::OWN_COUNTS, true)
                || ($m['doc_date'] ?? '') < $from || ($m['doc_date'] ?? '') >= $to) {
                continue;
            }
            $unit = (string) ($m['unit_description'] ?? '');
            $factor = $unit === '' ? 1.0 : $units->factor($pid, $unit);
            if ($factor === null) {
                $noConv[$pid][$unit] = true;
                continue;
            }
            if ((int) $m['stk_signal'] === 1) {
                $in[$pid] += (float) $m['qnt'] * $factor;
            } elseif ((int) $m['stk_signal'] === -1 && in_array((string) $m['docconfig_id'], self::TRANSFER_OUT, true)) {
                $out[$pid] += abs((float) $m['qnt']) * $factor;
            }
        }

        // Consumo teórico no período (as lojas cujo armazém por omissão é este) e desperdício enviado.
        $consumption = new StockConsumptionService($this->companyId);
        $theo = array_fill_keys($products, 0.0);
        foreach ($consumption->daily($from, $lastDay) as $r) {
            if (isset($theo[$r['component_id']]) && $consumption->warehouseForStore($r['store_id']) === $warehouseId) {
                $theo[$r['component_id']] += $r['qty'];
            }
        }
        $waste = array_fill_keys($products, 0.0);
        $entries = WasteEntry::where('company_id', $this->companyId)->where('warehouse_id', $warehouseId)->whereNotNull('batch_id')
            ->whereHas('batch', fn ($q) => $q->where('status', 'enviada'))->whereBetween('registered_at', [$from . ' 00:00:00', $lastDay . ' 23:59:59'])->get();
        foreach ((new WasteService($this->companyId))->documentLines($entries) as $l) {
            if (isset($waste[$l['product_id']])) {
                $waste[$l['product_id']] += $l['qty'];
            }
        }

        $stock = PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $warehouseId)->whereIn('product_pingwin_id', $products)->get()->keyBy(fn ($s) => (string) $s->product_pingwin_id);
        $rows = [];
        foreach ($products as $p) {
            $uv = (float) ($stock[$p]->unit_value ?? 1) ?: 1.0;
            $real = (float) $start[$p]->qty_base + $in[$p] - $out[$p] - (float) $end[$p]->qty_base;
            $t = $theo[$p] / $uv;
            $dev = $real - $t - $waste[$p];
            $pcm = (float) ($stock[$p]->pcm ?? $end[$p]->unit_cost_snapshot ?? 0);
            $rows[] = [
                'product_id' => $p, 'code' => $codes[$p], 'description' => $end[$p]->description, 'unit' => $end[$p]->stock_unit,
                'initial' => (float) $start[$p]->qty_base, 'entries' => round($in[$p], 5), 'transfers_out' => round($out[$p], 5), 'final' => (float) $end[$p]->qty_base,
                // Sem conversão de uma unidade dos movimentos, as contas deste artigo não são fiáveis: ficam vazias.
                'real' => isset($noConv[$p]) ? null : round($real, 5), 'theoretical' => round($t, 5), 'waste' => round($waste[$p], 5),
                'deviation' => isset($noConv[$p]) ? null : round($dev, 5),
                'deviation_eur' => isset($noConv[$p]) ? null : round($dev * $pcm, 2),
                'without_conversion' => isset($noConv[$p]) ? array_keys($noConv[$p]) : [],
            ];
        }
        usort($rows, fn ($x, $y) => [abs($y['deviation_eur'] ?? -1), $x['description']] <=> [abs($x['deviation_eur'] ?? -1), $y['description']]);

        return ['from' => $from, 'to' => $to, 'counts' => [['id' => $a->id, 'document' => $a->pingwin_doc_number], ['id' => $b->id, 'document' => $b->pingwin_doc_number]],
            'rows' => $rows, 'warning' => self::WARNING];
    }
}
