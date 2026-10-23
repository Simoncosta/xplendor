<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Agency\Meta\AgencyAssetLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Parceiro 5: depois de a agência voltar a ligar a Meta (a renovação manual), o token novo chega às
 * ligações dos clientes com a origem "agencia" (AgencyAssetLifecycle::propagateToken). Na fila, para o
 * regresso da Meta não esperar pelos pedidos de cada Página.
 */
class PropagateAgencyMetaTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $agencyId) {}

    public function handle(AgencyAssetLifecycle $lifecycle): void
    {
        $lifecycle->propagateToken($this->agencyId);
    }
}
