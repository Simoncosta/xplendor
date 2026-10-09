<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinDocumentSyncRun;
use App\Services\SupplierDocumentLinesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor (F4, SÓ LEITURA): UM lote de até 50
 * documentos (um docker exec, ~1,3 s por documento). Se ainda houver candidatos e sobrar
 * orçamento, despacha o lote seguinte — os lotes correm em sequência, cada um dentro do
 * timeout. Não sobrepõe a sync de documentos (F1) da mesma empresa: se houver um run ativo,
 * espera (release) e tenta de novo.
 */
class SyncPingwinSupplierDocumentLinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 330;      // docker exec até 300 s + margem
    public int $maxExceptions = 1;

    /** @param string $cycleStartedAt ISO — início do ciclo (um documento falhado não se repete no mesmo ciclo) */
    public function __construct(public int $companyId, public int $budget, public string $cycleStartedAt) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-doclines:{$this->companyId}"))->releaseAfter(60)->expireAfter(600)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(SupplierDocumentLinesService $lines): void
    {
        $docsRunActive = PingwinDocumentSyncRun::where('company_id', $this->companyId)
            ->whereIn('status', [PingwinDocumentSyncRun::STATUS_QUEUED, PingwinDocumentSyncRun::STATUS_RUNNING])
            ->exists();
        if ($docsRunActive) {
            $this->release(120); // a lista de documentos (F1) primeiro

            return;
        }

        $since = Carbon::parse($this->cycleStartedAt);
        $batch = $lines->candidates($this->companyId, [], $since)
            ->limit(min(SupplierDocumentLinesService::BATCH_SIZE, $this->budget))->get();
        if ($batch->isEmpty()) {
            return;
        }

        $stats = $lines->syncBatch($this->companyId, $batch->all());
        Log::info('[PingWin Linhas] lote concluído', ['company_id' => $this->companyId] + $stats);

        $budget = $this->budget - $stats['docs'];
        if ($budget > 0 && $lines->candidates($this->companyId, [], $since)->exists()) {
            self::dispatch($this->companyId, $budget, $this->cycleStartedAt);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Linhas] job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
    }
}
