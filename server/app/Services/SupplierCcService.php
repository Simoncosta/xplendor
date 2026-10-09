<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncPingwinSupplierCcJob;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierCcBalance;
use App\Models\PingwinSupplierCcDocument;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1): sync de LEITURA do PingWin + cálculos.
 *
 * Regras (spike de 2026-10-09, provadas em 184 fornecedores da Yuko):
 *   · ccbalance do PingWin == Σ topay × ca_signal de todos os documentos.
 *   · Auto-pago = docconfig com settled=1 ("Pago") no espelho. O PingWin conta-os como
 *     dívida (é o erro do saldo do BO); o saldo real exclui-os.
 *   · Docconfig fora do espelho conta como NÃO auto-pago (conservador, igual ao PingWin).
 * Valores sempre em cêntimos int.
 */
class SupplierCcService
{
    /** Fornecedores por docker exec (cabe folgado nos 180 s do invoke: ~0,3 s cada). */
    public const BATCH_SIZE = 40;

    public function __construct(private readonly PingwinService $pingwin) {}

    // ─────────────────────────────────────────────────────────────────────────
    // SYNC
    // ─────────────────────────────────────────────────────────────────────────

    /** Fornecedores PingWin da empresa (com pingwin_id), opcionalmente só os ids locais dados. */
    public function suppliersFor(int $companyId, ?array $supplierIds = null): Collection
    {
        return PingwinSupplier::where('company_id', $companyId)
            ->whereNotNull('pingwin_id')->where('pingwin_id', '!=', '')
            ->when($supplierIds !== null, fn ($q) => $q->whereIn('id', $supplierIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * Sincroniza a conta corrente dos fornecedores (todos ou os dados), em lotes de
     * BATCH_SIZE por docker exec. Um fornecedor que falhe não trava os outros.
     *
     * @return array{suppliers:int, ok:int, failed:int, documents:int, reconciled:int, seconds:float, errors:array<int,string>}
     */
    public function sync(int $companyId, ?array $supplierIds = null): array
    {
        $t0 = microtime(true);
        $suppliers = $this->suppliersFor($companyId, $supplierIds);
        $summary = ['suppliers' => $suppliers->count(), 'ok' => 0, 'failed' => 0, 'documents' => 0,
                    'reconciled' => 0, 'seconds' => 0.0, 'errors' => []];

        foreach ($suppliers->chunk(self::BATCH_SIZE) as $batch) {
            $byEntity = $batch->keyBy(fn (PingwinSupplier $s) => (string) $s->pingwin_id);
            foreach ($batch as $s) {
                $this->setStatus($companyId, $s, PingwinSupplierCcBalance::STATUS_RUNNING);
            }

            try {
                $results = $this->pingwin->fetchSupplierCc($companyId, $byEntity->keys()->all());
            } catch (\Throwable $e) {
                // Falha do lote inteiro (login/docker): nada é tocado nos documentos.
                foreach ($batch as $s) {
                    $this->markFailed($companyId, $s, 'lote falhou: ' . $e->getMessage());
                    $summary['failed']++;
                    $summary['errors'][$s->id] = 'lote falhou: ' . mb_substr($e->getMessage(), 0, 150);
                }
                continue;
            }

            $answered = [];
            foreach ($results as $res) {
                $eid = (string) ($res['entity_id'] ?? '');
                $supplier = $byEntity->get($eid);
                if (! $supplier) {
                    continue;
                }
                $answered[$eid] = true;
                $out = $this->persistSupplier($companyId, $supplier, $res);
                if ($out['status'] === PingwinSupplierCcBalance::STATUS_OK) {
                    $summary['ok']++;
                    $summary['documents'] += $out['documents'];
                    $summary['reconciled'] += $out['reconciled'] ? 1 : 0;
                } else {
                    $summary['failed']++;
                    $summary['errors'][$supplier->id] = (string) $out['error'];
                }
            }
            foreach ($byEntity as $eid => $s) {
                if (! isset($answered[$eid])) {
                    $this->markFailed($companyId, $s, 'sem resposta do PingWin para este fornecedor');
                    $summary['failed']++;
                    $summary['errors'][$s->id] = 'sem resposta do PingWin para este fornecedor';
                }
            }
        }

        $summary['seconds'] = round(microtime(true) - $t0, 1);
        Log::info('[PingWin CC] sync concluída', ['company_id' => $companyId] + array_diff_key($summary, ['errors' => 1]));

        return $summary;
    }

    /**
     * Persiste a resposta de UM fornecedor, com as guardas:
     *   · fetch falhado → não toca nos documentos; failed + last_error;
     *   · lista vazia com documentos locais > 0 → suspeito; NÃO apaga; failed;
     *   · reconciled = (Σ topay_cents × ca_signal == balance_cents), exato em cêntimos.
     *
     * @return array{status:string, documents:int, reconciled:bool, error:?string}
     */
    public function persistSupplier(int $companyId, PingwinSupplier $supplier, array $res): array
    {
        if (! ($res['ok'] ?? false)) {
            $error = (string) ($res['error'] ?? 'erro desconhecido');
            $this->markFailed($companyId, $supplier, $error);

            return ['status' => PingwinSupplierCcBalance::STATUS_FAILED, 'documents' => 0, 'reconciled' => false, 'error' => $error];
        }

        $docs = array_values(array_filter($res['documents'] ?? [], fn ($d) => is_array($d) && (string) ($d['docheader_id'] ?? '') !== ''));
        $localCount = PingwinSupplierCcDocument::where('company_id', $companyId)->where('supplier_id', $supplier->id)->count();
        if ($docs === [] && $localCount > 0) {
            $error = "lista vazia suspeita ({$localCount} documento(s) locais mantidos)";
            $this->markFailed($companyId, $supplier, $error);

            return ['status' => PingwinSupplierCcBalance::STATUS_FAILED, 'documents' => 0, 'reconciled' => false, 'error' => $error];
        }

        $now = now();
        $rows = array_map(fn (array $d) => $this->documentRow($companyId, $supplier, $d, $now), $docs);
        $balanceRaw = $res['balance']['balance'] ?? null;
        $balanceCents = is_numeric($balanceRaw) ? self::cents($balanceRaw) : null;
        $sumCents = array_sum(array_map(fn ($r) => $r['topay_cents'] * $r['ca_signal'], $rows));
        $reconciled = $balanceCents !== null && $sumCents === $balanceCents;

        DB::transaction(function () use ($companyId, $supplier, $rows, $balanceCents, $reconciled, $now) {
            $updateCols = $rows ? array_values(array_diff(array_keys($rows[0]), ['company_id', 'docheader_id', 'created_at'])) : [];
            foreach (array_chunk($rows, 200) as $chunk) {
                PingwinSupplierCcDocument::upsert($chunk, ['company_id', 'docheader_id'], $updateCols);
            }
            PingwinSupplierCcDocument::where('company_id', $companyId)
                ->where('supplier_id', $supplier->id)
                ->whereNotIn('docheader_id', array_column($rows, 'docheader_id'))
                ->delete();

            PingwinSupplierCcBalance::updateOrCreate(
                ['company_id' => $companyId, 'supplier_id' => $supplier->id],
                [
                    'entity_pingwin_id' => (string) $supplier->pingwin_id,
                    'balance_cents'     => $balanceCents,
                    'reconciled'        => $reconciled,
                    'sync_status'       => PingwinSupplierCcBalance::STATUS_OK,
                    'last_error'        => null,
                    'synced_at'         => $now,
                ]
            );
        });

        if (! $reconciled) {
            Log::warning('[PingWin CC] fornecedor NÃO reconciliado', [
                'company_id'     => $companyId,
                'supplier_id'    => $supplier->id,
                'entity_id'      => $supplier->pingwin_id,
                'balance_cents'  => $balanceCents,
                'sum_cents'      => $sumCents,
                'diff_cents'     => $balanceCents === null ? null : $sumCents - $balanceCents,
            ]);
        }

        return ['status' => PingwinSupplierCcBalance::STATUS_OK, 'documents' => count($rows), 'reconciled' => $reconciled, 'error' => null];
    }

    /**
     * Pede a atualização de UM fornecedor (usado pela página da S2): estado "queued" e
     * job no worker. O progresso lê-se em pingwin_supplier_cc_balances.sync_status.
     */
    public function requestRefresh(int $companyId, int $supplierId): PingwinSupplierCcBalance
    {
        $supplier = $this->suppliersFor($companyId, [$supplierId])->first();
        if (! $supplier) {
            throw new \InvalidArgumentException("Fornecedor {$supplierId} não é um fornecedor PingWin da empresa {$companyId}.");
        }
        $balance = $this->setStatus($companyId, $supplier, PingwinSupplierCcBalance::STATUS_QUEUED);
        SyncPingwinSupplierCcJob::dispatch($companyId, [$supplier->id]);

        return $balance;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CÁLCULOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Saldo PingWin, Saldo real, Diferença (auto-pagos contados como dívida) e Vencido.
     *
     * @return array{pingwin_balance_cents:?int, real_balance_cents:int, difference_cents:int, overdue_cents:int, reconciled:bool, sync_status:?string, synced_at:?CarbonInterface}
     */
    public function balances(int $companyId, int $supplierId, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $docs = $this->documents($companyId, $supplierId);
        $auto = $this->autoPaidIds($companyId, $docs);
        $bal = PingwinSupplierCcBalance::where('company_id', $companyId)->where('supplier_id', $supplierId)->first();
        $f = $this->figures($docs, $auto, $today);

        return [
            'pingwin_balance_cents' => $bal?->balance_cents,
            'real_balance_cents'    => $f['real_balance_cents'],
            'difference_cents'      => $f['difference_cents'],
            'overdue_cents'         => $f['overdue_cents'],
            'reconciled'            => (bool) $bal?->reconciled,
            'sync_status'           => $bal?->sync_status,
            'synced_at'             => $bal?->synced_at,
        ];
    }

    /**
     * VISÃO GERAL (S2): uma linha por fornecedor PingWin + os cartões. Mesmas contas que
     * balances() (figures() é a única fonte). Sinal: positivo = em dívida ao fornecedor.
     *
     * @return array{cards: array<string,int>, suppliers: list<array<string,mixed>>}
     */
    public function overview(int $companyId, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $allDocs = PingwinSupplierCcDocument::where('company_id', $companyId)->get();
        $auto = $this->autoPaidIds($companyId, $allDocs);
        $docsBySupplier = $allDocs->groupBy('supplier_id');
        $balances = PingwinSupplierCcBalance::where('company_id', $companyId)->get()->keyBy('supplier_id');

        $cards = ['real_balance_cents' => 0, 'overdue_cents' => 0, 'difference_cents' => 0, 'pingwin_balance_cents' => 0, 'problem_suppliers' => 0];
        $rows = [];
        foreach ($this->suppliersFor($companyId) as $s) {
            $f = $this->figures($docsBySupplier->get($s->id, collect()), $auto, $today);
            $bal = $balances->get($s->id);
            $status = self::status($bal);
            $rows[] = [
                'supplier_id'           => $s->id,
                'code'                  => $s->code,
                'name'                  => $s->name,
                'tax_number'            => $s->tax_number,
                'pingwin_balance_cents' => $bal?->balance_cents,
                'real_balance_cents'    => $f['real_balance_cents'],
                'difference_cents'      => $f['difference_cents'],
                'overdue_cents'         => $f['overdue_cents'],
                'open_docs'             => $f['open_docs'],
                'next_due_date'         => $f['next_due_date'],
                'status'                => $status,
                'sync_status'           => $bal?->sync_status,
                'last_error'            => $bal?->last_error,
                'synced_at'             => $bal?->synced_at?->toIso8601String(),
            ];
            $cards['real_balance_cents'] += $f['real_balance_cents'];
            $cards['overdue_cents'] += $f['overdue_cents'];
            $cards['difference_cents'] += $f['difference_cents'];
            $cards['pingwin_balance_cents'] += (int) ($bal?->balance_cents ?? 0);
            $cards['problem_suppliers'] += in_array($status, ['failed', 'not_reconciled'], true) ? 1 : 0;
        }

        return ['cards' => $cards, 'suppliers' => $rows];
    }

    /**
     * FORNECEDOR (S2): cabeçalho + "Por liquidar" = documentos de docconfigs NÃO auto-pagos
     * com topay ≠ 0. Por liquidar = topay × ca_signal (as NC a abater). Ordem: data, documento.
     *
     * @return array{supplier: array<string,mixed>, open: list<array<string,mixed>>, open_total_cents: int}
     */
    public function supplierDetail(int $companyId, int $supplierId, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $s = $this->suppliersFor($companyId, [$supplierId])->firstOrFail();
        $docs = $this->documents($companyId, $supplierId);
        $auto = $this->autoPaidIds($companyId, $docs);
        $bal = PingwinSupplierCcBalance::where('company_id', $companyId)->where('supplier_id', $supplierId)->first();
        $f = $this->figures($docs, $auto, $today);

        $open = $docs->filter(fn ($d) => ! isset($auto[$d->docconfig_id]) && $d->topay_cents !== 0)
            ->sortBy(fn ($d) => ($d->doc_date?->format('Y-m-d') ?? '0000-00-00') . '|' . $d->document)
            ->map(function (PingwinSupplierCcDocument $d) use ($today) {
                $openCents = $d->topay_cents * $d->ca_signal;
                $overdue = $d->due_date !== null && $d->topay_cents > 0 && $d->due_date->lt($today);

                return [
                    'docheader_id'        => $d->docheader_id,
                    'doc_date'            => $d->doc_date?->toDateString(),
                    'document'            => $d->document,
                    'doctype'             => $d->doctype,
                    'docreference_number' => $d->docreference_number,
                    'docreference_date'   => self::date(($d->raw ?? [])['docreference_date'] ?? null),
                    'due_date'            => $d->due_date?->toDateString(),
                    'due_is_doc_date'     => $d->due_date !== null && $d->doc_date !== null && $d->due_date->equalTo($d->doc_date),
                    'days_overdue'        => $overdue ? (int) $d->due_date->diffInDays($today) : 0,
                    'total_cents'         => $d->total_cents,
                    'ca_signal'           => $d->ca_signal,
                    'open_cents'          => $openCents,
                    'store_name'          => $d->store_name,
                ];
            })->values()->all();

        return [
            'supplier' => [
                'supplier_id'           => $s->id,
                'code'                  => $s->code,
                'name'                  => $s->name,
                'tax_number'            => $s->tax_number,
                'pingwin_balance_cents' => $bal?->balance_cents,
                'real_balance_cents'    => $f['real_balance_cents'],
                'difference_cents'      => $f['difference_cents'],
                'overdue_cents'         => $f['overdue_cents'],
                'open_docs'             => $f['open_docs'],
                'next_due_date'         => $f['next_due_date'],
                'status'                => self::status($bal),
                'sync_status'           => $bal?->sync_status,
                'last_error'            => $bal?->last_error,
                'synced_at'             => $bal?->synced_at?->toIso8601String(),
            ],
            'open'             => $open,
            'open_total_cents' => array_sum(array_column($open, 'open_cents')),
        ];
    }

    /** Lojas presentes nos documentos do fornecedor (filtro do extrato). */
    public function stores(int $companyId, int $supplierId): array
    {
        return PingwinSupplierCcDocument::where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereNotNull('store_pingwin_id')
            ->select('store_pingwin_id', DB::raw('MAX(store_name) as store_name'))
            ->groupBy('store_pingwin_id')->orderBy('store_name')->get()
            ->map(fn ($r) => ['id' => $r->store_pingwin_id, 'name' => $r->store_name])->values()->all();
    }

    /**
     * Contas de um fornecedor a partir dos seus documentos — ÚNICA fonte (balances(),
     * overview() e supplierDetail() usam-na, por isso os totais coincidem por construção).
     * Próximo vencimento = o mais cedo ≥ hoje entre as dívidas em aberto (não auto-pagas).
     *
     * @return array{real_balance_cents:int, difference_cents:int, overdue_cents:int, open_docs:int, next_due_date:?string}
     */
    private function figures(iterable $docs, array $auto, CarbonImmutable $today): array
    {
        $real = $difference = $overdue = $open = 0;
        $nextDue = null;
        foreach ($docs as $d) {
            if (isset($auto[$d->docconfig_id])) {
                if ($d->ca_signal === 1) {
                    $difference += $d->topay_cents;
                }
                continue;
            }
            $real += $d->topay_cents * $d->ca_signal;
            if ($d->topay_cents !== 0) {
                $open++;
            }
            if ($d->topay_cents > 0 && $d->due_date !== null) {
                if ($d->due_date->lt($today)) {
                    $overdue += $d->topay_cents * $d->ca_signal;
                } elseif ($d->ca_signal === 1 && ($nextDue === null || $d->due_date->lt($nextDue))) {
                    $nextDue = $d->due_date;
                }
            }
        }

        return [
            'real_balance_cents' => $real,
            'difference_cents'   => $difference,
            'overdue_cents'      => $overdue,
            'open_docs'          => $open,
            'next_due_date'      => $nextDue?->toDateString(),
        ];
    }

    /** Estado para a UI: never | syncing | failed | not_reconciled | ok. */
    private static function status(?PingwinSupplierCcBalance $bal): string
    {
        return match (true) {
            $bal === null => 'never',
            in_array($bal->sync_status, [PingwinSupplierCcBalance::STATUS_QUEUED, PingwinSupplierCcBalance::STATUS_RUNNING], true) => 'syncing',
            $bal->sync_status === PingwinSupplierCcBalance::STATUS_FAILED => 'failed',
            ! $bal->reconciled => 'not_reconciled',
            default => 'ok',
        };
    }

    /**
     * EXTRATO de um fornecedor em [from, to] (datas Y-m-d; null = sem limite).
     * Linhas: Loja, Data, Documento, Doc. Fornecedor, Débito, Crédito, Saldo acumulado,
     * Data vencimento. ca_signal=1 → Crédito=total; ca_signal=-1 → Débito=total; auto-pago
     * (settled=1, ca_signal=1) com topay>0 → Débito=topay no próprio documento ("pago no
     * ato"). Saldo acumulado = Crédito − Débito (positivo = em dívida). Saldo anterior =
     * movimentos antes de `from`. Ordem: doc_date, document. `store` (store_pingwin_id)
     * restringe o extrato a uma loja (o saldo anterior também passa a ser só dessa loja).
     *
     * @return array{opening_cents:int, lines:list<array<string,mixed>>, total_debit_cents:int, total_credit_cents:int, closing_cents:int}
     */
    public function statement(int $companyId, int $supplierId, ?string $from = null, ?string $to = null, ?string $store = null): array
    {
        $docs = $this->documents($companyId, $supplierId)
            ->when($store !== null && $store !== '', fn ($c) => $c->where('store_pingwin_id', $store))
            ->sortBy(fn (PingwinSupplierCcDocument $d) => ($d->doc_date?->format('Y-m-d') ?? '0000-00-00') . '|' . $d->document)
            ->values();
        $auto = $this->autoPaidIds($companyId, $docs);

        $opening = 0;
        $lines = [];
        $totalDebit = $totalCredit = 0;
        $running = null;
        foreach ($docs as $d) {
            [$debit, $credit] = $this->movement($d, isset($auto[$d->docconfig_id]));
            $date = $d->doc_date?->format('Y-m-d');
            if ($from !== null && ($date === null || $date < $from)) {
                $opening += $credit - $debit;
                continue;
            }
            if ($to !== null && $date !== null && $date > $to) {
                continue;
            }
            $running = ($running ?? $opening) + $credit - $debit;
            $totalDebit += $debit;
            $totalCredit += $credit;
            $lines[] = [
                'store'          => $d->store_name,
                'date'           => $date,
                'document'       => $d->document,
                'supplier_doc'   => $d->docreference_number,
                'debit_cents'    => $debit,
                'credit_cents'   => $credit,
                'balance_cents'  => $running,
                'due_date'       => $d->due_date?->format('Y-m-d'),
                'auto_paid'      => isset($auto[$d->docconfig_id]),
                // "pago no ato": SÓ a fatura auto-paga com valor (débito = crédito no próprio
                // documento). Uma NL também é auto-paga (settled=1) mas é a liquidação em si.
                'paid_on_issue'  => isset($auto[$d->docconfig_id]) && $d->ca_signal === 1 && $d->topay_cents > 0,
                'docheader_id'   => $d->docheader_id,
            ];
        }

        return [
            'opening_cents'      => $opening,
            'lines'              => $lines,
            'total_debit_cents'  => $totalDebit,
            'total_credit_cents' => $totalCredit,
            'closing_cents'      => $opening + $totalCredit - $totalDebit,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────────────────

    /** [débito, crédito] de um documento no extrato. */
    private function movement(PingwinSupplierCcDocument $d, bool $autoPaid): array
    {
        if ($d->ca_signal === -1) {
            return [$d->total_cents, 0];
        }
        $debit = ($autoPaid && $d->topay_cents > 0) ? $d->topay_cents : 0;

        return [$debit, $d->total_cents];
    }

    private function documents(int $companyId, int $supplierId): Collection
    {
        return PingwinSupplierCcDocument::where('company_id', $companyId)->where('supplier_id', $supplierId)->get();
    }

    /**
     * docconfig_id auto-pagos (settled=1) presentes nos documentos, como mapa id => true.
     * Docconfigs que não estão no espelho contam como NÃO auto-pagos e ficam no log.
     */
    private function autoPaidIds(int $companyId, Collection $docs): array
    {
        $ids = $docs->pluck('docconfig_id')->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $configs = PingwinDocumentConfig::where('company_id', $companyId)->whereIn('external_id', $ids)
            ->get(['external_id', 'settled']);
        $missing = array_values(array_diff($ids, $configs->pluck('external_id')->all()));
        if ($missing !== []) {
            Log::info('[PingWin CC] docconfig fora do espelho — tratado como NÃO auto-pago', [
                'company_id' => $companyId, 'docconfig_ids' => $missing,
            ]);
        }

        return $configs->where('settled', true)->pluck('external_id')->flip()->map(fn () => true)->all();
    }

    private function documentRow(int $companyId, PingwinSupplier $supplier, array $d, CarbonInterface $now): array
    {
        $str = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        return [
            'company_id'            => $companyId,
            'supplier_id'           => $supplier->id,
            'entity_pingwin_id'     => (string) $supplier->pingwin_id,
            'docheader_id'          => (string) $d['docheader_id'],
            'docconfig_id'          => (string) ($d['docconfig_id'] ?? ''),
            'doctype'               => $str($d['doctype'] ?? null),
            'document'              => $str(isset($d['document']) ? trim((string) $d['document']) : null),
            'doc_date'              => self::date($d['doc_date'] ?? null),
            'due_date'              => self::date($d['due_date'] ?? null),
            'fiscal_date'           => self::date($d['fiscal_date'] ?? null),
            'total_cents'           => self::cents($d['total'] ?? 0),
            'total_paid_cents'      => self::cents($d['total_paid'] ?? 0),
            'topay_cents'           => self::cents($d['topay'] ?? 0),
            'suspended_cents'       => self::cents($d['suspended_value'] ?? 0),
            'paid'                  => (int) ($d['paid'] ?? 0) === 1,
            'ca_signal'             => (int) ($d['ca_signal'] ?? 1) === -1 ? -1 : 1,
            'iscredit'              => (int) ($d['iscredit'] ?? 0) === 1,
            'isdebit'               => (int) ($d['isdebit'] ?? 0) === 1,
            'docstatus_description' => $str($d['docstatus_description'] ?? null),
            'docreference_number'   => $str(isset($d['docreference_number']) ? trim((string) $d['docreference_number']) : null),
            'store_pingwin_id'      => $str($d['docheader_store_id'] ?? null),
            'store_name'            => $str($d['store'] ?? null),
            'raw'                   => json_encode($d, JSON_UNESCAPED_UNICODE),
            'synced_at'             => $now,
            'created_at'            => $now,
            'updated_at'            => $now,
        ];
    }

    private function setStatus(int $companyId, PingwinSupplier $supplier, string $status): PingwinSupplierCcBalance
    {
        return PingwinSupplierCcBalance::updateOrCreate(
            ['company_id' => $companyId, 'supplier_id' => $supplier->id],
            ['entity_pingwin_id' => (string) $supplier->pingwin_id, 'sync_status' => $status]
        );
    }

    /** Falha: só estado + erro; documentos e último saldo bom ficam como estavam. */
    private function markFailed(int $companyId, PingwinSupplier $supplier, string $error): void
    {
        PingwinSupplierCcBalance::updateOrCreate(
            ['company_id' => $companyId, 'supplier_id' => $supplier->id],
            [
                'entity_pingwin_id' => (string) $supplier->pingwin_id,
                'sync_status'       => PingwinSupplierCcBalance::STATUS_FAILED,
                'last_error'        => mb_substr($error, 0, 1000),
            ]
        );
        Log::warning('[PingWin CC] fornecedor falhou', ['company_id' => $companyId, 'supplier_id' => $supplier->id, 'error' => $error]);
    }

    /** € (float/string) → cêntimos int. */
    public static function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** "20260826T00:00:00" (formato PingWin) ou "2026-08-26…" → "2026-08-26"; vazio/inválido → null. */
    private static function date($value): ?string
    {
        $v = trim((string) $value);
        if (! preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $v, $m)) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
