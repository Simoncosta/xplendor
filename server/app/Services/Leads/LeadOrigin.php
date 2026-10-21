<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * A origem de uma lead em texto legível ("QR do stand", "Anúncios Meta", "Pesquisa"), a partir
 * do que o site envia: o canal que o script do site deduz (paid, organic_social, organic_search,
 * email, referral, utm, direct), o utm_source e o origem=qr da folha da viatura. Um só sítio para
 * a lista, o funil, o detalhe e os avisos.
 */
final class LeadOrigin
{
    public const QR = 'qr';

    private const SOCIAL = ['facebook', 'fb', 'instagram', 'ig', 'meta', 'tiktok', 'linkedin', 'youtube', 'whatsapp'];

    public static function label(?string $channel, ?string $utmSource, ?string $origin = null): string
    {
        $source = mb_strtolower(trim((string) $utmSource));
        $channel = mb_strtolower(trim((string) $channel));
        if (mb_strtolower((string) $origin) === self::QR || $source === self::QR) {
            return 'QR do stand';
        }

        return match ($channel) {
            'paid' => match (true) {
                in_array($source, self::SOCIAL, true) => 'Anúncios Meta',
                str_contains($source, 'google') => 'Anúncios Google',
                default => 'Anúncios pagos',
            },
            'organic_social' => 'Redes sociais',
            'organic_search' => 'Pesquisa',
            'email' => 'Email',
            'referral' => 'Outro site',
            'utm' => $source !== '' ? 'Campanha (' . $source . ')' : 'Campanha',
            '', 'direct' => $source !== '' ? 'Campanha (' . $source . ')' : 'Direto',
            default => ucfirst($channel),
        };
    }
}
