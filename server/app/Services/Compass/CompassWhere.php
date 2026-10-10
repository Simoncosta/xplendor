<?php

declare(strict_types=1);

namespace App\Services\Compass;

use App\Models\EditorialPost;
use App\Models\SocialConnection;
use App\Services\Brand\CreativeFormatAdvisor;

/**
 * O "Onde": a publicação é multicanal, sempre Instagram e Facebook, cada uma com o formato das
 * regras de formato; "connected" diz se as redes da empresa estão ligadas (o ecrã mostra uma
 * nota com a ligação às Integrações quando não estão).
 */
final class CompassWhere
{
    public const DEFAULT_FORMAT = ['instagram' => 'ig_feed_image', 'facebook' => 'fb_photos'];

    public function __construct(private readonly CreativeFormatAdvisor $formats) {}

    /** @return array{networks: list<array>, connected: bool} */
    public function networks(int $companyId): array
    {
        $connection = SocialConnection::with('accounts')->where('company_id', $companyId)->first();
        $connected = $connection && in_array($connection->status, SocialConnection::READABLE, true) && $connection->accounts->isNotEmpty();
        $out = [];
        foreach (EditorialPost::NETWORKS as $network) {
            $rec = $this->formats->recommend($companyId, $network);
            $key = $rec['ranked'][0]['format_key'] ?? self::DEFAULT_FORMAT[$network];
            $out[] = ['network' => $network, 'label' => $network === 'instagram' ? 'Instagram' : 'Facebook', 'format_key' => $key,
                'format_label' => EditorialPost::MEDIA_FORMAT_LABELS[$key] ?? $key,
                'format_source' => $rec['ranked'] !== [] ? ($rec['source_label'] ?? null) : null];
        }

        return ['networks' => $out, 'connected' => $connected];
    }
}
