<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Jobs\VoidPingwinStockDocumentJob;
use App\Jobs\WritePingwinWasteJob;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinStock;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\WasteBatch;
use App\Models\WasteEntry;
use App\Services\PingwinService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Fatia 7b: desperdício. Registar no telemóvel (artigo contável ou prato com ficha, quantidade
 * e unidade, motivo), editar e apagar enquanto não se envia; o gestor envia um documento por armazém com
 * as linhas pendentes (Desperdício 1100, Fechado). Um prato com ficha explode, ao enviar, pela versão da
 * ficha em vigor nessa data e na loja do armazém, um só nível (os preparados da cozinha com stock vão
 * como estão). Os euros são uma estimativa (× PCM).
 */
final class WasteService
{
    public function __construct(private int $companyId) {}

    /** @return array{product_id:string, code:?string, description:string, is_dish:bool}|null */
    public function product(string $productId): ?array
    {
        $c = PingwinCountableProduct::where('company_id', $this->companyId)->where('product_id', $productId)->first();
        if ($c) {
            return ['product_id' => $productId, 'code' => $c->code, 'description' => (string) $c->description, 'is_dish' => false];
        }
        $consumption = new StockConsumptionService($this->companyId);
        if ($consumption->versionsOf($productId)) {
            $i = PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $productId)->first();

            return ['product_id' => $productId, 'code' => $i?->code, 'description' => (string) ($i?->description ?? $productId), 'is_dish' => true];
        }

