<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SocialPublication;
use App\Services\Social\Publishing\MetaMediaPreparer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * F2.3 · Ao programar uma publicação automática: prepara os ficheiros para a Meta (JPEG e
 * verificação do vídeo) na fila, para não atrasar a gravação. Nunca chama a Meta.
 */
class PrepareSocialPublicationMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $publicationId) {}

    public function handle(MetaMediaPreparer $preparer): void
    {
        $publication = SocialPublication::find($this->publicationId);
        if (! $publication) {
            return;
        }
        try {
            $preparer->prepare($publication);
        } catch (\Throwable $e) {
            // Sem preparação, o publicador ainda tenta à hora (e gera o JPEG nesse momento).
            Log::warning('[Publicação automática] Falha ao preparar os ficheiros', ['publication_id' => $publication->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
