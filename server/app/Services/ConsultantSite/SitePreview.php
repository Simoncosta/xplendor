<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Models\ConsultantSite;
use Carbon\CarbonImmutable;

/**
 * Site do consultor: a pré-visualização do site (também em rascunho) por um link assinado e
 * temporário. O token é "site.validade.assinatura" (HMAC-SHA256 com a APP_KEY); não fica guardado.
 * O site (Next) guarda-o num cookie da pré-visualização e envia-o à API no X-Site-Preview; sem ele,
 * um site em rascunho continua a dar 404. Em pré-visualização: faixa visível, noindex, sem medição.
 */
final class SitePreview
{
    /** @return array{url: string, token: string, expires_at: string} */
    public static function issue(ConsultantSite $site): array
    {
        $expires = CarbonImmutable::now()->addMinutes(max(1, (int) config('consultor_site.preview_minutes')));
        $token = "{$site->id}.{$expires->getTimestamp()}." . self::sign((int) $site->id, $expires->getTimestamp());
        $base = rtrim((string) (config('consultor_site.preview_base_url') ?: 'https://' . $site->primaryHost()), '/');

        return ['url' => "{$base}/pre-visualizar?token=" . rawurlencode($token), 'token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    public static function valid(?string $token, ConsultantSite $site): bool
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return false;
        }
        [$siteId, $expires, $sig] = $parts;

        return (int) $siteId === (int) $site->id && (int) $expires > CarbonImmutable::now()->getTimestamp() && hash_equals(self::sign((int) $siteId, (int) $expires), $sig);
    }

    private static function sign(int $siteId, int $expires): string
    {
        return hash_hmac('sha256', "consultant-site-preview|{$siteId}|{$expires}", (string) config('app.key'));
    }
}
