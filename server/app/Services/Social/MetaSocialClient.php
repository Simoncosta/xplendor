<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Chamadas à Graph API da ligação das redes sociais. Separado do MetaAdsService de
 * propósito: a ligação dos anúncios não muda. Cada leitura devolve ['ok' => bool, ...]
 * e, quando falha, o tipo de erro (ver classify()).
 */
class MetaSocialClient
{
    public const GRAPH_URL = 'https://graph.facebook.com/v25.0';
    public const DIALOG_URL = 'https://www.facebook.com/v25.0/dialog/oauth';

    // Tipos de erro (estados honestos no ecrã).
    public const ERR_EXPIRED = SocialConnection::STATUS_EXPIRED;            // token inválido ou expirado
    public const ERR_PERMISSION = 'permission';                             // a Meta recusou por permissão (ver permissionState())
    public const ERR_FAILED = SocialConnection::ERROR_FAILED;               // outra falha (rede, limite, erro da Meta)

    private const TIMEOUT = 15;

    /** URL de autorização: só as três permissões das redes; rerequest volta a pedir as recusadas. */
    public function authUrl(string $state): string
    {
        return self::DIALOG_URL . '?' . http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => self::redirectUri(),
            'scope' => implode(',', SocialConnection::SCOPES),
            'response_type' => 'code',
            'auth_type' => 'rerequest',
            'state' => $state,
        ]);
    }

    /**
     * URL de retorno das redes sociais. Por omissão, ao lado do callback dos anúncios:
     * https://host/api/oauth/meta/callback → https://host/api/oauth/meta/social/callback
     */
    public static function redirectUri(): string
    {
        $explicit = (string) config('services.meta.social_redirect_uri');
        if ($explicit !== '') {
            return $explicit;
        }
        $ads = (string) config('services.meta.redirect_uri');
        $derived = preg_replace('#/api/oauth/meta/callback/?$#', '/api/oauth/meta/social/callback', $ads);

        return ($derived && $derived !== $ads) ? $derived : rtrim((string) config('app.url'), '/') . '/api/oauth/meta/social/callback';
    }

    /** Troca o code pelo token de longa duração; devolve token, expiração, utilizador e permissões concedidas. */
    public function exchangeCode(string $code): ?array
    {
        $short = $this->send(fn () => Http::asForm()->timeout(self::TIMEOUT)->post(self::GRAPH_URL . '/oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => self::redirectUri(),
            'code' => $code,
        ]));
        $shortToken = $short?->successful() ? $short->json('access_token') : null;
        if (! $shortToken) {
            return null;
        }

        $long = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $shortToken,
        ]));
        $token = $long?->successful() ? $long->json('access_token') : null;
        if (! $token) {
            return null;
        }

        $debug = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH_URL . '/debug_token', [
            'input_token' => $token,
            'access_token' => config('services.meta.app_id') . '|' . config('services.meta.app_secret'),
        ]));
        $data = $debug?->successful() ? (array) $debug->json('data', []) : [];

        return [
            'token' => (string) $token,
            // 0 ou ausente = sem data de expiração (NULL), nunca 1970 nem uma data inventada.
            'expires_at' => \App\Support\MetaTokenExpiry::fromDebug($data),
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'scopes' => array_values(array_intersect(SocialConnection::SCOPES, (array) ($data['scopes'] ?? SocialConnection::SCOPES))),
        ];
    }

    /**
     * Páginas a que o utilizador tem acesso e, por cada uma, a conta de Instagram
     * profissional ligada (se houver). Inclui o token de cada Página (nunca sai do backend).
     */
    public function pages(string $userToken): array
    {
        $pages = [];
        $url = self::GRAPH_URL . '/me/accounts';
        $query = [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
            'limit' => 100,
            'access_token' => $userToken,
        ];

        for ($i = 0; $i < 10 && $url; $i++) {
            $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->get($url, $query));
            if (! $r || ! $r->successful()) {
                return $this->failure($r);
            }
            foreach ((array) $r->json('data', []) as $p) {
                $ig = $p['instagram_business_account'] ?? null;
                $pages[] = [
                    'id' => (string) $p['id'],
                    'name' => (string) ($p['name'] ?? ''),
                    'access_token' => (string) ($p['access_token'] ?? ''),
                    'instagram' => $ig ? ['id' => (string) $ig['id'], 'username' => $ig['username'] ?? null, 'name' => $ig['name'] ?? null] : null,
                ];
            }
            $url = $r->json('paging.next');
            $query = []; // o "next" já traz os parâmetros
        }

        return ['ok' => true, 'pages' => $pages];
    }

    /** Seguidores de uma Página (followers_count; o fan_count está obsoleto na Graph API). */
    public function pageFollowers(string $pageId, string $pageToken): array
    {
        $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH_URL . '/' . $pageId, [
            'fields' => 'followers_count',
            'access_token' => $pageToken,
        ]));
        if (! $r || ! $r->successful()) {
            return $this->failure($r);
        }
        $count = $r->json('followers_count');

        return is_numeric($count)
            ? ['ok' => true, 'followers' => (int) $count, 'follows' => null, 'media' => null]
            : ['ok' => false, 'kind' => self::ERR_FAILED, 'message' => 'A Meta não devolveu o número de seguidores.'];
    }

    /** Seguidores de uma conta de Instagram profissional (lida com o token da Página a que está ligada). */
    public function instagramFollowers(string $igId, string $pageToken): array
    {
        $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH_URL . '/' . $igId, [
            'fields' => 'followers_count,follows_count,media_count',
            'access_token' => $pageToken,
        ]));
        if (! $r || ! $r->successful()) {
            return $this->failure($r);
        }
        $count = $r->json('followers_count');

        return is_numeric($count)
            ? ['ok' => true, 'followers' => (int) $count, 'follows' => self::intOrNull($r->json('follows_count')), 'media' => self::intOrNull($r->json('media_count'))]
            : ['ok' => false, 'kind' => self::ERR_FAILED, 'message' => 'A Meta não devolveu o número de seguidores.'];
    }

    /**
     * Depois de uma recusa por permissão: se as três continuam concedidas, a recusa vem
     * do nível de acesso da app (ainda sem aprovação da Meta para contas fora da equipa);
     * se alguma foi retirada pelo utilizador, é preciso voltar a ligar.
     */
    public function permissionState(string $userToken): string
    {
        $statuses = $this->permissionStatuses($userToken);
        if ($statuses === null) {
            return SocialConnection::STATUS_EXPIRED;
        }
        $granted = array_keys(array_filter($statuses, fn ($st) => $st === 'granted'));

        return array_diff(SocialConnection::SCOPES, $granted) === []
            ? SocialConnection::STATUS_NOT_APPROVED
            : SocialConnection::STATUS_PERMISSION_REMOVED;
    }

    /**
     * Estado de cada permissão (granted | declined) segundo GET /me/permissions. Uma
     * permissão que nem aparece não foi oferecida no diálogo: é o que acontece a quem
     * não tem papel na app enquanto a Meta não aprovar o acesso. null: token inválido.
     */
    public function permissionStatuses(string $userToken): ?array
    {
        $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(self::GRAPH_URL . '/me/permissions', ['access_token' => $userToken]));
        if (! $r || ! $r->successful()) {
            return self::classify($r) === self::ERR_EXPIRED ? null : [];
        }

        return collect((array) $r->json('data', []))->mapWithKeys(fn ($p) => [(string) ($p['permission'] ?? '') => (string) ($p['status'] ?? '')])->all();
    }

    /**
     * Retira UMA permissão (DELETE /me/permissions/{permissão}). Nunca o DELETE
     * /me/permissions sem nome, que retiraria todas, incluindo a dos anúncios.
     */
    public function revokePermission(string $userToken, string $permission): array
    {
        if ($userToken === '' || ! in_array($permission, SocialConnection::SCOPES, true)) {
            return ['revoked' => false, 'error' => 'sem token'];
        }
        $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->delete(
            self::GRAPH_URL . '/me/permissions/' . $permission . '?' . http_build_query(['access_token' => $userToken])
        ));
        if ($r && $r->successful() && $r->json('success') === true) {
            return ['revoked' => true, 'error' => null];
        }

        return ['revoked' => false, 'error' => (string) ($r?->json('error.message') ?? 'sem resposta')];
    }

    /** Tipo de erro a partir da resposta da Graph API. */
    public static function classify(?Response $r): string
    {
        if (! $r) {
            return self::ERR_FAILED;
        }
        $code = (int) $r->json('error.code');
        if ($code === 190 || $code === 102) {
            return self::ERR_EXPIRED;
        }
        if ($code === 10 || ($code >= 200 && $code <= 299)) {
            return self::ERR_PERMISSION;
        }

        return self::ERR_FAILED;
    }

    private function failure(?Response $r): array
    {
        return [
            'ok' => false,
            'kind' => self::classify($r),
            'message' => mb_substr((string) ($r?->json('error.message') ?? ($r ? 'HTTP ' . $r->status() : 'Sem ligação à Meta.')), 0, 300),
        ];
    }

    /** Uma repetição em falhas transitórias (limite ou erro do servidor da Meta). */
    private function send(\Closure $call): ?Response
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $r = $call();
            } catch (ConnectionException) {
                $r = null;
            }
            if ($r && ! ($r->status() === 429 || $r->serverError())) {
                return $r;
            }
            if ($attempt < 2) {
                usleep(app()->environment('testing') ? 0 : 500_000);
            }
        }

        return $r;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
