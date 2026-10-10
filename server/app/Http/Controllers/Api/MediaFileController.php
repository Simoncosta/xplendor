<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\Media\MediaService;
use App\Support\Storage\SignedFileUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ficheiros do disco privado "media", só por URL assinado de curta duração (middleware
 * signed:relative). Sem sessão: as etiquetas <img> e <video> não enviam o token. O vídeo é
 * servido por partes (Range), o que permite avançar sem descarregar o ficheiro todo.
 *
 * R2: quando o ficheiro está num disco S3 (o Cloudflare R2, bucket privado), a resposta é um
 * redirecionamento para um endereço assinado do R2 de curta duração (5 minutos), gerado aqui,
 * depois de verificado o URL assinado da aplicação. O R2 também serve por partes (Range).
 */
class MediaFileController extends Controller
{
    public function show(int $asset, string $variant): Response
    {
        return self::serve(MediaAsset::find($asset), $variant);
    }

    /** Foto de perfil de uma conta ligada (para a pré-visualização como na rede). */
    public function avatar(int $account): Response
    {
        $path = \App\Models\SocialConnectionAccount::whereKey($account)->value('profile_picture_path');
        $disk = MediaService::disk();
        abort_if(! $path || ! $disk->exists($path), 404);

        return self::file($disk, (string) config('media.disk', 'media'), $path, $disk->mimeType($path) ?: 'image/jpeg');
    }

    /** Também usado pelos ficheiros do link de aprovação (que verificam o lote antes). */
    public static function serve(?MediaAsset $media, string $variant): Response
    {
        $path = $media?->pathFor($variant);
        $disk = $media ? MediaService::diskFor($media) : null;
        abort_if(! $media || ! $path || ! $disk->exists($path), 404);

        return self::file($disk, (string) ($media->disk ?: config('media.disk', 'media')), $path,
            $variant === 'original' ? $media->mime : ($variant === 'poster' ? 'image/jpeg' : 'image/webp'));
    }

    private static function file(Filesystem $disk, string $diskName, string $path, string $mime): Response
    {
        if (SignedFileUrl::supports($diskName)) {
            return redirect()->away(SignedFileUrl::for($diskName, $path, 300), 302, [
                'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex',
            ]);
        }
        $response = response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
        $response->setContentDisposition('inline');

        return $response;
    }
}
