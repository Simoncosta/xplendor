<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SupplierDocumentsSyncInProgress;
use App\Jobs\SyncPingwinSupplierDocumentsJob;
use App\Models\PingwinDocumentSyncRun;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Documentos de fornecedor do PingWin (F1, SÓ LEITURA): sync por período
 * (madrugada: últimos 7 dias; manual: qualquer período) e consulta para a página de Faturas.
 *
 * Regras: upsert por (company_id, docheader_id), NUNCA apaga (as anulações chegam como
 * docstatus "Anulado"); um único run ativo por empresa; um run preso (sem conclusão em
 * STALE_MINUTES) é dado como falhado para não bloquear a empresa para sempre.
 */
class SupplierDocumentsService
{
    public const NIGHTLY_DAYS = 7;
    public const STALE_MINUTES = 30;
    /** Filtro por omissão na UI: Fatura de fornecedor, Fatura-recibo compra, NC e ND de fornecedor. */
    public const DEFAULT_TYPES = ['1209', '584955579139752236', '1205', '1206'];

    public function __construct(private readonly PingwinService $pingwin) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Runs
    // ─────────────────────────────────────────────────────────────────────────

    /** Cria um run (queued) e põe o job na queue. Lança SupplierDocumentsSyncInProgress. */
    public function startRun(int $companyId, string $from, string $to, string $trigger): PingwinDocumentSyncRun
    {
        $run = $this->createRun($companyId, $from, $to, $trigger);
        SyncPingwinSupplierDocumentsJob::dispatch($run->id);

        return $run;
    }

    /** Cria um run (queued) se não houver outro ativo na empresa. */
    public function createRun(int $companyId, string $from, string $to, string $trigger): PingwinDocumentSyncRun
    {
        $start = CarbonImmutable::parse($from)->toDateString();
        $end = CarbonImmutable::parse($to)->toDateString();
        if ($end < $start) {
            throw new \InvalidArgumentException('O fim do período é anterior ao início.');
        }
        $this->expireStale($companyId);

        return DB::transaction(function () use ($companyId, $start, $end, $trigger) {
            $active = PingwinDocumentSyncRun::where('company_id', $companyId)
                ->whereIn('status', [PingwinDocumentSyncRun::STATUS_QUEUED, PingwinDocumentSyncRun::STATUS_RUNNING])
                ->lockForUpdate()->first();
            if ($active) {
                throw new SupplierDocumentsSyncInProgress($active);
            }

            return PingwinDocumentSyncRun::create([
                'company_id' => $companyId,
                'start_date' => $start,
                'end_date'   => $end,
                'trigger'    => $trigger,
                'status'     => PingwinDocumentSyncRun::STATUS_QUEUED,
            ]);
        });
    }

