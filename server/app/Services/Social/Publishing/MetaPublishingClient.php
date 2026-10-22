<?php

declare(strict_types=1);

namespace App\Services\Social\Publishing;

/**
 * Contrato das chamadas de publicação à Graph API (F2). Até à F2.4 só existe a versão simulada
 * (FakeMetaPublishingClient); a real chega com as chamadas ao Instagram e ao Facebook.
 *
 * Cada chamada devolve ['ok' => true, ...] ou, quando falha,
 * ['ok' => false, 'kind' => KIND_*, 'code' => ?string, 'message' => string]. Só os erros
 * "transient" se repetem (aos 2, 10 e 30 minutos, na F2.7).
 */
interface MetaPublishingClient
{
    /** Erro passageiro (limite de pedidos, erro do servidor da Meta, contentor ainda a processar). */
    public const KIND_TRANSIENT = 'transient';

    /** Erro que não se resolve a repetir (formato, ficheiro, permissão). */
    public const KIND_PERMANENT = 'permanent';

    /** Token inválido ou expirado: é preciso voltar a ligar. */
    public const KIND_EXPIRED = 'expired';

    /** Quota do Instagram nas últimas 24 horas: ['used' => int, 'total' => int]. */
    public function instagramQuota(string $igUserId, string $token): array;

    /**
     * Cria um contentor no Instagram: ['container_id' => string].
     *
     * @param array{media_type: string, image_url?: string, video_url?: string, cover_url?: string, caption?: string, children?: string[], is_carousel_item?: bool} $payload
     */
    public function createInstagramContainer(string $igUserId, string $token, array $payload): array;

    /** Estado do contentor: ['status' => FINISHED|IN_PROGRESS|ERROR|EXPIRED|PUBLISHED]. */
    public function containerStatus(string $containerId, string $token): array;

    /** Publica o contentor: ['media_id' => string]. */
    public function publishInstagram(string $igUserId, string $token, string $containerId): array;

    /**
     * Publica na Página de Facebook: ['post_id' => string].
     *
     * @param array{type: string, message?: string, urls?: string[]} $payload type: text|photo|photos|video
     */
    public function publishFacebook(string $pageId, string $token, array $payload): array;

    /** Link público da publicação: ['permalink' => string]. */
    public function permalink(string $network, string $mediaId, string $token): array;
}
