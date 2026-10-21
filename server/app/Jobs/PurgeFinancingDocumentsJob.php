<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Financing\FinancingRetention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Diária (interna): o aviso e o apagamento automático dos documentos de financiamento. */
class PurgeFinancingDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(FinancingRetention $retention): void
    {
        $retention->run();
    }
}