    /** Executa um run (no worker). Só corre se ainda estiver queued (idempotente). */
    public function executeRun(int $runId): PingwinDocumentSyncRun
    {
        $run = PingwinDocumentSyncRun::findOrFail($runId);
        if ($run->status !== PingwinDocumentSyncRun::STATUS_QUEUED) {
            return $run;
        }
        $run->update(['status' => PingwinDocumentSyncRun::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $res = $this->pingwin->fetchSupplierDocuments(
                $run->company_id, $run->start_date->toDateString(), $run->end_date->toDateString()
            );
            $count = $this->persist($run->company_id, $res['documents']);
            $run->update([
                'status'      => PingwinDocumentSyncRun::STATUS_OK,
                'docs_count'  => $count,
                'error'       => null,
                'finished_at' => now(),
            ]);
            Log::info('[PingWin Documentos] run concluído', [
                'run_id' => $run->id, 'company_id' => $run->company_id, 'docs' => $count,
                'blocks' => $res['blocks'], 'splits' => $res['splits'],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status'      => PingwinDocumentSyncRun::STATUS_FAILED,
                'error'       => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
            Log::warning('[PingWin Documentos] run falhou', ['run_id' => $run->id, 'company_id' => $run->company_id, 'error' => $e->getMessage()]);
        }

        return $run->fresh();
    }

    /** Runs ativos há mais de STALE_MINUTES → failed (job morto/worker reiniciado). */
    public function expireStale(int $companyId): void
    {
        PingwinDocumentSyncRun::where('company_id', $companyId)
            ->whereIn('status', [PingwinDocumentSyncRun::STATUS_QUEUED, PingwinDocumentSyncRun::STATUS_RUNNING])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status'      => PingwinDocumentSyncRun::STATUS_FAILED,
                'error'       => 'Expirou: sem conclusão em ' . self::STALE_MINUTES . ' minutos.',
                'finished_at' => now(),
            ]);
    }

    public function latestRun(int $companyId): ?PingwinDocumentSyncRun
    {
        return PingwinDocumentSyncRun::where('company_id', $companyId)->latest('id')->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistência
    // ─────────────────────────────────────────────────────────────────────────

    /** Upsert por docheader_id (first_seen_at só na inserção). Devolve nº de documentos. */
    public function persist(int $companyId, array $docs): int
    {
        $supplierIds = PingwinSupplier::where('company_id', $companyId)->whereNotNull('pingwin_id')
            ->pluck('id', 'pingwin_id')->all();
        $now = now();
        $str = static fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        $rows = [];
        foreach ($docs as $d) {
            $id = $str($d['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $entity = $str($d['entity_id'] ?? null);
            $rows[$id] = [
                'company_id'            => $companyId,
                'docheader_id'          => $id,
                'docconfig_id'          => (string) ($d['docconfig_id'] ?? ''),
                'doctype'               => $str($d['doctype'] ?? null),
                'document'              => $str($d['document'] ?? null),
                'entity_pingwin_id'     => $entity,
                'entity_name'           => $str($d['entity_name'] ?? null),
                'fiscalname'            => $str($d['fiscalname'] ?? null),
                'tax_number'            => $str($d['tax_number'] ?? null),
                'supplier_id'           => $entity !== null ? ($supplierIds[$entity] ?? null) : null,
                'store_pingwin_id'      => $str($d['store_id'] ?? null),
                'store_name'            => $str($d['store'] ?? null),
                'doc_date'              => self::date($d['doc_date'] ?? null),
                'fiscal_date'           => self::date($d['fiscal_date'] ?? null),
                'doc_time'              => self::time($d['doc_time'] ?? null),
                'total_cents'           => (int) round(((float) ($d['total'] ?? 0)) * 100),
                'paid'                  => (float) ($d['paid'] ?? 0) >= 1,
                'docstatus_id'          => $str($d['docstatus_id'] ?? null),
                'docstatus_description' => $str($d['docstatus_description'] ?? null),
                'employee_name'         => $str($d['employee'] ?? null),
                'docreference_number'   => $str($d['docreference_number'] ?? null),
                'raw'                   => json_encode($d, JSON_UNESCAPED_UNICODE),
                'first_seen_at'         => $now,
                'last_seen_at'          => $now,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }
        if ($rows === []) {
            return 0;
        }

        $rows = array_values($rows);
        $updateCols = array_values(array_diff(array_keys($rows[0]), ['company_id', 'docheader_id', 'first_seen_at', 'created_at']));
        foreach (array_chunk($rows, 200) as $chunk) {
            PingwinSupplierDocument::upsert($chunk, ['company_id', 'docheader_id'], $updateCols);
        }

        return count($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consulta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array{from?:?string, to?:?string, types?:?array, supplier?:?string, missing_ref?:?bool} $f
     */
    public function query(int $companyId, array $f): Builder
    {
        return PingwinSupplierDocument::where('company_id', $companyId)
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('doc_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('doc_date', '<=', $v))
            ->when(! empty($f['types']), fn ($q) => $q->whereIn('docconfig_id', $f['types']))
            ->when($f['supplier'] ?? null, fn ($q, $v) => $q->where('entity_pingwin_id', $v))
            ->when(! empty($f['missing_ref']), fn ($q) => $q->where(fn ($w) => $w->whereNull('docreference_number')->orWhere('docreference_number', '')))
            ->orderByDesc('doc_date')->orderByDesc('doc_time')->orderByDesc('document');
    }

    /** Opções dos filtros a partir do que existe no espelho (tipos e fornecedores). */
    public function facets(int $companyId): array
    {
        $types = PingwinSupplierDocument::where('company_id', $companyId)
            ->select('docconfig_id', 'doctype', DB::raw('COUNT(*) as n'))
            ->groupBy('docconfig_id', 'doctype')->orderByDesc('n')->get()
            ->map(fn ($r) => ['id' => $r->docconfig_id, 'label' => $r->doctype, 'count' => (int) $r->n])->values()->all();
        $suppliers = PingwinSupplierDocument::where('company_id', $companyId)->whereNotNull('entity_pingwin_id')
            ->select('entity_pingwin_id', DB::raw('MAX(entity_name) as name'))
            ->groupBy('entity_pingwin_id')->orderBy('name')->get()
            ->map(fn ($r) => ['id' => $r->entity_pingwin_id, 'name' => $r->name])->values()->all();

        return ['types' => $types, 'suppliers' => $suppliers, 'default_types' => self::DEFAULT_TYPES];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** "20261005T00:00:00" → "2026-10-05". */
    private static function date($v): ?string
    {
        return preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', trim((string) $v), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** "00000000T10:42:18" → "10:42:18". */
    private static function time($v): ?string
    {
        return preg_match('/T?(\d{2}):(\d{2}):(\d{2})$/', trim((string) $v), $m) ? "{$m[1]}:{$m[2]}:{$m[3]}" : null;
    }
}
