<?php

declare(strict_types=1);

namespace App\Services\Media;

use Symfony\Component\Process\Process;

/**
 * ffprobe e ffmpeg (vídeo): dados técnicos e a imagem da capa. Isolado numa classe para os
 * testes o poderem simular; no servidor exige o ffmpeg na imagem do PHP e do worker.
 */
class MediaProbe
{
    /** @return array{width: int, height: int, duration_ms: int, codec: ?string} */
    public function probeVideo(string $absolutePath): array
    {
        $p = new Process([(string) config('media.ffprobe', 'ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,codec_name:stream_tags=rotate:stream_side_data=rotation:format=duration',
            '-of', 'json', $absolutePath]);
        $p->setTimeout(60);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('Não foi possível ler o vídeo.');
        }
        $data = json_decode($p->getOutput(), true) ?: [];
        $stream = $data['streams'][0] ?? null;
        if (! $stream) {
            throw new \RuntimeException('O ficheiro não tem imagem de vídeo.');
        }
        $w = (int) ($stream['width'] ?? 0);
        $h = (int) ($stream['height'] ?? 0);
        // Vídeos gravados ao alto no telemóvel vêm "deitados" com rotação de 90 graus.
        $rotation = abs((int) ($stream['tags']['rotate'] ?? ($stream['side_data_list'][0]['rotation'] ?? 0)));
        if ($rotation === 90 || $rotation === 270) {
            [$w, $h] = [$h, $w];
        }

        return [
            'width' => $w, 'height' => $h,
            'duration_ms' => (int) round(((float) ($data['format']['duration'] ?? 0)) * 1000),
            'codec' => $stream['codec_name'] ?? null,
        ];
    }

    /** Grava a capa (JPEG) a partir de um instante do vídeo. */
    public function posterFrame(string $absoluteVideo, string $absoluteJpeg, float $atSeconds): void
    {
        $p = new Process([(string) config('media.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-ss', (string) max(0, $atSeconds),
            '-i', $absoluteVideo, '-frames:v', '1', '-q:v', '3', $absoluteJpeg]);
        $p->setTimeout(120);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($absoluteJpeg)) {
            throw new \RuntimeException('Não foi possível gerar a capa do vídeo.');
        }
    }
}
