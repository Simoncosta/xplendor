<?php

namespace App\Jobs;

use App\Models\AiRequest;
use App\Services\Ai\AiRequestLifecycle;
use App\Services\Brand\BrandProfileAiService;
use App\Services\Brand\CreativeAiService;
use App\Services\Editorial\CaptionAiService;
use App\Services\Editorial\EditorialIdeasAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processa um pedido à IA (ai_requests) dos modos perfil da marca, criativo, ideias do mês e legenda. O ecrã
 * consulta o estado até ficar pronto. O blog tem o seu próprio job (GenerateBlogAiDraftJob).
 */
class ProcessAiRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $requestId) {}

    public function handle(): void
    {
        $mode = AiRequest::whereKey($this->requestId)->value('mode');

        match ($mode) {
            AiRequest::MODE_BRAND_PROFILE => app(BrandProfileAiService::class)->process($this->requestId),
            AiRequest::MODE_CREATIVE      => app(CreativeAiService::class)->process($this->requestId),
            AiRequest::MODE_IDEAS         => app(EditorialIdeasAiService::class)->process($this->requestId),
            AiRequest::MODE_CAPTION       => app(CaptionAiService::class)->process($this->requestId),
            default                       => null,
        };
    }

    /** Tempo esgotado ou worker parado: o pedido não fica "a gerar" para sempre. */
    public function failed(?\Throwable $e): void
    {
        app(AiRequestLifecycle::class)->interrupted($this->requestId, $e);
    }
}
