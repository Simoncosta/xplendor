<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Blog\BlogAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Blog — gera o rascunho pedido à IA (ai_requests, modo blog). O editor consulta o estado até
 * ficar pronto. Uma tentativa só: o serviço já repete a chamada à OpenAI e grava o erro.
 */
class GenerateBlogAiDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $draftId) {}

    public function handle(BlogAiService $service): void
    {
        $service->process($this->draftId);
    }
}
