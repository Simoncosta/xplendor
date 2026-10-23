<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\AgencyMetaConnection;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use Illuminate\Support\Collection;

/**
 * Um mesmo utilizador da Meta pode estar ligado em várias empresas (por exemplo, o Simon liga vários
 * clientes com o seu login). Revogar a permissão na Meta corta TODAS essas ligações, por isso
 * desligar uma empresa só desliga cá; a permissão só se revoga quando nenhuma outra ligação do mesmo
 * tipo usa esse utilizador. Na dúvida (ligações antigas sem utilizador conhecido), não se revoga: o
 * token apaga-se na XPLENDOR na mesma. A ligação da Meta de uma agência (Parceiro 2) conta como
 * outra ligação para as redes e para os anúncios.
 */
final class SharedMetaUser
{
    /** Pode revogar o ads_read desta ligação dos anúncios? */
    public static function canRevokeAds(CompanyIntegration $integration): bool
    {
        $others = CompanyIntegration::where('platform', 'meta')->whereKeyNot($integration->id)
            ->where('status', '!=', 'revoked')->get(['id', 'meta_user_id', 'access_token'])
            ->filter(fn (CompanyIntegration $i) => (string) $i->access_token !== '');

        return self::alone($integration->meta_user_id, $others) && self::alone($integration->meta_user_id, self::agencies());
    }

    /** Pode revogar as permissões das redes desta ligação? */
    public static function canRevokeSocial(SocialConnection $connection): bool
    {
        return self::alone($connection->meta_user_id, self::activeSocial()->where('id', '!=', $connection->id))
            && self::alone($connection->meta_user_id, self::agencies());
    }

    /**
     * Na autorização das redes com permissões em falta (nada se guarda): pode revogar as concedidas?
     * Só se o utilizador da Meta não estiver em nenhuma ligação das redes ainda ativa (nem na da agência).
     */
    public static function canRevokeNewSocialGrant(?string $metaUserId): bool
    {
        return self::alone($metaUserId, self::activeSocial()) && self::alone($metaUserId, self::agencies());
    }

    /** As ligações da Meta das agências (Parceiro 2): também usam as permissões das redes e o ads_read. */
    private static function agencies(): Collection
    {
        return AgencyMetaConnection::get(['id', 'meta_user_id']);
    }

    /**
     * Parceiro 2: ao desligar a ligação da agência, as permissões que se podem revogar sem cortar
     * outra ligação do mesmo utilizador da Meta: o business_management se nenhuma outra agência o
     * usa; as das redes se nenhuma ligação das redes ativa o usa; o ads_read se nenhuma ligação dos
     * anúncios o usa. Utilizador desconhecido: nenhuma.
     *
     * @return list<string>
     */
    public static function revocableAgencyScopes(AgencyMetaConnection $connection): array
    {
        $agencies = self::agencies()->where('id', '!=', $connection->id);
        $ads = CompanyIntegration::where('platform', 'meta')->where('status', '!=', 'revoked')->get(['id', 'meta_user_id', 'access_token'])
            ->filter(fn (CompanyIntegration $i) => (string) $i->access_token !== '');
        $user = $connection->meta_user_id;
        if ($user === null || ! self::alone($user, $agencies)) {
            return [];
        }
        $out = ['business_management'];
        if (self::alone($user, self::activeSocial())) {
            array_push($out, ...SocialConnection::SCOPES);
        }
        if (self::alone($user, $ads)) {
            $out[] = 'ads_read';
        }

        return array_values(array_intersect(AgencyMetaConnection::SCOPES, $out));
    }

    private static function activeSocial(): Collection
    {
        return SocialConnection::where('status', '!=', SocialConnection::STATUS_REVOKED)->whereNotNull('access_token')->get(['id', 'meta_user_id']);
    }

    /** Sozinho só com o utilizador conhecido e nenhuma outra ligação dele (nem nenhuma de utilizador desconhecido). */
    private static function alone(?string $metaUserId, Collection $others): bool
    {
        foreach ($others as $other) {
            if ($metaUserId === null || $other->meta_user_id === null || (string) $other->meta_user_id === $metaUserId) {
                return false;
            }
        }

        return true;
    }
}
