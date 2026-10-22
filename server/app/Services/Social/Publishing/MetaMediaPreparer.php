<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

use App\Models\EditorialPost;
use App\Models\EditorialPostNetwork;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialPublication;
use App\Services\Media\MediaProbe;
use App\Services\Media\MediaService;
use App\Support\Storage\LocalCopy;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * F2.3 · Os ficheiros para a Meta. Ao programar (PrepareSocialPublicationMediaJob), cada publicação
 * agendada prepara os seus ficheiros e, se algum não servir, falha logo com o motivo (antes da hora):
 *  · imagens: o Instagram só aceita JPEG; uma PNG ou WebP ganha a variante "meta" (meta.jpg, até
 *    1440 de largura), no mesmo disco privado;
 *  · vídeos: H.264 com som AAC (ou sem som) e a duração do formato, lidos pelo ffprobe.
 * O endereço para a Meta é o URL assinado de 1 hora (MediaAsset::externalUrl), gerado só no momento
 * de criar o contentor: nunca há uma cópia pública permanente.
 */
class MetaMediaPreparer
{
    public const JPEG_MAX_WIDTH = 1440;

    /** Duração permitida por formato, em segundos: [mínimo, máximo]. */
    public const VIDEO_SECONDS = [
        'ig_reel' => [3, 900],
        'ig_carousel' => [3, 60],
        'fb_video' => [1, 14400],
        'fb_reel' => [3, 90],
    ];

    public function __construct(private readonly MediaProbe $probe) {}

    /** Prepara os ficheiros de uma publicação agendada; se algum não servir, ela falha já, com o motivo. */
    public function prepare(SocialPublication $publication): ?string
    {
        if ($publication->status !== SocialPublication::STATUS_SCHEDULED) {
            return null;
        }
        $post = EditorialPost::find($publication->editorial_post_id);
        $network = EditorialPostNetwork::find($publication->editorial_post_network_id);
        if (! $post || ! $network) {
            return null;
        }
        [$items, $cover] = self::media(EditorialPostVersion::find($post->approved_version_id ?? $post->current_version_id));
        $problem = null;
        foreach (array_filter([...$items, $cover]) as $asset) {
            $problem = $asset->kind === MediaAsset::VIDEO
                ? $this->videoProblem($asset, (string) $network->media_format)
                : $this->imageProblem($asset);
            if ($problem) {
                break;
            }
        }
        if ($problem) {
            // Só se ainda estiver agendada (o publicador pode tê-la reservado entretanto).
            SocialPublication::whereKey($publication->id)->where('status', SocialPublication::STATUS_SCHEDULED)
                ->update(['status' => SocialPublication::STATUS_FAILED, 'error_code' => 'media', 'error_message' => mb_substr($problem, 0, 500), 'updated_at' => now()]);
        }

        return $problem;
    }

    /** O endereço da imagem para a Meta: sempre JPEG (o original, se já for; senão a variante "meta"). */
    public function imageUrl(MediaAsset $asset): ?string
    {
        if ($asset->kind !== MediaAsset::IMAGE) {
            return null;
        }
        if (self::isJpeg($asset)) {
            return $asset->externalUrl('original');
        }

        return $this->ensureJpeg($asset) ? $asset->externalUrl('meta') : null;
    }

