<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinDocumentSyncRun;
use App\Services\SupplierDocumentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Documentos de fornecedor (F1, SÓ LEITURA): executa UM run (um docker exec,
 * blocos de 7 dias no Python). Corre no worker (o php-fpm não tem o socket Docker).
 * O estado vive no próprio run (pingwin_document_sync_runs), que a UI consulta.
 */
class SyncPingwinSupplierDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 660; // docker exec até 600 s (período largo) + margem

    public function __construct(public int $runId) {}

    public function handle(SupplierDocumentsService $docs): void
    {
        $docs->executeRun($this->runId);
    }

    /** Morte do job (timeout/worker): o run não pode ficar "running" para sempre. */
    public function failed(\Throwable $e): void
    {
        PingwinDocumentSyncRun::where('id', $this->runId)
            ->whereIn('status', [PingwinDocumentSyncRun::STATUS_QUEUED, PingwinDocumentSyncRun::STATUS_RUNNING])
            ->update(['status' => PingwinDocumentSyncRun::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        Log::error('[PingWin Documentos] job falhou', ['run_id' => $this->runId, 'error' => $e->getMessage()]);
    }
}
