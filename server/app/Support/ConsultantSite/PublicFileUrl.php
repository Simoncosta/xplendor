<?php

declare(strict_types=1);

namespace App\Support\ConsultantSite;

/**
 * O URL absoluto de um ficheiro público ("/storage/...") para o site do consultor, que corre
 * noutro contentor e noutro domínio. A base vem de PUBLIC_FILES_BASE_URL (preparado para o R2-2)
 * ou, vazia, do APP_URL.
 */
final class PublicFileUrl
{
    public static function absolute(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim((string) (config('consultor_site.public_files_base_url') ?: config('app.url')), '/');

        return $base . '/' . ltrim($path, '/');
    }
}
