<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor do PingWin (F4, SÓ LEITURA).
 *
 * Âmbito: documentos FECHADOS (8002) dos tipos Fatura de fornecedor (1209), Fatura-recibo
 * compra (584955579139752236), NC (1205) e ND (1206). Anulados e rascunhos ficam de fora.
 *
 * Incremental: lê os documentos sem leitura, com a última leitura falhada, ou cujo total ou
 * estado na LISTA (F1) mudou desde a leitura (snapshot lines_synced_total_cents/_docstatus_id).
 *
 * Regras:
 *  · as linhas de um documento só são substituídas (transação: apagar + inserir) se a leitura
 *    correu bem; se falhar ficam as antigas e lines_status=failed;
 *  · conferência: Σ total das linhas = total_products − detail_discount_value e Σ IVA das
 *    linhas = total_tax (cêntimos, tolerância TOLERANCE_CENTS); se divergir lines_check=diff com
 *    a diferença — não bloqueia. ⚠️ O total_products do PingWin é BRUTO (Σ qnt × preço, antes
 *    dos descontos de linha) e as linhas vêm LÍQUIDAS: provado em 11/11 documentos com desconto
 *    (Σ linhas = subtotal = total_products − detail_discount_value, ao cêntimo). O total do
 *    documento = Σ linhas + IVA + adjustment (acerto de cêntimos para bater com o fornecedor).
 */
class SupplierDocumentLinesService
{
    public const TYPES = ['1209', '584955579139752236', '1205', '1206'];
    public const STATUS_CLOSED = '8002';
    public const BATCH_SIZE = 50;
    public const TOLERANCE_CENTS = 1;
    public const NIGHTLY_MAX_DOCS = 300; // teto por madrugada (~7 min); o backfill grande corre à mão

    public function __construct(private readonly PingwinService $pingwin) {}

    /**
     * Documentos a ler. $opts: from/to (doc_date), doc (docheader_id ou nº "VFT BOVFT/1020" —
     * força a releitura), limit. $notAttemptedSince exclui os que já falharam neste ciclo
     * (para um ciclo nunca repetir em loop o mesmo documento falhado).
     */
    public function candidates(int $companyId, array $opts = [], ?\DateTimeInterface $notAttemptedSince = null): Builder
    {
        $q = PingwinSupplierDocument::where('company_id', $companyId)
            ->whereIn('docconfig_id', self::TYPES)
            ->where('docstatus_id', self::STATUS_CLOSED)
            ->when($opts['from'] ?? null, fn ($q, $v) => $q->where('doc_date', '>=', $v))
            ->when($opts['to'] ?? null, fn ($q, $v) => $q->where('doc_date', '<=', $v));

        if (! empty($opts['doc'])) {
            return $q->where(fn ($w) => $w->where('docheader_id', $opts['doc'])->orWhere('document', $opts['doc']));
        }

        return $q->where(fn ($w) => $w->whereNull('lines_synced_at')
                ->orWhere('lines_status', 'failed')
                ->orWhereNull('lines_synced_total_cents')
                ->orWhereColumn('total_cents', '!=', 'lines_synced_total_cents')
                ->orWhereNull('lines_synced_docstatus_id')
                ->orWhereColumn('docstatus_id', '!=', 'lines_synced_docstatus_id'))
            ->when($notAttemptedSince, fn ($q, $since) => $q->where(fn ($w) => $w->where('lines_status', '!=', 'failed')
                ->orWhereNull('lines_status')
                ->orWhere('updated_at', '<', $since)))
            ->orderByDesc('doc_date')->orderByDesc('id');
    }

    /**
     * Sincroniza em lotes de BATCH_SIZE até esgotar os candidatos (ou $opts['limit']).
     * $progress(array $batchStats) é chamado após cada lote (comando).
     */
    public function sync(int $companyId, array $opts = [], ?callable $progress = null): array
    {
        $started = now();
        $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
        $total = $this->emptyStats();

        while (true) {
            $take = $limit === null ? self::BATCH_SIZE : min(self::BATCH_SIZE, $limit - $total['docs']);
            if ($take <= 0) {
                break;
            }
            $batch = $this->candidates($companyId, $opts, $started)->limit($take)->get();
            if ($batch->isEmpty()) {
                break;
            }
            $stats = $this->syncBatch($companyId, $batch->all());
            $total = $this->addStats($total, $stats);
            if ($progress) {
                $progress($stats, $total);
            }
            if (! empty($opts['doc'])) {
                break; // --doc: um só passo (o filtro não é incremental)
            }
        }

        return $total;
    }

