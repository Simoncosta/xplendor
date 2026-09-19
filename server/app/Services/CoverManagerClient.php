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

    /** Devolve a lista de reservas (trata {"reservs":[...]} OU lista direta). */
    public function getReservations(string $token, string $slug, string $date, ?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?: (string) config('services.covermanager.base_url', self::DEFAULT_BASE), '/');

        // ⚠️ url-encode do token e do slug no path.
        $url = $base . '/api/restaurant/get_reservs/'
            . rawurlencode($token) . '/'
            . rawurlencode($slug) . '/'
            . rawurlencode($date) . '/'
            . rawurlencode($date);

        $response = Http::withHeaders(['apikey' => $token])
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->get($url);

        $response->throw(); // 4xx/5xx → RequestException (o motivo real sobe)

        $data = $response->json();

        // {"reservs": [...]} OU uma lista direta — tratar os dois casos.
        if (is_array($data) && array_key_exists('reservs', $data)) {
            return is_array($data['reservs']) ? $data['reservs'] : [];
        }

        return is_array($data) ? $data : [];
    }
}
