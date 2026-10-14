<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use Illuminate\Support\Facades\Cache;

/**
 * Radar: a condição dos termos do Google Trends. Uma vez por dia (cache de 24 horas) lê o
 * robots.txt; se o caminho do feed (/trending) passar a Disallow, a fonte não é lida. Uma
 * leitura que falha (sem resposta ou erro 5xx) também não deixa ler a fonte, e não fica em cache.
 */
class RobotsGuard
{
    public const TTL_SECONDS = 86400;

    public const DISALLOWED = 'robots.txt passou a proibir /trending';

    public function __construct(private readonly RadarHttp $http) {}

    public function needsFetch(RadarSource $source): bool
    {
        return $this->robotsUrl($source) !== null && Cache::get($this->cacheKey($source)) === null;
    }

    /** @return array{allowed: bool, requests: int, error: ?string} */
    public function check(RadarSource $source): array
    {
        $url = $this->robotsUrl($source);
        if ($url === null) {
            return ['allowed' => true, 'requests' => 0, 'error' => null];
        }
        $cached = Cache::get($this->cacheKey($source));
        if ($cached !== null) {
            return ['allowed' => $cached === 'allowed', 'requests' => 0, 'error' => $cached === 'allowed' ? null : self::DISALLOWED];
        }
        try {
            $response = $this->http->get($url);
        } catch (RadarFetchException) {
            return ['allowed' => false, 'requests' => 1, 'error' => 'Não foi possível ler o robots.txt; a fonte não foi lida.'];
        }
        if ($response->status >= 500 || ($response->status >= 300 && $response->status < 400)) {
            return ['allowed' => false, 'requests' => 1, 'error' => "O robots.txt respondeu {$response->status}; a fonte não foi lida."];
        }
        // Sem robots.txt (4xx), não há proibição.
        $paths = array_filter([(string) ($source->config()['robots_path'] ?? ''), (string) parse_url($source->url(), PHP_URL_PATH)]);
        $allowed = $response->status >= 400 || ! self::disallows($response->body, $paths);
        Cache::put($this->cacheKey($source), $allowed ? 'allowed' : 'disallowed', self::TTL_SECONDS);

        return ['allowed' => $allowed, 'requests' => 1, 'error' => $allowed ? null : self::DISALLOWED];
    }

    /** Alguma regra Disallow do grupo "*" (ou do nosso agente) cobre um destes caminhos. */
    public static function disallows(string $robots, array $paths): bool
    {
        $applies = false;
        $groupHasRules = false;
        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($groupHasRules) {
                    $applies = false;
                    $groupHasRules = false;
                }
                $agent = strtolower($value);
                $applies = $applies || $agent === '*' || str_starts_with($agent, 'xplendor');
                continue;
            }
            if ($field === 'disallow' || $field === 'allow') {
                $groupHasRules = true;
            }
            if (! $applies || $field !== 'disallow' || $value === '') {
                continue;
            }
            $rule = rtrim($value, '*$');
            foreach ($paths as $path) {
                if ($rule === '' || $rule === '/' || str_starts_with($path, $rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function robotsUrl(RadarSource $source): ?string
    {
        $url = $source->config()['robots_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function cacheKey(RadarSource $source): string
    {
        return 'radar:robots:' . $source->key();
    }
}
