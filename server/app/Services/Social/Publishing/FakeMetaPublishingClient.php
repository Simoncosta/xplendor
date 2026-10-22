<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

/**
 * Versão simulada da publicação na Meta (nunca chama a Meta). Dá identificadores previsíveis e
 * links falsos num domínio reservado (.invalid), para nunca se confundirem com publicações reais.
 * Para os testes: $failures[método] devolve essa falha uma vez (ou sempre, com $failAlways), as
 * respostas mudam-se pelas propriedades e cada chamada fica registada em $calls.
 */
class FakeMetaPublishingClient implements MetaPublishingClient
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    /** @var array<string, array> método => falha (['ok' => false, 'kind' => ..., ...]) */
    public array $failures = [];

    public bool $failAlways = false;

    public int $quotaUsed = 0;

    public int $quotaTotal = 100;

    /** Estado devolvido por containerStatus(). */
    public string $containerState = 'FINISHED';

    private int $next = 1;

    public static function failure(string $kind, string $message, ?string $code = null): array
    {
        return ['ok' => false, 'kind' => $kind, 'code' => $code, 'message' => $message];
    }

    public function instagramQuota(string $igUserId, string $token): array
    {
        return $this->answer('instagramQuota', [$igUserId], fn () => ['used' => $this->quotaUsed, 'total' => $this->quotaTotal]);
    }

    public function createInstagramContainer(string $igUserId, string $token, array $payload): array
    {
        return $this->answer('createInstagramContainer', [$igUserId, $payload], fn () => ['container_id' => 'c-' . $this->id()]);
    }

    public function containerStatus(string $containerId, string $token): array
    {
        return $this->answer('containerStatus', [$containerId], fn () => ['status' => $this->containerState]);
    }

    public function publishInstagram(string $igUserId, string $token, string $containerId): array
    {
        return $this->answer('publishInstagram', [$igUserId, $containerId], fn () => ['media_id' => 'ig-' . $this->id()]);
    }

    public function publishFacebook(string $pageId, string $token, array $payload): array
    {
        return $this->answer('publishFacebook', [$pageId, $payload], fn () => ['post_id' => $pageId . '_' . $this->id()]);
    }

    public function permalink(string $network, string $mediaId, string $token): array
    {
        return $this->answer('permalink', [$network, $mediaId], fn () => ['permalink' => "https://{$network}.simulado.invalid/p/{$mediaId}"]);
    }

    /** As chamadas de um método (os argumentos de cada uma). */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    private function answer(string $method, array $args, \Closure $ok): array
    {
        $this->calls[] = [$method, $args];
        if (isset($this->failures[$method])) {
            $failure = $this->failures[$method];
            if (! $this->failAlways) {
                unset($this->failures[$method]);
            }

            return $failure;
        }

        return ['ok' => true] + $ok();
    }

    private function id(): string
    {
        return (string) $this->next++;
    }
}
