<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * O caminho público ("/storage/...") de um ficheiro do disco PÚBLICO, pedido explicitamente a
 * esse disco (Storage::disk('public')->url()). Antes usava-se Storage::url(), que dependia do
 * disco por omissão (FILESYSTEM_DISK) e só dava certo por acaso. Guarda-se o caminho relativo,
 * como sempre: o URL do disco público é absoluto (APP_URL) e o APP_URL pode não ser o da API.
 */
final class PublicUrl
{
    public static function path(string $diskPath): string
    {
        $url = Storage::disk('public')->url($diskPath);
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/storage/' . ltrim($diskPath, '/');
    }
}
