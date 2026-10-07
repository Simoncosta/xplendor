<?php

namespace App\Jobs;

use App\Services\Setup\SetupPublicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Link de configuração: um passo da Meta iniciado há mais de 30 minutos e sem regresso (por
 * exemplo, um erro da Meta dentro do Facebook) avisa a agência, uma só vez por passo e link.
 */
class SetupLinkStalledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(SetupPublicService $setup): void
    {
        $setup->notifyStalled();
    }
}
