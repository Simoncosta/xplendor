<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * XPLENDOR — Cliente CoverManager (reservas). HTTP normal (Guzzle via Http) — sem
 * SSL legacy/hashing/login-logout do PingWin. O token vai DUAS vezes: no path
 * (url-encoded) E no header `apikey`.
 *
 *   GET {base}/api/restaurant/get_reservs/{token}/{slug}/{date}/{date}
 *   header: apikey: {token}
 */
class CoverManagerClient
{
    private const DEFAULT_BASE = 'https://www.covermanager.com';
    private const TIMEOUT = 30;

    /**
     * Devolve a lista de reservas (trata {"reservs":[...]} OU lista direta).
     * Em erro, sobe uma RuntimeException com o token mascarado (ver mask()).
     */
    public function getReservations(#[\SensitiveParameter] string $token, string $slug, string $date, ?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?: (string) config('services.covermanager.base_url', self::DEFAULT_BASE), '/');

        // ⚠️ url-encode do token e do slug no path.
        $url = $base . '/api/restaurant/get_reservs/'
            . rawurlencode($token) . '/'
            . rawurlencode($slug) . '/'
            . rawurlencode($date) . '/'
            . rawurlencode($date);

        try {
            $response = Http::withHeaders(['apikey' => $token])
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->get($url);

            $response->throw(); // 4xx/5xx → RequestException (o motivo real sobe, mascarado)
        } catch (\Throwable $e) {
            // Um erro de ligação traz o URL completo (com o token) e um 4xx pode ecoar o
            // token no corpo: sobe só a mensagem mascarada, sem a exceção original.
            throw new \RuntimeException(self::mask($e->getMessage(), $token), (int) $e->getCode());
        }

        $data = $response->json();

        // {"reservs": [...]} OU uma lista direta — tratar os dois casos.
        if (is_array($data) && array_key_exists('reservs', $data)) {
            return is_array($data['reservs']) ? $data['reservs'] : [];
        }

        return is_array($data) ? $data : [];
    }

    /** Substitui o token (tal como está e url-encoded) por "***" num texto a registar. */
    public static function mask(string $text, #[\SensitiveParameter] ?string $token): string
    {
        $token = trim((string) $token);
        if ($token === '') {
            return $text;
        }

        return str_replace(array_unique([$token, rawurlencode($token), urlencode($token)]), '***', $text);
    }
}
