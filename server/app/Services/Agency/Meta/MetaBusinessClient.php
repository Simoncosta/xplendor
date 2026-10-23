<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

use App\Services\Social\MetaSocialClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Parceiro 3: a Graph API dos portfólios (só leitura), com o token de utilizador da ligação da agência.
 *  · GET /me/businesses?fields=id,name,verification_status
 *  · Páginas: /{business-id}/owned_pages e /client_pages, fields=id,name,instagram_business_account{id,username}
 *  · Contas de anúncios: /owned_ad_accounts e /client_ad_accounts, fields=account_id,name,currency,account_status
 *  · Instagram: as arestas de INSTAGRAM_EDGES (os nomes ficam a confirmar na documentação da Meta).
 * Segue a paginação (paging.next) até 10 páginas de 100.
 */
class MetaBusinessClient implements MetaBusinessClientInterface
{
    public const PAGE_EDGES = ['owned' => 'owned_pages', 'client' => 'client_pages'];
    public const AD_ACCOUNT_EDGES = ['owned' => 'owned_ad_accounts', 'client' => 'client_ad_accounts'];
    // A confirmar na documentação da Meta (não consultada na noite de 23/10, sem pedidos à Meta).
    public const INSTAGRAM_EDGES = ['owned' => 'owned_instagram_accounts', 'client' => 'client_instagram_accounts'];

    private const TIMEOUT = 15;
    private const MAX_PAGES = 10;

    public function businesses(string $userToken): array
    {
        $out = $this->list('/me/businesses', ['fields' => 'id,name,verification_status'], $userToken);
        if (! $out['ok']) {
            return $out;
        }

        return ['ok' => true, 'businesses' => array_map(fn ($b) => [
            'id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? ''), 'verification_status' => $b['verification_status'] ?? null,
        ], $out['data'])];
    }

    public function assets(string $userToken, string $businessId): array
    {
        $result = ['ok' => true, 'pages' => [], 'ad_accounts' => [], 'instagram' => []];
        foreach (self::PAGE_EDGES as $relation => $edge) {
            $r = $this->list("/{$businessId}/{$edge}", ['fields' => 'id,name,instagram_business_account{id,username}'], $userToken);
            if (! $r['ok']) {
                return $r;
            }
            foreach ($r['data'] as $p) {
                $ig = $p['instagram_business_account'] ?? null;
                $result['pages'][] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'relation' => $relation,
                    'instagram' => $ig ? ['id' => (string) $ig['id'], 'username' => $ig['username'] ?? null] : null];
            }
        }
        foreach (self::AD_ACCOUNT_EDGES as $relation => $edge) {
            $r = $this->list("/{$businessId}/{$edge}", ['fields' => 'account_id,name,currency,account_status'], $userToken);
            if (! $r['ok']) {
                return $r;
            }
            foreach ($r['data'] as $a) {
                $result['ad_accounts'][] = ['account_id' => (string) ($a['account_id'] ?? ''), 'name' => (string) ($a['name'] ?? ''), 'relation' => $relation,
                    'currency' => $a['currency'] ?? null, 'account_status' => isset($a['account_status']) ? (int) $a['account_status'] : null];
            }
        }
        foreach (self::INSTAGRAM_EDGES as $relation => $edge) {
            $r = $this->list("/{$businessId}/{$edge}", ['fields' => 'id,username'], $userToken);
            if (! $r['ok']) {
                return $r;
            }
            foreach ($r['data'] as $i) {
                $result['instagram'][] = ['id' => (string) $i['id'], 'username' => $i['username'] ?? null, 'relation' => $relation];
            }
        }

        return $result;
    }

    public function pageToken(string $userToken, string $pageId): array
    {
        $r = $this->send(fn () => Http::timeout(self::TIMEOUT)->get(MetaSocialClient::GRAPH_URL . '/' . $pageId, ['fields' => 'access_token', 'access_token' => $userToken]));
        if (! $r || ! $r->successful()) {
            return ['ok' => false, 'kind' => MetaSocialClient::classify($r),
                'message' => mb_substr((string) ($r?->json('error.message') ?? ($r ? 'HTTP ' . $r->status() : 'Sem ligação à Meta.')), 0, 300)];
        }
        $token = (string) ($r->json('access_token') ?? '');

        return ['ok' => true, 'access_token' => $token !== '' ? $token : null];
    }

    /** @return array{ok: bool, data?: list<array>, kind?: string, message?: string} */
    private function list(string $path, array $query, string $userToken): array
    {
        $data = [];
        $url = MetaSocialClient::GRAPH_URL . $path;
        $query += ['limit' => 100, 'access_token' => $userToken];
        for ($i = 0; $i < self::MAX_PAGES && $url; $i++) {
            // O "next" já traz os parâmetros: sem query (um 'query' vazio apagava a do endereço).
            $r = $this->send(fn () => $query === [] ? Http::timeout(self::TIMEOUT)->get($url) : Http::timeout(self::TIMEOUT)->get($url, $query));
            if (! $r || ! $r->successful()) {
                return ['ok' => false, 'kind' => MetaSocialClient::classify($r),
                    'message' => mb_substr((string) ($r?->json('error.message') ?? ($r ? 'HTTP ' . $r->status() : 'Sem ligação à Meta.')), 0, 300)];
            }
            array_push($data, ...array_values((array) $r->json('data', [])));
            $url = $r->json('paging.next');
            $query = [];
        }

        return ['ok' => true, 'data' => $data];
    }

    /** Uma repetição em falhas transitórias (limite ou erro do servidor da Meta). */
    private function send(\Closure $call): ?Response
    {
        $r = null;
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
}
