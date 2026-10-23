<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Agency\AgencyMetaConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Parceiro 2: avisa as agências cuja ligação da Meta acaba dentro de 10 dias (uma vez) e marca as
 * expiradas. Só lê a base de dados (sem chamadas à Meta); tarefa interna agency-meta-renewal.
 */
class WarnAgencyMetaRenewalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(AgencyMetaConnectionService $connections): array
    {
        $out = $connections->warnRenewals();
        Log::info('[Agência] Renovação da ligação da Meta', $out);

        return $out;
    }
}
