<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialConnection;

/**
 * Versão simulada da Graph API das redes sociais, para os testes (nunca chama a Meta). As respostas
 * mudam-se pelas propriedades públicas; cada chamada fica registada em $calls, por ordem.
 */
class FakeMetaSocialClient implements MetaSocialClientInterface
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    public ?array $token = ['token' => 'tok-simulado', 'expires_at' => null, 'user_id' => '1000', 'scopes' => SocialConnection::SCOPES];

    /** @var array<int, array{id: string, name: string, access_token: string, instagram: ?array}> */
    public array $pages = [];

    /** @var array<string, string> permissão => granted|declined */
    public array $permissions = [];

    public array $followers = ['ok' => true, 'followers' => 100, 'follows' => null, 'media' => null, 'picture_url' => null, 'username' => null];

    public bool $revokeSucceeds = true;

    public function authUrl(string $state): string
    {
        $this->calls[] = ['authUrl', [$state]];

        return 'https://meta.simulada/dialog?state=' . urlencode($state);
    }

    public function exchangeCode(string $code): ?array
    {
        $this->calls[] = ['exchangeCode', [$code]];

        return $this->token;
    }

    public function pages(string $userToken): array
    {
        $this->calls[] = ['pages', [$userToken]];

        return ['ok' => true, 'pages' => $this->pages];
    }

    public function pageFollowers(string $pageId, string $pageToken): array
    {
        $this->calls[] = ['pageFollowers', [$pageId]];

        return $this->followers;
    }

    public function instagramFollowers(string $igId, string $pageToken): array
    {
        $this->calls[] = ['instagramFollowers', [$igId]];

        return $this->followers;
    }

    public function permissionState(string $userToken): string
    {
        $this->calls[] = ['permissionState', [$userToken]];

        return SocialConnection::STATUS_NOT_APPROVED;
    }

    public function permissionStatuses(string $userToken): ?array
    {
        $this->calls[] = ['permissionStatuses', [$userToken]];

        return $this->permissions;
    }

    public function revokePermission(string $userToken, string $permission): array
    {
        $this->calls[] = ['revokePermission', [$userToken, $permission]];

        return $this->revokeSucceeds ? ['revoked' => true, 'error' => null] : ['revoked' => false, 'error' => 'simulado'];
    }

    /** As chamadas de um método (os argumentos de cada uma). */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }
}
