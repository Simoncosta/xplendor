<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Transfers;

use App\Models\StockTransferRequest;
use App\Models\StockTransferRoute;
use App\Services\PingwinService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 7c (SÓ LEITURA): os documentos de transferência lidos do PingWin, dos últimos 60 dias,
 * com as linhas (a quantidade, a unidade, o fator que o PingWin aplicou e a linha de origem): as
 * encomendas ENCFB e ENCF, as requisições internas antigas (só consulta, "Pedido antigo (Requisição
 * interna)") e as Guias (1102, 1007) e Entradas (1104), que a auditoria das transferências lê. "Convertida" =
 * o PingWin já tem uma guia feita a partir dela (some da lista com HIDE_CONV_DOCS=1, provado no passo 0).
 * Corre no worker (a pedido e no sync das 04:15).
 */
final class TransferOrdersService
{
    public function __construct(private PingwinService $pingwin) {}

    /** @return array{requests:int, lines:int, errors:int} */
    public function sync(int $companyId): array
    {
        $days = (int) config('stock_transfers.orders_days', 60);
        $end = CarbonImmutable::today()->addDay();
        $start = $end->subDays($days + 1);
        $orders = array_map('strval', array_keys((array) config('stock_transfers.order_docconfigs', [])));
        $legacy = array_map('strval', array_keys((array) config('stock_transfers.legacy_docconfigs', [])));
        $exits = array_map('strval', array_keys((array) config('stock_transfers.exit_docconfigs', [])));
        $entry = (string) config('stock_transfers.entry_docconfig', '1104');
        $range = ['start' => $start->toDateString(), 'end' => $end->toDateString()];

        $all = $this->pingwin->stockDocument($companyId, 'doc_list', $range + ['docconfig_ids' => array_merge($orders, $legacy, $exits, [$entry]), 'with_lines' => true], 1200);
        if (! ($all['ok'] ?? false)) {
            throw new \RuntimeException('Leitura das encomendas falhou: ' . ($all['error'] ?? 'erro desconhecido'));
        }
        $open = $this->pingwin->stockDocument($companyId, 'doc_list', $range + ['docconfig_ids' => $orders, 'hide_converted' => true], 300);
        $notConverted = ($open['ok'] ?? false) ? array_map(fn ($d) => (string) ($d['id'] ?? ''), (array) ($open['documents'] ?? [])) : null;

        $routes = StockTransferRoute::where('company_id', $companyId)->where('active', true)->get();
        $count = ['requests' => 0, 'lines' => 0, 'errors' => 0];
        foreach ((array) ($all['documents'] ?? []) as $d) {
            $id = (string) ($d['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $dc = (string) ($d['docconfig_id'] ?? '');
            $kind = match (true) {
                in_array($dc, $legacy, true) => 'requisicao',
                in_array($dc, $exits, true) => 'guia',
                $dc === $entry => 'entrada',
                default => 'encomenda',
            };
            $store = (string) ($d['store_id'] ?? '');
            $read = $d['read'] ?? null;
            DB::transaction(function () use ($companyId, $d, $id, $dc, $kind, $store, $read, $notConverted, $routes, &$count) {
                $req = StockTransferRequest::updateOrCreate(['company_id' => $companyId, 'pingwin_doc_id' => $id], [
                    'docconfig_id' => $dc, 'kind' => $kind, 'document' => $d['document'] ?? null, 'store_id' => $store ?: null,
                    'store_name' => $d['store'] ?? null, 'entity_id' => (string) ($d['entity_id'] ?? '') ?: null,
                    'entity_name' => trim((string) ($d['entity_name'] ?? '')) ?: null, 'doc_date' => self::date($d['doc_date'] ?? null),
                    'docstatus_id' => (string) ($d['docstatus_id'] ?? '') ?: null, 'employee_name' => $d['employee'] ?? null,
                    'total' => is_numeric($d['total'] ?? null) ? (float) $d['total'] : null,
                    'converted' => $kind === 'encomenda' && $notConverted !== null && ! in_array($id, $notConverted, true),
                    'route_id' => $kind === 'encomenda' ? $routes->firstWhere('dest_store_id', $store)?->id : null,
                    'lines_status' => is_array($read) ? 'ok' : (isset($d['read_error']) ? 'erro' : null),
                    'lines_error' => isset($d['read_error']) ? mb_substr((string) $d['read_error'], 0, 250) : null,
                    'synced_at' => now(),
                ]);
                $count['requests']++;
                if (! is_array($read)) {
                    $count['errors'] += isset($d['read_error']) ? 1 : 0;

                    return;
                }
                $req->lines()->delete();
                $n = 0;
                foreach ((array) ($read['details'] ?? []) as $l) {
                    if (! ($l['product_id'] ?? null)) {
                        continue;
                    }
                    $raw = (array) ($l['raw'] ?? []);
                    $req->lines()->create([
                        'line_number' => ++$n, 'line_pingwin_id' => (string) ($l['id'] ?? '') ?: null, 'product_id' => (string) $l['product_id'],
                        'code' => $l['product_code'] ?? null, 'description' => mb_substr((string) ($l['description'] ?? ''), 0, 255) ?: null,
                        'qnt' => (float) ($l['qnt'] ?? 0), 'unit_id' => (string) ($l['unit_id'] ?? '') ?: null, 'unit_desc' => $l['unit_desc'] ?? null,
                        'unit_value' => self::num($l['unit_value'] ?? $raw['unit_value'] ?? null), 'qnt_base' => self::num($l['qnt_base'] ?? $raw['qnt_base'] ?? null),
                        'qnt_delivered' => self::num($l['qnt_delivered'] ?? $raw['qnt_delivered'] ?? null), 'price' => self::num($l['price'] ?? null),
                        'warehouse_id' => (string) ($l['warehouse_id'] ?? '') ?: null,
                        'src_line_pingwin_id' => (string) ($l['src_docmovdetail_id'] ?? $raw['src_docmovdetail_id'] ?? '') ?: null,
                    ]);
                    $count['lines']++;
                }
            });
        }

        return $count;
    }

    private static function date(mixed $v): ?string
    {
        $s = (string) $v;

        return preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : (preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null);
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
