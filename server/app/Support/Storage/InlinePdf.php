<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Um PDF aberto no browser a partir de um disco S3 (o R2): passa pela aplicação em vez de
 * redirecionar para o R2. O leitor de PDF do browser mostra como título o fim do endereço, e o
 * endereço do R2 acaba no identificador do objeto; o da aplicação acaba no nome do ficheiro
 * ({nome} na rota). Só para ver; descarregar continua a redirecionar para o R2.
 */
final class InlinePdf
{
    public static function stream(string $disk, string $path, string $name): StreamedResponse
    {
        $storage = Storage::disk($disk);
        $size = $storage->size($path);

        return new StreamedResponse(function () use ($storage, $path) {
            $stream = $storage->readStream($path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Length' => $size ?: null,
            'Content-Disposition' => ContentDisposition::make('inline', $name),
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]));
    }
}
