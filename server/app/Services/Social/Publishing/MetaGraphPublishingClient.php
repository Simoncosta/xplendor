<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

use App\Services\Social\MetaSocialClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * F2.4 · As chamadas reais de publicação à Graph API, com o token da Página
 * (social_connection_accounts.page_access_token). Só é usado com o driver "meta"
 * (config/social_publishing.php) e com a guarda das chamadas externas ligada; nos testes,
 * Http::fake. Permissões: instagram_content_publish, instagram_basic, pages_show_list e
 * pages_read_engagement (Instagram); pages_manage_posts (Facebook, F2.6).
 *
 * Nunca repete sozinho um pedido de publicação: as tentativas são do publicador (F2.7), que
 * consulta antes o estado do contentor (nunca publicar duas vezes).
 */
class MetaGraphPublishingClient implements MetaPublishingClient
{
    private const TIMEOUT = 30;

    /** Erros da Meta que passam sozinhos (limites de pedidos, indisponibilidade, ficheiro a processar). */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 368, 613, 9004, 9007, 80001, 80002];

    /** Erros de token: é preciso voltar a ligar. */
    private const EXPIRED_CODES = [102, 190, 463, 467];

    /** Motivos em linguagem simples para os erros mais comuns. */
    private const MESSAGES = [
        10 => 'A XPLENDOR não tem permissão para publicar nesta conta. Ligue a publicação em Integrações › Redes sociais.',
        200 => 'A XPLENDOR não tem permissão para publicar nesta conta. Ligue a publicação em Integrações › Redes sociais.',
        9004 => 'A Meta não conseguiu ir buscar o ficheiro. Vai tentar de novo.',
        9007 => 'O Instagram ainda está a processar o ficheiro.',
        36003 => 'A proporção da imagem não é aceite pelo Instagram (entre 4:5 e 1,91:1).',
        36001 => 'A resolução da imagem não é aceite pelo Instagram.',
        2207026 => 'O formato do vídeo não é aceite pelo Instagram (MP4 com H.264 e AAC).',
    ];

    public function instagramQuota(string $igUserId, string $token): array
    {
        $r = $this->call(fn () => Http::timeout(self::TIMEOUT)->get(self::url($igUserId . '/content_publishing_limit'), [
            'fields' => 'config,quota_usage', 'access_token' => $token,
        ]));
        if (! $r['ok']) {
            return $r;
        }
        $row = $r['json']['data'][0] ?? [];

        return ['ok' => true, 'used' => (int) ($row['quota_usage'] ?? 0), 'total' => (int) ($row['config']['quota_total'] ?? 100)];
    }

    public function createInstagramContainer(string $igUserId, string $token, array $payload): array
    {
        $params = ['access_token' => $token];
        $type = $payload['media_type'] ?? 'IMAGE';
        if ($type !== 'IMAGE') {
            $params['media_type'] = $type; // a imagem do feed vai sem media_type
        }
        foreach (['image_url', 'video_url', 'cover_url', 'thumb_offset', 'caption'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '') {
                $params[$field] = (string) $payload[$field];
            }
        }
        if (! empty($payload['is_carousel_item'])) {
            $params['is_carousel_item'] = 'true';
            unset($params['caption']);
        }
        if (isset($payload['share_to_feed'])) {
            $params['share_to_feed'] = $payload['share_to_feed'] ? 'true' : 'false';
        }
        if (! empty($payload['children'])) {
            $params['children'] = implode(',', $payload['children']);
        }
        $r = $this->call(fn () => Http::asForm()->timeout(self::TIMEOUT)->post(self::url($igUserId . '/media'), $params));

        return $r['ok'] ? ['ok' => true, 'container_id' => (string) ($r['json']['id'] ?? '')] : $r;
    }

    public function containerStatus(string $containerId, string $token): array
    {
        $r = $this->call(fn () => Http::timeout(self::TIMEOUT)->get(self::url($containerId), ['fields' => 'status_code', 'access_token' => $token]));

        return $r['ok'] ? ['ok' => true, 'status' => (string) ($r['json']['status_code'] ?? 'IN_PROGRESS')] : $r;
    }

    public function publishInstagram(string $igUserId, string $token, string $containerId): array
    {
        $r = $this->call(fn () => Http::asForm()->timeout(self::TIMEOUT)->post(self::url($igUserId . '/media_publish'), [
            'creation_id' => $containerId, 'access_token' => $token,
        ]));

        return $r['ok'] ? ['ok' => true, 'media_id' => (string) ($r['json']['id'] ?? '')] : $r;
    }

    public function publishFacebook(string $pageId, string $token, array $payload): array
    {
        return self::failure(self::KIND_PERMANENT, 'O Facebook ainda não sai sozinho: publique à mão e marque como publicada.', 'not_yet');
    }

    public function permalink(string $network, string $mediaId, string $token): array
    {
        $field = $network === 'instagram' ? 'permalink' : 'permalink_url';
        $r = $this->call(fn () => Http::timeout(self::TIMEOUT)->get(self::url($mediaId), [
            'fields' => $network === 'instagram' ? 'permalink,timestamp' : 'permalink_url', 'access_token' => $token,
        ]));
        if (! $r['ok']) {
            return $r;
        }
        $link = (string) ($r['json'][$field] ?? '');
        if ($link !== '' && str_starts_with($link, '/')) {
            $link = 'https://www.facebook.com' . $link;
        }

        return $link !== '' ? ['ok' => true, 'permalink' => $link] : self::failure(self::KIND_TRANSIENT, 'A Meta não devolveu o link.');
    }

    /**
     * Um pedido à Graph API: ['ok' => true, 'json' => array] ou a falha, já classificada.
     */
    private function call(\Closure $request): array
    {
        try {
            /** @var Response $r */
            $r = $request();
        } catch (ConnectionException) {
            return self::failure(self::KIND_TRANSIENT, 'Sem ligação à Meta. Vai tentar de novo.', 'connection');
        }
        if ($r->successful() && ! isset($r->json()['error'])) {
            return ['ok' => true, 'json' => (array) $r->json()];
        }

        return self::classify($r->status(), (array) ($r->json('error') ?? []));
    }

    /** Erro da Meta → tipo (passageiro, definitivo ou token) e o motivo em linguagem simples. */
    public static function classify(int $status, array $error): array
    {
        $code = (int) ($error['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);
        $kind = match (true) {
            in_array($code, self::EXPIRED_CODES, true) => self::KIND_EXPIRED,
            $status === 429 || $status >= 500 || ! empty($error['is_transient']) || in_array($code, self::TRANSIENT_CODES, true) => self::KIND_TRANSIENT,
            default => self::KIND_PERMANENT,
        };
        $message = self::MESSAGES[$sub] ?? self::MESSAGES[$code] ?? match ($kind) {
            self::KIND_EXPIRED => 'A ligação às redes sociais expirou. Volte a ligar em Integrações › Redes sociais.',
            self::KIND_TRANSIENT => 'A Meta está ocupada ou indisponível. Vai tentar de novo.',
            default => 'A Meta recusou a publicação' . ($code ? " (código {$code})" : '') . '.',
        };

        return self::failure($kind, $message, $code ? (string) ($sub ? "{$code}.{$sub}" : $code) : (string) $status);
    }

    private static function failure(string $kind, string $message, ?string $code = null): array
    {
        return ['ok' => false, 'kind' => $kind, 'code' => $code, 'message' => $message];
    }

    private static function url(string $path): string
    {
        return MetaSocialClient::GRAPH_URL . '/' . ltrim($path, '/');
    }
}
