<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Transfers;

use App\Audit\Fmt;
use App\Jobs\WritePingwinTransferJob;
use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRequestLine;
use App\Models\StockTransferRoute;
use App\Services\PingwinService;
use App\Services\Restaurant\StockUnits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Fatia 7c: as transferências do Economato para os restaurantes.
 *
 * Preparar (só leitura): para cada linha, a unidade e o fator (só a unidade de stock ou uma conversão
 * confirmada na XPLENDOR; nunca o fator do PingWin), a quantidade em unidades de stock, o PCM da
 * origem (o preço do envio = PCM × fator), o último preço de compra e o stock da origem. Bloqueios:
 * sem fator confirmado, fator diferente do que o PingWin aplicou na encomenda (até se confirmar no
 * modal), unidade de stock desconhecida. Confirmação explícita: PCM 0 ou afastado do último preço de
 * compra mais do que o limite. Aviso: stock insuficiente.
 *
 * Enviar (⚠️ escreve no PingWin, um envio por ação de uma pessoa): a Guia sai na unidade de stock, com
 * o PCM, pela conversão da encomenda (P2) ou nova (P3); se o circuito recebe sozinho, a Entrada segue
 * no mesmo trabalho (P4). Nunca se reenvia sozinho.
 */
final class TransferService
{
    public function __construct(private int $companyId) {}

    /** Os circuitos da empresa (cria os iniciais da config na primeira visita). */
    public function routes(): Collection
    {
        $q = StockTransferRoute::where('company_id', $this->companyId);
        if (! (clone $q)->exists()) {
            foreach ((array) (config('stock_transfers.default_routes')[$this->companyId] ?? []) as $i => $r) {
                StockTransferRoute::create($r + ['company_id' => $this->companyId, 'position' => $i, 'exit_docconfig_id' => '1102', 'auto_receive' => true, 'active' => true]);
            }
        }

        return $q->orderBy('position')->orderBy('id')->get();
    }

    /** @return array<string, string> pingwin_id → nome do armazém */
    public function warehouseNames(): array
    {
        return PingwinWarehouse::where('company_id', $this->companyId)->pluck('description', 'pingwin_id')->map(fn ($d) => (string) $d)->all();
    }

