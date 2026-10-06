<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\Media\MediaService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ficheiros do disco privado "media", só por URL assinado de curta duração (middleware
 * signed:relative). Sem sessão: as etiquetas <img> e <video> não enviam o token. O vídeo é
 * servido por partes (Range), o que permite avançar sem descarregar o ficheiro todo.
 */
class MediaFileController extends Controller
{
    public function show(int $asset, string $variant): BinaryFileResponse
    {
        return self::serve(MediaAsset::find($asset), $variant);
    }

    /** Foto de perfil de uma conta ligada (para a pré-visualização como na rede). */
    public function avatar(int $account): BinaryFileResponse
    {
        $path = \App\Models\SocialConnectionAccount::whereKey($account)->value('profile_picture_path');
        abort_if(! $path || ! MediaService::disk()->exists($path), 404);

        return self::file($path, MediaService::disk()->mimeType($path) ?: 'image/jpeg');
    }

    /** Também usado pelos ficheiros do link de aprovação (que verificam o lote antes). */
    public static function serve(?MediaAsset $media, string $variant): BinaryFileResponse
    {
        $path = $media?->pathFor($variant);
        abort_if(! $media || ! $path || ! MediaService::disk()->exists($path), 404);

        return self::file($path, $variant === 'original' ? $media->mime : ($variant === 'poster' ? 'image/jpeg' : 'image/webp'));
    }

    private static function file(string $path, string $mime): BinaryFileResponse
    {
        $response = response()->file(MediaService::disk()->path($path), [
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
