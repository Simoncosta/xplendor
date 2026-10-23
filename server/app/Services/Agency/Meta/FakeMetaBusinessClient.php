<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

/**
 * Versão simulada da leitura dos portfólios, para os testes (nunca chama a Meta). As respostas mudam-se
 * pelas propriedades públicas; cada chamada fica registada em $calls.
 */
class FakeMetaBusinessClient implements MetaBusinessClientInterface
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    /** @var list<array{id: string, name: string, verification_status: ?string}> */
    public array $businesses = [];

    /** @var array<string, array{pages: list<array>, ad_accounts: list<array>, instagram: list<array>}> por portfólio */
    public array $assets = [];

    /** Uma falha a devolver em todas as leituras (por exemplo ['ok' => false, 'kind' => 'expired', 'message' => '…']). */
    public ?array $failure = null;

    public function businesses(string $userToken): array
    {
        $this->calls[] = ['businesses', [$userToken]];

        return $this->failure ?? ['ok' => true, 'businesses' => $this->businesses];
    }

    public function assets(string $userToken, string $businessId): array
    {
        $this->calls[] = ['assets', [$userToken, $businessId]];

        return $this->failure ?? ['ok' => true] + ($this->assets[$businessId] ?? ['pages' => [], 'ad_accounts' => [], 'instagram' => []]);
    }

    /** @var array<string, ?string> o token de cada Página (null = sem tarefa na Página) */
    public array $pageTokens = [];

    public function pageToken(string $userToken, string $pageId): array
    {
        $this->calls[] = ['pageToken', [$userToken, $pageId]];

        return $this->failure ?? ['ok' => true, 'access_token' => $this->pageTokens[$pageId] ?? null];
    }
}
