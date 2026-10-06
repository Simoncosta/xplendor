<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\MediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Media da Linha Editorial: miniaturas (320 e 1080 WebP), capa e dados técnicos do vídeo
 * (ffmpeg/ffprobe). Uma tentativa: o serviço grava "rejeitado" com o motivo.
 */
class ProcessMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $assetId) {}

    public function handle(MediaService $media): void
    {
        $media->process($this->assetId);
    }

    /** Se o trabalho morrer (tempo esgotado, worker parado), o media não fica "a processar" para sempre. */
    public function failed(?\Throwable $e): void
    {
        MediaAsset::whereKey($this->assetId)->where('status', MediaAsset::PROCESSING)
            ->update(['status' => MediaAsset::REJECTED, 'error' => 'O processamento foi interrompido. Volte a enviar o ficheiro.']);
    }
}