    /** Lê e grava UM lote (um docker exec). Uma falha do lote inteiro marca todos como failed. */
    public function syncBatch(int $companyId, array $docs): array
    {
        $stats = $this->emptyStats();
        $t0 = microtime(true);
        $request = array_map(fn (PingwinSupplierDocument $d) => ['docconfig_id' => $d->docconfig_id, 'docheader_id' => $d->docheader_id], $docs);

        try {
            $results = collect($this->pingwin->fetchSupplierDocumentLines($companyId, $request))->keyBy(fn ($r) => (string) ($r['docheader_id'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('[PingWin Linhas] lote falhou', ['company_id' => $companyId, 'docs' => count($docs), 'error' => $e->getMessage()]);
            $results = collect();
            $batchError = $e->getMessage();
        }

        foreach ($docs as $doc) {
            $res = $results->get((string) $doc->docheader_id)
                ?? ['ok' => false, 'error' => $batchError ?? 'sem resposta para este documento no lote'];
            $outcome = $this->apply($doc, $res);
            $stats['docs']++;
            $stats[$outcome]++;
            if ($outcome !== 'failed' && $doc->lines_check === 'diff') {
                $stats['diff']++;
            }
            if (($res['ok'] ?? false) && ($res['closed'] ?? true) === false) {
                $stats['not_closed']++;
            }
            $stats['lines'] += $outcome === 'failed' ? 0 : count($res['details'] ?? []);
        }
        $stats['ms'] = (int) round((microtime(true) - $t0) * 1000);

        return $stats;
    }

    /** Aplica o resultado de UM documento. Devolve 'ok' ou 'failed'. */
    public function apply(PingwinSupplierDocument $doc, array $res): string
    {
        if (! ($res['ok'] ?? false)) {
            $this->markFailed($doc, (string) ($res['error'] ?? 'erro desconhecido'));

            return 'failed';
        }

        try {
            $h = (array) ($res['header'] ?? []);
            $rows = $this->lineRows($doc, (array) ($res['details'] ?? []));
            $linesTotal = array_sum(array_column($rows, 'total_cents'));
            $linesTax = array_sum(array_column($rows, 'tax_value_cents'));
            $productsCents = self::cents($h['total_products'] ?? null);
            $taxCents = self::cents($h['total_tax'] ?? null);
            $detailDiscount = self::cents($h['detail_discount_value'] ?? null) ?? 0;
            $diff = $productsCents === null ? null : $linesTotal - ($productsCents - $detailDiscount);
            $taxDiff = $taxCents === null ? null : $linesTax - $taxCents;
            $check = ($diff !== null && abs($diff) <= self::TOLERANCE_CENTS && $taxDiff !== null && abs($taxDiff) <= self::TOLERANCE_CENTS) ? 'ok' : 'diff';

            DB::transaction(function () use ($doc, $rows, $h, $productsCents, $taxCents, $detailDiscount, $diff, $taxDiff, $check) {
                PingwinSupplierDocumentLine::where('company_id', $doc->company_id)->where('docheader_id', $doc->docheader_id)->delete();
                foreach (array_chunk($rows, 200) as $chunk) {
                    PingwinSupplierDocumentLine::insert($chunk);
                }
                $doc->update([
                    'due_date'                  => self::date($h['due_date'] ?? null),
                    'paycond_pingwin_id'        => self::str($h['paycond_id'] ?? null),
                    'docreference_date'         => self::date($h['docreference_date'] ?? null),
                    'total_products_cents'      => $productsCents,
                    'total_tax_cents'           => $taxCents,
                    'detail_discount_cents'     => $detailDiscount,
                    'adjustment_cents'          => self::cents($h['adjustment'] ?? null),
                    'discount1'                 => self::dec($h['discount1'] ?? null, 4),
                    'discount2'                 => self::dec($h['discount2_add'] ?? null, 4),
                    'shipping_cents'            => self::cents($h['shipping_value'] ?? null),
                    'withholding_cents'         => self::cents($h['withholding'] ?? null),
                    'lines_synced_at'           => now(),
                    'lines_status'              => 'ok',
                    'lines_error'               => null,
                    'lines_check'               => $check,
                    'lines_diff_cents'          => $diff,
                    'lines_tax_diff_cents'      => $taxDiff,
                    'lines_synced_total_cents'  => $doc->total_cents,
                    'lines_synced_docstatus_id' => $doc->docstatus_id,
                ]);
            });
        } catch (\Throwable $e) {
            // Nada foi substituído (transação) — ficam as linhas antigas.
            Log::warning('[PingWin Linhas] gravação falhou', ['docheader_id' => $doc->docheader_id, 'error' => $e->getMessage()]);
            $doc->refresh();
            $this->markFailed($doc, 'Gravação: ' . $e->getMessage());

            return 'failed';
        }

        return 'ok';
    }

    /** Linhas do PingWin → linhas da tabela (preço com 6 casas; totais em cêntimos). */
    private function lineRows(PingwinSupplierDocument $doc, array $details): array
    {
        $productIds = array_values(array_filter(array_map(fn ($d) => self::str($d['product_id'] ?? null), $details)));
        $articles = $productIds === [] ? [] : PingwinCatalogItem::where('company_id', $doc->company_id)
            ->whereIn('pingwin_id', $productIds)->pluck('id', 'pingwin_id')->all();

        $now = now();
        $rows = [];
        foreach ($details as $d) {
            $n = $d['line_number'] ?? null;
            if (! is_numeric($n)) {
                throw new \RuntimeException('linha sem line_number');
            }
            $n = (int) $n;
            if (isset($rows[$n])) {
                throw new \RuntimeException("line_number {$n} repetido no documento");
            }
            $product = self::str($d['product_id'] ?? null);
            $rows[$n] = [
                'company_id'           => $doc->company_id,
                'docheader_id'         => $doc->docheader_id,
                'line_number'          => $n,
                'line_pingwin_id'      => self::str($d['id'] ?? null),
                'product_pingwin_id'   => $product,
                'product_code'         => self::str($d['product_code'] ?? null),
                'article_id'           => $product !== null ? ($articles[$product] ?? null) : null,
                'supplier_code'        => self::str($d['entity_product_id'] ?? null),
                'description'          => mb_substr((string) ($d['description'] ?? ''), 0, 255) ?: null,
                'qnt'                  => self::dec($d['qnt'] ?? null, 6),
                'unit_code'            => self::str($d['unit_code'] ?? null),
                'unit_desc'            => self::str($d['unit_desc'] ?? null),
                'price'                => self::dec($d['price'] ?? null, 6),
                'price_w_tax'          => self::dec($d['price_w_tax'] ?? null, 6),
                'discount1'            => self::dec($d['discount1'] ?? null, 4),
                'total_cents'          => (int) self::cents($d['total'] ?? 0),
                'tax_value_cents'      => (int) self::cents($d['tax_value'] ?? 0),
                'total_w_tax_cents'    => (int) self::cents($d['total_w_tax'] ?? 0),
                'taxgroup_id'          => self::str($d['taxgroup_id'] ?? null),
                'tax_description'      => self::str($d['tax_description'] ?? null),
                'warehouse_pingwin_id' => self::str($d['warehouse_id'] ?? null),
                'raw'                  => json_encode($d['raw'] ?? $d, JSON_UNESCAPED_UNICODE),
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * Falha: as linhas antigas ficam; marca failed. updated_at é SEMPRE tocado (mesmo com o
     * mesmo erro) — é o que impede o ciclo de repetir o documento (candidates $notAttemptedSince).
     */
    private function markFailed(PingwinSupplierDocument $doc, string $error): void
    {
        $doc->forceFill(['lines_status' => 'failed', 'lines_error' => mb_substr($error, 0, 500), 'updated_at' => now()])->save();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function emptyStats(): array
    {
        return ['docs' => 0, 'ok' => 0, 'failed' => 0, 'diff' => 0, 'not_closed' => 0, 'lines' => 0, 'ms' => 0];
    }

    private function addStats(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }

        return $a;
    }

    private static function str($v): ?string
    {
        return ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
    }

    /** € → cêntimos inteiros (null se vazio/não numérico). */
    private static function cents($v): ?int
    {
        return is_numeric($v) ? (int) round(((float) $v) * 100) : null;
    }

    /** Decimal com N casas como STRING (sem passar a cêntimos; precisão total até N casas). */
    private static function dec($v, int $places): ?string
    {
        if (! is_numeric($v)) {
            return null;
        }
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            [$int, $frac] = array_pad(explode('.', $v), 2, '');
            if (strlen($frac) <= $places) {
                return $frac === '' ? $int : "{$int}.{$frac}";
            }
        }

        return rtrim(rtrim(number_format((float) $v, $places, '.', ''), '0'), '.');
    }

    /** "20261104T00:00:00" → "2026-11-04"; "" → null. */
    private static function date($v): ?string
    {
        return preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', trim((string) $v), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
