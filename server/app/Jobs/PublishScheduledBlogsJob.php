<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BlogWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A cada 5 minutos: publica os artigos do blog aprovados cuja data de publicação chegou.
 * Sem gatilho de build dos sites (fica para a fase de cada site).
 */
class PublishScheduledBlogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(BlogWorkflowService $workflow): void
    {
        $count = $workflow->publishDue();
        if ($count > 0) {
            Log::info('[Blog] Artigos agendados publicados', ['count' => $count]);
        }
    }
}
