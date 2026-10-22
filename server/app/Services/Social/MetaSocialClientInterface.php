<?php

declare(strict_types=1);

namespace App\Services\Social;

/**
 * Contrato das chamadas à Graph API da ligação das redes sociais (e da verificação das permissões
 * dos anúncios). A implementação real é o MetaSocialClient; os testes usam o FakeMetaSocialClient.
 * Cada leitura devolve ['ok' => bool, ...] e, quando falha, o tipo de erro (MetaSocialClient::classify).
 */
interface MetaSocialClientInterface
{
    /** URL de autorização com as permissões das redes. */
    public function authUrl(string $state): string;

    /** Troca o code pelo token de longa duração: token, expires_at, user_id, scopes; null se falhar. */
    public function exchangeCode(string $code): ?array;

    /** Páginas do utilizador, com a conta de Instagram ligada a cada uma e o token da Página. */
    public function pages(string $userToken): array;

    public function pageFollowers(string $pageId, string $pageToken): array;

    public function instagramFollowers(string $igId, string $pageToken): array;

    /** Depois de uma recusa por permissão: not_approved, permission_removed ou expired. */
    public function permissionState(string $userToken): string;

    /** Estado de cada permissão (granted | declined); null: token inválido. */
    public function permissionStatuses(string $userToken): ?array;

    /** Retira UMA permissão das redes. @return array{revoked: bool, error: ?string} */
    public function revokePermission(string $userToken, string $permission): array;
}
