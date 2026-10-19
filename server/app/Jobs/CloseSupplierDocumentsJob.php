<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupplierDocumentClose;
use App\Services\Finance\SupplierDocumentCloseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — ⚠️ ESCRITA no PingWin: fecha (8002) um lote de documentos de fornecedor em Aberto, um de
 * cada vez, no worker (o php-fpm não tem o socket Docker). Uma escrita de documento de cada vez por
 * empresa (o mesmo lock do lançamento das faturas); um fecho nunca se repete sozinho.
 */
class CloseSupplierDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1500;     // até 10 documentos: leitura viva + fecho + releitura de cada um

    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;
    public int $maxExceptions = 1;

    public function __construct(public int $companyId, public string $batch) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-docwrite:{$this->companyId}"))->releaseAfter(15)->expireAfter(1800)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(SupplierDocumentCloseService $closes): void
    {
        $closes->execute($this->companyId, $this->batch);
    }

    public function failed(\Throwable $e): void
    {
        // Morte do job: o que já tinha começado pode ter fechado → rever; o resto não foi feito.
        foreach (SupplierDocumentClose::where('company_id', $this->companyId)->where('batch', $this->batch)->where('status', SupplierDocumentClose::PENDING)->get() as $c) {
            $c->update(['status' => $c->started_at ? SupplierDocumentClose::CONFIRM_ERROR : SupplierDocumentClose::NOT_DONE,
                'error' => mb_substr('O job terminou sem resposta: ' . $e->getMessage(), 0, 1000), 'finished_at' => now()]);
        }
        Log::error('[Fechar documentos] job falhou', ['company_id' => $this->companyId, 'batch' => $this->batch, 'error' => $e->getMessage()]);
    }
}
