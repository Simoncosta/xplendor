<?php

declare(strict_types=1);

namespace App\Support\ConsultantSite;

/**
 * O ID de um vídeo do YouTube a partir de um link (vídeo, Short, youtu.be, embed, nocookie) ou
 * do próprio ID. Guarda-se só o ID; o site carrega-o pelo youtube-nocookie.com, ao toque.
 */
final class YoutubeId
{
    private const ID = '[A-Za-z0-9_-]{11}';

    public static function parse(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^' . self::ID . '$/', $value)) {
            return $value;
        }
        $parts = parse_url(str_contains($value, '://') ? $value : 'https://' . $value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^(www\.|m\.|music\.)/', '', $host) ?? $host;
        $path = (string) ($parts['path'] ?? '');
        if ($host === 'youtu.be' && preg_match('#^/(' . self::ID . ')#', $path, $m)) {
            return $m[1];
        }
        if (! in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            return null;
        }
        if (preg_match('#^/(shorts|embed|live|v)/(' . self::ID . ')#', $path, $m)) {
            return $m[2];
        }
        if ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $q);
            $v = is_string($q['v'] ?? null) ? $q['v'] : '';

            return preg_match('/^' . self::ID . '$/', $v) ? $v : null;
        }

        return null;
    }
}
