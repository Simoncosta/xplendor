<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

/**
 * Parceiro 3: a leitura dos portfólios da Meta (Business Manager) da agência e dos ativos de cada um,
 * os da agência (owned) e os partilhados pelos clientes (client). Só leitura. A implementação real é o
 * MetaBusinessClient; os testes usam o FakeMetaBusinessClient. Cada leitura devolve ['ok' => bool, ...]
 * e, quando falha, o tipo de erro (expired | permission | failed) e a mensagem.
 */
interface MetaBusinessClientInterface
{
    /** @return array{ok: bool, businesses?: list<array{id: string, name: string, verification_status: ?string}>, kind?: string, message?: string} */
    public function businesses(string $userToken): array;

    /**
     * Os ativos de um portfólio, cada um com a relação (owned | client).
     *
     * @return array{ok: bool, pages?: list<array>, ad_accounts?: list<array>, instagram?: list<array>, kind?: string, message?: string}
     */
    public function assets(string $userToken, string $businessId): array;

    /**
     * Parceiro 4: o token de uma Página (GET /{page-id}?fields=access_token). A Meta só o dá se a pessoa
     * tiver uma tarefa na Página (atribuída no portfólio); sem ela, access_token vem null.
     *
     * @return array{ok: bool, access_token?: ?string, kind?: string, message?: string}
     */
    public function pageToken(string $userToken, string $pageId): array;
}
