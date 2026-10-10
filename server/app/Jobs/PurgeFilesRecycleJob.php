<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Files\FilesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ficheiros: apaga de vez o que está na reciclagem há mais de 30 dias (os ficheiros enviados
 * nos Ficheiros, as pastas e os atalhos). Os ficheiros do sistema nunca passam por aqui.
 * Tarefa interna (sem chamadas externas).
 */
class PurgeFilesRecycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(FilesService $files): array
    {
        $stats = $files->purgeRecycle();
        Log::info('[Ficheiros] Reciclagem', $stats);

        return $stats;
    }
}
