<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Agency\ManagementRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Diário: apaga o NIPC ou email dos pedidos de gestão sem empresa correspondente, 30 dias depois de expirarem ou serem retirados. */
class ScrubManagementRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(ManagementRequestService $service): void
    {
        $count = $service->scrubUnmatched();
        if ($count > 0) {
            Log::info('[Gestão por agências] Dados de pedidos sem empresa apagados', ['count' => $count]);
        }
    }
}
