<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinDocumentWrite;
use App\Services\OcrInvoiceLaunchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — FB-1 ⚠️ ESCRITA no PingWin: executa UMA escrita de documento (lançar em rascunho,
 * fechar ou anular) no worker (o php-fpm não tem o socket Docker). Uma de cada vez por empresa;
 * esperar pelo lock não conta como falha; uma exceção real falha à primeira — uma escrita NUNCA
 * se repete sozinha (o serviço também só executa escritas "pendente").
 */
class LaunchPingwinDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;      // pesquisa viva + lista viva + lançamento com releitura
    public int $maxExceptions = 1;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $companyId = (int) PingwinDocumentWrite::whereKey($this->writeId)->value('company_id');

        return [(new WithoutOverlapping("pingwin-docwrite:{$companyId}"))->releaseAfter(15)->expireAfter(900)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(OcrInvoiceLaunchService $launch): void
    {
        $launch->execute($this->writeId);
    }

    public function failed(\Throwable $e): void
    {
        // Morte do job: se o lançamento já tinha ido ao PingWin, pode ter gravado → rever, nunca repetir.
        $w = PingwinDocumentWrite::find($this->writeId);
        if ($w && $w->status === PingwinDocumentWrite::PENDING) {
            $w->update(['status' => $w->started_at ? PingwinDocumentWrite::CONFIRM_ERROR : PingwinDocumentWrite::ERROR,
                'error' => mb_substr('O job terminou sem resposta: ' . $e->getMessage(), 0, 2000), 'finished_at' => now()]);
        }
        Log::error('[PingWin Lançar] job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
