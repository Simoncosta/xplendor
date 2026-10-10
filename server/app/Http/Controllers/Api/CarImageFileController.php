<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * O editor de imagens das viaturas (FilePond e o recorte) carrega as imagens guardadas por
 * aqui, com CORS (o ecrã e a API podem estar em origens diferentes; o CORS é o global,
 * config/cors.php). Só serve imagens de viaturas (as cortadas e as originais, já sem EXIF),
 * que também são públicas em /storage. Substitui a rota antiga que servia todo o disco
 * público com CORS para localhost.
 */
class CarImageFileController extends Controller
{
    public const PATTERN = 'company_[0-9]+/cars/[A-Za-z0-9._-]+/(images|originals)/[A-Za-z0-9._-]+\.(webp|jpe?g|png)';

    public function show(string $path): BinaryFileResponse
    {
        // Defesa em profundidade: o caminho real tem de ficar dentro do disco público.
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $publicRoot = realpath($disk->path(''));
        $fullPath = realpath($disk->path($path));
        abort_unless($publicRoot !== false && $fullPath !== false
            && str_starts_with($fullPath, rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) && is_file($fullPath), 404);

        return Response::file($fullPath, ['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
