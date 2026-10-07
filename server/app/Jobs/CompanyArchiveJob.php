<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Agency\CompanyArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Diário: empresas arquivadas (sem admin, sem agência). Saem do arquivo as que ganharam um
 * admin ou uma agência; aviso ao contacto 7 dias antes do fim; apagadas ao fim de 90 dias.
 */
class CompanyArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(CompanyArchiveService $service): void
    {
        $r = $service->runDaily();
        if (array_sum($r) > 0) {
            Log::info('[Gestão por agências] Arquivo de empresas', $r);
        }
    }
}
