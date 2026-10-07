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

/** De hora a hora: os pedidos de gestão pendentes há mais de 14 dias passam a expirados. */
class ExpireManagementRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(ManagementRequestService $service): void
    {
        $count = $service->expireDue();
        if ($count > 0) {
            Log::info('[Gestão por agências] Pedidos de gestão expirados', ['count' => $count]);
        }
    }
}
