<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\QuoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Diário (00:15, hora de Lisboa): passa a Expirado os orçamentos enviados cuja
 * validade (30 dias após o envio) já passou. Rascunhos nunca expiram.
 */
class ExpireQuotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(QuoteService $service): void
    {
        $count = $service->expireDue();
        if ($count > 0) {
            Log::info('[Quotes] Orçamentos expirados', ['count' => $count]);
        }
    }
}