        return null;
    }

    /** As unidades de um artigo (a de stock e as confirmadas); um prato regista-se em unidades vendidas. */
    public function units(string $productId, bool $isDish): array
    {
        return $isDish ? ['stock_unit' => 'Unidade', 'stock_unit_id' => null, 'units' => [['unit' => 'Unidade', 'factor' => 1.0, 'source' => 'prato']]]
            : (new StockUnits($this->companyId))->for($productId);
    }

    /**
     * A explosão de um prato pela ficha em vigor nessa data e na loja do armazém (um nível).
     *
     * @return list<array{product_id:string, code:?string, description:?string, qty:float}>
     */
    public function explode(string $dishId, float $qty, string $warehouseId, string $date): array
    {
        $consumption = new StockConsumptionService($this->companyId);
        $store = null;
        foreach ($consumption->storeWarehouses() as $sid => $s) {
            if ($s['warehouse_id'] === $warehouseId) {
                $store = (string) $sid;
                break;
            }
        }
        $versions = $consumption->versionsOf($dishId);
        $v = ($store !== null ? RecipeCostService::versionInForce($versions, $date, $store) : null) ?? RecipeCostService::versionInForce($versions, $date);
        if (! $v) {
            return [];
        }
        $items = PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', array_column($v['lines'], 'component_id'))->get()->keyBy(fn ($i) => (string) $i->pingwin_id);
        $out = [];
        foreach ($v['lines'] as $l) {
            if ($l['component_id'] === '') {
                continue;
            }
            $c = $l['component_id'];
            $out[$c] ??= ['product_id' => $c, 'code' => $items[$c]->code ?? null, 'description' => $items[$c]->description ?? $l['description'], 'qty' => 0.0];
            $out[$c]['qty'] += $qty * $l['qnt_base'] * (1 + $l['waste'] / 100);
        }

        return array_values(array_map(fn ($x) => array_merge($x, ['qty' => round($x['qty'], 5)]), $out));
    }

    private function pcm(string $productId, string $warehouseId): ?float
    {
        $row = PingwinStock::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->where('warehouse_pingwin_id', $warehouseId)->first()
            ?? PingwinStock::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->whereNotNull('pcm')->first();

        return $row?->pcm;
    }

    /** Valida e grava um registo (novo ou editado). */
    public function save(?WasteEntry $entry, array $data, ?int $userId): WasteEntry
    {
        if ($entry && $entry->batch_id) {
            throw ValidationException::withMessages(['entry' => ['Este registo já foi enviado para o PingWin: não se pode mudar.']]);
        }
        if (! PingwinWarehouse::where('company_id', $this->companyId)->where('pingwin_id', $data['warehouse_id'])->where('deleted', false)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Armazém não encontrado.']]);
        }
        $p = $this->product((string) $data['product_id']);
        if (! $p) {
            throw ValidationException::withMessages(['product_id' => ['Artigo não encontrado (nem contável nem prato com ficha).']]);
        }
        if (! array_key_exists($data['reason'], WasteEntry::REASONS)) {
            throw ValidationException::withMessages(['reason' => ['Escolha um motivo.']]);
        }
        if ($data['reason'] === 'outro' && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => ['Com o motivo «Outro», escreva o que aconteceu.']]);
        }
        $qty = (float) str_replace(',', '.', (string) $data['qty']);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => ['A quantidade tem de ser maior do que 0.']]);
        }
        $units = $this->units($p['product_id'], $p['is_dish']);
        $unit = trim((string) ($data['unit'] ?? '')) ?: $units['stock_unit'];
        $factor = null;
        foreach ($units['units'] as $u) {
            if (mb_strtolower($u['unit']) === mb_strtolower($unit)) {
                $factor = $u['factor'];
            }
        }
        if ($factor === null) {
            throw ValidationException::withMessages(['unit' => ["A unidade «{$unit}» não tem uma conversão confirmada."]]);
        }
        $qtyBase = round($qty * $factor, 5);
        $date = substr((string) ($entry?->registered_at ?? now('Europe/Lisbon')), 0, 10);
        $value = null;
        if ($p['is_dish']) {
            $parts = $this->explode($p['product_id'], $qtyBase, (string) $data['warehouse_id'], $date);
            $value = array_sum(array_map(fn ($x) => $x['qty'] * (float) ($this->pcm($x['product_id'], (string) $data['warehouse_id']) ?? 0), $parts));
        } elseif (($pcm = $this->pcm($p['product_id'], (string) $data['warehouse_id'])) !== null) {
            $value = $qtyBase * $pcm;
        }
        $entry ??= new WasteEntry(['company_id' => $this->companyId, 'registered_by' => $userId, 'registered_at' => now()]);
        $entry->fill([
            'warehouse_id' => (string) $data['warehouse_id'], 'product_id' => $p['product_id'], 'code' => $p['code'], 'description' => $p['description'],
            'source_product_id' => $p['is_dish'] ? $p['product_id'] : null, 'qty' => $qty, 'unit' => $unit, 'factor' => $factor, 'qty_base' => $qtyBase,
            'reason' => $data['reason'], 'note' => trim((string) ($data['note'] ?? '')) ?: null, 'value_estimate' => $value !== null ? round($value, 4) : null,
        ])->save();

        return $entry;
    }

    /** As linhas que vão para o PingWin (os pratos já explodidos), uma por registo e componente. */
    public function documentLines(iterable $entries): array
    {
        $out = [];
        $units = new StockUnits($this->companyId);
        foreach ($entries as $e) {
            $date = substr((string) $e->registered_at, 0, 10);
            $obs = WasteEntry::REASONS[$e->reason] . ($e->reason === 'outro' && $e->note ? ": {$e->note}" : '') . ' · ' . ($e->registrant?->name ?? 'XPLENDOR');
            $parts = $e->source_product_id
                ? $this->explode((string) $e->source_product_id, (float) $e->qty_base, (string) $e->warehouse_id, $date)
                : [['product_id' => (string) $e->product_id, 'code' => $e->code, 'description' => $e->description, 'qty' => (float) $e->qty_base]];
            foreach ($parts as $p) {
                $out[] = ['entry_id' => $e->id, 'product_id' => $p['product_id'], 'code' => $p['code'], 'description' => $p['description'],
                    'qty' => round($p['qty'], 5), 'unit_id' => $units->for($p['product_id'])['stock_unit_id'], 'obs' => mb_substr($obs, 0, 200),
                    'from_dish' => $e->source_product_id ? $e->description : null];
            }
        }

        return $out;
    }

    /** Porque não se pode enviar o desperdício neste ambiente (null = pode), antes da guarda dos códigos. */
    public static function sendBlock(): ?array
    {
        return config('stock_counts.waste_send_proven') ? null
            : ['mode' => 'por_provar', 'reason' => 'O envio do desperdício está bloqueado: ainda não ficou provado no PingWin que o documento fica no armazém escolhido.'];
    }

    /** Envia os pendentes de um armazém: um documento, por ação de um gestor. */
    public function send(string $warehouseId, ?int $userId): PingwinStockWrite
    {
        if ($block = self::sendBlock()) {
            throw ValidationException::withMessages(['pingwin' => [$block['reason']]]);
        }
        $pending = WasteEntry::where('company_id', $this->companyId)->where('warehouse_id', $warehouseId)->whereNull('batch_id')->with('registrant:id,name')->orderBy('id')->get();
        if ($pending->isEmpty()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Não há desperdício por enviar neste armazém.']]);
        }
        $lines = $this->documentLines($pending);
        if (! $lines) {
            throw ValidationException::withMessages(['warehouse_id' => ['Os pratos registados não têm ficha em vigor: não há linhas para enviar.']]);
        }
        if ($guard = PingwinService::stockDocWriteGuard(array_map(fn ($l) => (string) $l['code'], $lines))) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $noUnit = array_values(array_unique(array_map(fn ($l) => $l['code'], array_filter($lines, fn ($l) => ! $l['unit_id']))));
        if ($noUnit) {
            throw ValidationException::withMessages(['pingwin' => ['Não se sabe a unidade de stock de ' . implode(', ', array_slice($noUnit, 0, 5)) . ' (o stock ainda não foi lido).']]);
        }
        $busy = PingwinStockWrite::where('company_id', $this->companyId)->whereIn('kind', ['contagem', 'desperdicio', 'anular_documento'])
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subMinutes(15))->exists();
        if ($busy) {
            throw ValidationException::withMessages(['pingwin' => ['Já há um documento de stock a ser gravado: aguarde que termine.']]);
        }

        return DB::transaction(function () use ($warehouseId, $userId, $pending, $lines) {
            $batch = WasteBatch::create(['company_id' => $this->companyId, 'warehouse_id' => $warehouseId, 'date' => now('Europe/Lisbon')->toDateString(),
                'status' => 'a_enviar', 'approved_by' => $userId, 'approved_at' => now(), 'lines' => $lines]);
            WasteEntry::whereIn('id', $pending->pluck('id'))->update(['batch_id' => $batch->id]);
            $write = PingwinStockWrite::create(['company_id' => $this->companyId, 'user_id' => $userId, 'kind' => 'desperdicio', 'warehouse_pingwin_id' => $warehouseId,
                'payload' => ['batch_id' => $batch->id, 'lines' => count($lines), 'entries' => $pending->count()], 'status' => 'queued']);
            WritePingwinWasteJob::dispatch($write->id);

            return $write;
        });
    }

    /** Anular um documento enviado (contagem ou desperdício): por ação de um gestor. */
    public function void(string $target, int $id, ?int $userId): PingwinStockWrite
    {
        $model = $target === 'contagem' ? \App\Models\StockCount::where('company_id', $this->companyId)->find($id) : WasteBatch::where('company_id', $this->companyId)->find($id);
        if (! $model || $model->status !== 'enviada' || ! $model->pingwin_doc_id) {
            throw ValidationException::withMessages(['id' => ['Só se anula um documento enviado para o PingWin.']]);
        }
        $codes = $target === 'contagem' ? $model->lines()->whereNotNull('qty_base')->pluck('code')->map(fn ($v) => (string) $v)->all() : array_map(fn ($l) => (string) $l['code'], (array) $model->lines);
        if ($guard = PingwinService::stockDocWriteGuard($codes)) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $write = PingwinStockWrite::create(['company_id' => $this->companyId, 'user_id' => $userId, 'kind' => 'anular_documento', 'warehouse_pingwin_id' => $model->warehouse_id,
            'payload' => ['target' => $target, 'id' => $id, 'doc_id' => $model->pingwin_doc_id, 'doc_number' => $model->pingwin_doc_number], 'status' => 'queued']);
        VoidPingwinStockDocumentJob::dispatch($write->id);

        return $write;
    }

    /** O relatório: desperdício por motivo, por artigo e por semana, em € (estimativa × PCM), por valor. */
    public function report(string $from, string $to, ?string $warehouseId = null): array
    {
        $rows = WasteEntry::where('company_id', $this->companyId)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereBetween('registered_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->get();
        $by = fn (callable $key, callable $label) => $rows->groupBy($key)->map(fn ($g, $k) => ['key' => (string) $k, 'label' => $label($g->first(), $k),
            'entries' => $g->count(), 'value_eur' => round((float) $g->sum('value_estimate'), 2)])->sortByDesc('value_eur')->values()->all();

        return [
            'from' => $from, 'to' => $to, 'total_eur' => round((float) $rows->sum('value_estimate'), 2), 'entries' => $rows->count(),
            'by_reason' => $by(fn ($e) => $e->reason, fn ($e) => WasteEntry::REASONS[$e->reason] ?? $e->reason),
            'by_product' => $by(fn ($e) => $e->product_id, fn ($e) => trim(($e->code ? $e->code . ' ' : '') . $e->description)),
            'by_week' => collect($by(fn ($e) => CarbonImmutable::parse($e->registered_at)->startOfWeek()->toDateString(), fn ($e, $k) => (string) $k))->sortBy('key')->values()->all(),
        ];
    }
}
