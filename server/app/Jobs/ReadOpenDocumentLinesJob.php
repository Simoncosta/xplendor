<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Finance\SupplierDocumentCloseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — SÓ LEITURA: lê no PingWin as linhas de até 10 documentos de fornecedor (os em Aberto
 * não entram na leitura noturna das linhas, que só lê os fechados), para a conferência do fecho.
 */
class ReadOpenDocumentLinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public int $tries = 1;

    /** @param list<string> $docheaderIds */
    public function __construct(public int $companyId, public array $docheaderIds) {}

    public function handle(SupplierDocumentCloseService $closes): void
    {
        $closes->readLines($this->companyId, $this->docheaderIds);
    }
}
