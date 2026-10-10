<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * O cabeçalho Content-Disposition com o nome do ficheiro: "inline" (abre no browser) ou
 * "attachment" (descarrega), com o nome em UTF-8 (filename*) e uma versão só em ASCII para os
 * browsers antigos (filename). Usado nos endereços assinados da aplicação e do R2.
 */
final class ContentDisposition
{
    public static function make(string $type, ?string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\r", "\n"], '-', (string) $name));
        if ($name === '') {
            return $type;
        }
        $fallback = trim(str_replace(['%', '"', '/', '\\'], '_', Str::ascii($name)));

        return HeaderUtils::makeDisposition($type, $name, $fallback !== '' ? $fallback : 'ficheiro');
    }
}