    /** O vídeo serve para este formato? Devolve o motivo em linguagem simples, ou null. */
    public function videoProblem(MediaAsset $asset, string $format): ?string
    {
        if ($asset->kind !== MediaAsset::VIDEO) {
            return null;
        }
        if ($asset->audio_codec === null) {
            if (! $asset->pathFor('original')) {
                return 'O vídeo original já não existe (foi apagado pela retenção). Envie-o de novo.';
            }
            try {
                $info = LocalCopy::with(MediaService::diskForVariant($asset, 'original'), (string) $asset->pathFor('original'),
                    fn (string $path) => $this->probe->probeStreams($path));
            } catch (\Throwable) {
                return 'Não foi possível ler o vídeo. Experimente exportá-lo de novo em MP4.';
            }
            $asset->forceFill(['codec' => $info['video_codec'] ?? $asset->codec, 'audio_codec' => mb_substr($info['audio_codec'], 0, 20),
                'duration_ms' => $info['duration_ms'] ?: $asset->duration_ms])->save();
        }
        if (strtolower((string) $asset->codec) !== 'h264') {
            return 'O vídeo tem de estar em H.264 (MP4). Exporte-o de novo nesse formato e substitua-o na versão.';
        }
        if (! in_array(strtolower((string) $asset->audio_codec), ['aac', 'none'], true)) {
            return 'O som do vídeo tem de estar em AAC. Exporte-o de novo em MP4 (H.264 com AAC) e substitua-o na versão.';
        }
        [$min, $max] = self::VIDEO_SECONDS[$format] ?? [1, 14400];
        $seconds = ((int) $asset->duration_ms) / 1000;
        if ($seconds < $min || $seconds > $max) {
            return "Este formato aceita vídeos entre {$min} e " . ($max >= 120 ? intdiv($max, 60) . ' minutos' : "{$max} segundos")
                . ' (este tem ' . self::duration($seconds) . ').';
        }

        return null;
    }

    private function imageProblem(MediaAsset $asset): ?string
    {
        if (self::isJpeg($asset)) {
            return $asset->pathFor('original') ? null : 'A imagem original já não existe (foi apagada pela retenção). Envie-a de novo.';
        }

        return $this->ensureJpeg($asset) ? null : 'Não foi possível converter a imagem para JPEG. Exporte-a de novo em JPEG.';
    }

    /** Gera a variante "meta" (JPEG) se ainda não existir. */
    private function ensureJpeg(MediaAsset $asset): bool
    {
        $disk = MediaService::diskFor($asset);
        if (($path = $asset->pathFor('meta')) && $disk->exists($path)) {
            return true;
        }
        if (! $asset->pathFor('original')) {
            return false;
        }
        try {
            $binary = LocalCopy::with(MediaService::diskForVariant($asset, 'original'), (string) $asset->pathFor('original'),
                fn (string $original) => (new ImageManager(new Driver()))->read($original)->orient()
                    ->scaleDown(width: self::JPEG_MAX_WIDTH)->toJpeg(90)->toString());
        } catch (\Throwable) {
            return false;
        }
        $disk->put("{$asset->dir}/meta.jpg", $binary);
        $asset->forceFill([
            'variants' => array_merge((array) ($asset->variants ?? []), ['meta' => 'meta.jpg']),
            'variants_bytes' => (int) $asset->variants_bytes + strlen($binary),
        ])->save();

        return true;
    }

    private static function isJpeg(MediaAsset $asset): bool
    {
        return in_array(strtolower((string) $asset->mime), ['image/jpeg', 'image/jpg'], true);
    }

    private static function duration(float $seconds): string
    {
        return $seconds >= 120 ? intdiv((int) round($seconds), 60) . ' minutos' : (int) round($seconds) . ' segundos';
    }

    /**
     * Os ficheiros de uma versão, pela ordem, e a capa.
     *
     * @return array{0: MediaAsset[], 1: ?MediaAsset}
     */
    public static function media(?EditorialPostVersion $version): array
    {
        if (! $version) {
            return [[], null];
        }
        $rows = DB::table('editorial_post_version_media')->where('version_id', $version->id)->orderBy('position')->get();
        $assets = MediaAsset::whereIn('id', $rows->pluck('media_asset_id'))->get()->keyBy('id');
        $items = $rows->where('role', 'item')->map(fn ($r) => $assets[$r->media_asset_id] ?? null)->filter()->values()->all();
        $cover = ($c = $rows->firstWhere('role', 'cover')) ? ($assets[$c->media_asset_id] ?? null) : null;

        return [$items, $cover];
    }
}