    /**
     * As linhas de um envio com as contas e os bloqueios. $lines = [{product_id, qty, unit?, request_line_id?,
     * price_confirmed?, factor_ack?}] (unit = nome da unidade; vazio = a de stock).
     *
     * @return array{lines: list<array<string, mixed>>, total_value_eur: float, blocked: bool, needs_confirmation: bool, warnings: int}
     */
    public function prepare(StockTransferRoute $route, array $lines): array
    {
        $units = new StockUnits($this->companyId);
        $gap = (float) config('stock_transfers.price_gap_pct', 30);
        $pids = array_values(array_unique(array_map(fn ($l) => (string) ($l['product_id'] ?? ''), $lines)));
        $origin = PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $route->origin_warehouse_id)
            ->whereIn('product_pingwin_id', $pids)->get()->keyBy(fn ($s) => (string) $s->product_pingwin_id);
        $items = PingwinStockItem::where('company_id', $this->companyId)->whereIn('product_pingwin_id', $pids)->get()->keyBy(fn ($s) => (string) $s->product_pingwin_id);
        $reqLines = StockTransferRequestLine::whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['request_line_id'] ?? 0), $lines)))->get()->keyBy('id');
        $catalog = DB::table('pingwin_catalog_items')->where('company_id', $this->companyId)->whereIn('pingwin_id', $pids)->get(['pingwin_id', 'code', 'description'])->keyBy('pingwin_id');

        $out = [];
        $total = 0.0;
        foreach ($lines as $i => $l) {
            $pid = (string) ($l['product_id'] ?? '');
            $u = $units->for($pid);
            $unit = trim((string) ($l['unit'] ?? '')) ?: $u['stock_unit'];
            $factor = $units->factor($pid, $unit);
            $qty = (float) str_replace(',', '.', (string) ($l['qty'] ?? 0));
            $stock = $origin[$pid] ?? null;
            $pcm = $stock?->pcm !== null ? (float) $stock->pcm : null;
            $last = $stock?->pcu !== null && (float) $stock->pcu > 0 ? (float) $stock->pcu : null;
            $req = isset($l['request_line_id']) ? ($reqLines[(int) $l['request_line_id']] ?? null) : null;
            $pwFactor = $req && $req->unit_value !== null && mb_strtolower((string) $req->unit_desc) !== mb_strtolower($u['stock_unit']) ? (float) $req->unit_value : null;

            $blocks = [];
            if (! ($items[$pid]->stock_unit_id ?? null)) {
                $blocks[] = ['code' => 'sem_unidade_stock', 'text' => 'Não se sabe a unidade de stock deste artigo no PingWin. Leia o stock do artigo antes de enviar.'];
            }
            if ($factor === null) {
                $blocks[] = ['code' => 'fator_por_confirmar', 'text' => "A unidade «{$unit}» não tem uma conversão confirmada. Confirme " . Fmt::howMany($u['stock_unit']) . " tem cada «{$unit}»."];
            } elseif ($pwFactor !== null && abs($pwFactor - $factor) > 1e-6 && empty($l['factor_ack'])) {
                $blocks[] = ['code' => 'fator_diferente', 'text' => sprintf('O PingWin conta cada «%s» como %s; a XPLENDOR tem %s confirmado. Confirme o fator antes de enviar.',
                    $unit, Fmt::units($pwFactor, $u['stock_unit']), self::n($factor))];
            }
            if ($qty <= 0) {
                $blocks[] = ['code' => 'quantidade', 'text' => 'A quantidade tem de ser maior do que 0.'];
            }
            $qtyBase = $factor !== null ? round($qty * $factor, 5) : null;
            $confirm = null;
            if ($pcm === null || $pcm <= 0) {
                $confirm = ['code' => 'pcm_zero', 'text' => 'O preço médio (PCM) no Economato é 0: a entrada no restaurante fica sem custo.'];
            } elseif ($last !== null && abs($pcm - $last) / $last * 100 > $gap) {
                $confirm = ['code' => 'preco_afastado', 'text' => sprintf('O PCM (%s €) afasta-se %s%% do último preço de compra (%s €).', self::eur($pcm), self::n(round(abs($pcm - $last) / $last * 100, 1)), self::eur($last))];
            }
            $warning = $stock !== null && $qtyBase !== null && (float) $stock->qnt < $qtyBase
                ? ['code' => 'stock_insuficiente', 'text' => sprintf('O Economato tem %s; o envio fica com stock negativo.', Fmt::units((float) $stock->qnt, $u['stock_unit']))] : null;
            $value = $qtyBase !== null && $pcm !== null ? round($qtyBase * $pcm, 4) : null;
            $total += $value ?? 0;
            $out[] = [
                'index' => $i, 'product_id' => $pid, 'code' => $l['code'] ?? $req?->code ?? ($catalog[$pid]->code ?? null),
                'description' => $l['description'] ?? $req?->description ?? ($catalog[$pid]->description ?? null),
                'request_line_id' => $req?->id, 'qty' => $qty, 'unit' => $unit, 'stock_unit' => $u['stock_unit'], 'stock_unit_id' => $items[$pid]->stock_unit_id ?? null,
                'units' => $u['units'], 'factor' => $factor, 'pingwin_factor' => $pwFactor, 'qty_base' => $qtyBase, 'origin_pcm_eur' => $pcm,
                'price_per_unit_eur' => $pcm !== null && $factor !== null ? round($pcm * $factor, 6) : null, 'last_purchase_price_eur' => $last,
                'origin_stock' => $stock?->qnt !== null ? (float) $stock->qnt : null, 'value_eur' => $value,
                'blocks' => $blocks, 'confirm' => $confirm, 'price_confirmed' => ! empty($l['price_confirmed']), 'warning' => $warning,
            ];
        }

        return [
            'lines' => $out, 'total_value_eur' => round($total, 2),
            'blocked' => collect($out)->contains(fn ($l) => $l['blocks'] !== []),
            'needs_confirmation' => collect($out)->contains(fn ($l) => $l['confirm'] !== null && ! $l['price_confirmed']),
            'warnings' => collect($out)->filter(fn ($l) => $l['warning'] !== null)->count(),
        ];
    }

    /** Porque não se pode enviar por este circuito (null = pode). */
    public function sendBlock(StockTransferRoute $route, array $codes, bool $fromOrder): ?string
    {
        if (! config('stock_transfers.send_proven')) {
            return 'O envio de transferências para o PingWin ainda não foi provado neste ambiente (STOCK_TRANSFER_SEND_PROVEN). A preparação funciona; o envio fica bloqueado.';
        }
        if (! $fromOrder && ! config('stock_transfers.new_guide_proven')) {
            return 'O envio sem encomenda (Guia nova) ainda não foi provado no PingWin (STOCK_TRANSFER_NEW_GUIDE_PROVEN). Envie a partir de uma encomenda.';
        }
        if ((string) $route->exit_docconfig_id !== '1102') {
            return 'Só a Guia de ativos próprios (1102) foi provada. Mude o documento de saída do circuito.';
        }
        if (! $route->active) {
            return 'Este circuito está desligado.';
        }
        if (! StockTransferRoute::addressComplete($route->load_address)) {
            return 'Falta a morada de carga do circuito (o PingWin recusa a Guia sem ela). Preencha-a no separador Circuito.';
        }
        if (! StockTransferRoute::addressComplete($route->unload_address)) {
            return 'Falta a morada de descarga do circuito. Preencha-a no separador Circuito.';
        }
        if ($g = PingwinService::stockDocWriteGuard($codes)) {
            return $g['reason'];
        }

        return null;
    }

    /** Cria o envio e põe a Guia na fila (UM envio por ação). */
    public function send(StockTransferRoute $route, ?StockTransferRequest $request, array $lines, int $userId, ?string $note = null): StockTransfer
    {
        if ($request && ($request->kind !== 'encomenda' || $request->converted || $request->transfers()->whereNotIn('status', ['anulado', 'falhou_envio'])->exists())) {
            throw ValidationException::withMessages(['request_id' => ['Esta encomenda já foi enviada ou não pode ser enviada.']]);
        }
        $prep = $this->prepare($route, $lines);
        if ($prep['lines'] === []) {
            throw ValidationException::withMessages(['lines' => ['Sem linhas para enviar.']]);
        }
        if ($prep['blocked']) {
            throw ValidationException::withMessages(['lines' => ['Há linhas bloqueadas: resolva-as antes de enviar.']]);
        }
        if ($prep['needs_confirmation']) {
            throw ValidationException::withMessages(['lines' => ['Há preços por confirmar: confirme-os antes de enviar.']]);
        }
        if ($why = $this->sendBlock($route, array_map(fn ($l) => (string) $l['code'], $prep['lines']), $request !== null)) {
            throw new TransferBlocked($why);
        }
        if (PingwinStockWrite::where('company_id', $this->companyId)->whereIn('kind', ['guia_saida', 'entrada_armazem', 'anular_documento'])
            ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new TransferBlocked('Há outra transferência a gravar no PingWin. Aguarde que termine.');
        }

        return DB::transaction(function () use ($route, $request, $prep, $userId, $note) {
            $t = StockTransfer::create([
                'company_id' => $this->companyId, 'route_id' => $route->id, 'request_id' => $request?->id,
                'origin_warehouse_id' => $route->origin_warehouse_id, 'origin_store_id' => $route->origin_store_id,
                'dest_warehouse_id' => $route->dest_warehouse_id, 'dest_store_id' => $route->dest_store_id, 'exit_docconfig_id' => $route->exit_docconfig_id,
                'auto_receive' => $route->auto_receive, 'status' => 'a_enviar', 'method' => $request ? 'conversao' : 'nova',
                'total_value' => $prep['total_value_eur'], 'note' => $note ? mb_substr($note, 0, 250) : null, 'created_by' => $userId, 'sent_by' => $userId,
            ]);
            foreach ($prep['lines'] as $l) {
                $t->lines()->create([
                    'request_line_id' => $l['request_line_id'], 'product_id' => $l['product_id'], 'code' => $l['code'], 'description' => $l['description'],
                    'qnt' => $l['qty'], 'unit_id' => $l['stock_unit_id'], 'unit_desc' => $l['unit'], 'factor' => $l['factor'], 'qnt_base' => $l['qty_base'],
                    'origin_pcm' => $l['origin_pcm_eur'], 'last_purchase_price' => $l['last_purchase_price_eur'], 'price' => $l['price_per_unit_eur'],
                    'price_confirmed' => $l['price_confirmed'], 'stock_before' => $l['origin_stock'],
                ]);
            }
            $this->queue($t, 'guia_saida', $userId);

            return $t;
        });
    }

    /** Receção feita pelo restaurante (circuito sem receção automática) ou "Repetir só a entrada". */
    public function receive(StockTransfer $t, int $userId, array $received = []): PingwinStockWrite
    {
        $retry = $t->status === 'falhou_rececao';
        if (! in_array($t->status, ['enviado', 'falhou_rececao'], true) || ! $t->exit_doc_id) {
            throw ValidationException::withMessages(['status' => ['Este envio não está à espera de receção.']]);
        }
        foreach ($t->lines as $l) {
            $q = array_key_exists((string) $l->id, $received) ? (float) str_replace(',', '.', (string) $received[(string) $l->id]) : (float) $l->qnt_base;
            if ($q < 0 || $q > (float) $l->qnt_base + 1e-6) {
                throw ValidationException::withMessages(["received.{$l->id}" => ['A quantidade recebida tem de estar entre 0 e a quantidade enviada.']]);
            }
            $l->update(['received_qnt_base' => $q]);
        }
        if ($why = $this->writeGuard($t)) {
            throw new TransferBlocked($why);
        }

        return $this->queue($t, 'entrada_armazem', $userId, ['retry' => $retry]);
    }

    /** Anula no PingWin a Entrada e depois a Guia (pela ordem inversa). */
    public function void(StockTransfer $t, int $userId): PingwinStockWrite
    {
        if (! in_array($t->status, ['enviado', 'recebido', 'recebido_com_diferenca', 'falhou_rececao'], true) || ! $t->exit_doc_id) {
            throw ValidationException::withMessages(['status' => ['Este envio não tem documentos para anular.']]);
        }
        if ($why = $this->writeGuard($t)) {
            throw new TransferBlocked($why);
        }

        return $this->queue($t, 'anular_documento', $userId);
    }

    private function writeGuard(StockTransfer $t): ?string
    {
        if (! config('stock_transfers.send_proven')) {
            return 'O envio de transferências para o PingWin ainda não foi provado neste ambiente (STOCK_TRANSFER_SEND_PROVEN).';
        }
        $g = PingwinService::stockDocWriteGuard($t->lines->pluck('code')->map(fn ($c) => (string) $c)->all());

        return $g['reason'] ?? null;
    }

    private function queue(StockTransfer $t, string $kind, int $userId, array $extra = []): PingwinStockWrite
    {
        $w = PingwinStockWrite::create([
            'company_id' => $this->companyId, 'user_id' => $userId, 'kind' => $kind, 'warehouse_pingwin_id' => $kind === 'guia_saida' ? $t->origin_warehouse_id : $t->dest_warehouse_id,
            'payload' => ['transfer_id' => $t->id] + $extra, 'status' => 'queued',
        ]);
        WritePingwinTransferJob::dispatch($w->id);

        return $w;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    private static function eur(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }
}
