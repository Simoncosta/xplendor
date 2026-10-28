<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\AgencyMetaConnection;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\SocialPublishingGrant;
use Illuminate\Support\Collection;

/**
 * Um mesmo utilizador da Meta pode estar ligado em várias empresas e em vários fluxos (por exemplo,
 * o Simon liga vários clientes com o seu login, e a agência usa o mesmo login). As permissões na Meta
 * são do par utilizador e app, não de um token: revogar uma permissão corta-a em TODAS as ligações
 * desse utilizador. Por isso, ao desligar um fluxo, só se revogam as permissões desse fluxo que
 * nenhuma outra ligação ativa do mesmo utilizador usa, em qualquer empresa ou fluxo (decisão de
 * 22/10, a mesma dos tokens partilhados). Na dúvida (um utilizador desconhecido, deste lado ou numa
 * outra ligação que use a permissão), não se revoga: o token apaga-se na XPLENDOR na mesma.
 *
 * Quem usa o quê:
 * - leitura das redes (SocialConnection): as três permissões de leitura;
 * - publicação (SocialPublishingGrant): as duas de publicar, mais as de leitura (precisa da lista
 *   das páginas para publicar);
 * - agência (AgencyMetaConnection, Parceiro 2): business_management, as de leitura e o ads_read;
 * - anúncios (CompanyIntegration meta): o ads_read.
 */
final class SharedMetaUser
{
    public const FLOW_SOCIAL = 'social';
    public const FLOW_PUBLISH = 'publish';
    public const FLOW_AGENCY = 'agency';
    public const FLOW_ADS = 'ads';

    /**
     * As permissões de $wanted que se podem revogar ao desligar a ligação $self ("fluxo:id", ou null
     * numa autorização que nada guardou): as que nenhuma outra ligação ativa do mesmo utilizador da
     * Meta usa. Com um utilizador desconhecido, qualquer outra ligação que use a permissão pode ser
     * a dele: fica.
     *
     * @param  list<string>  $wanted
     * @return list<string>
     */
    public static function revocable(?string $metaUserId, array $wanted, ?string $self): array
    {
        $metaUserId = self::user($metaUserId);
        $others = self::connections()->reject(fn (array $c) => $c['key'] === $self);

        return array_values(array_filter(array_unique($wanted), function (string $permission) use ($others, $metaUserId) {
            foreach ($others as $c) {
                // Desconhecido (deste lado ou do outro): pode ser o mesmo utilizador, por isso fica.
                if (in_array($permission, $c['scopes'], true) && ($metaUserId === null || $c['meta_user_id'] === null || $c['meta_user_id'] === $metaUserId)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Pode revogar o ads_read desta ligação dos anúncios? */
    public static function canRevokeAds(CompanyIntegration $integration): bool
    {
        return self::revocable(self::user($integration->meta_user_id), ['ads_read'], self::FLOW_ADS . ':' . $integration->id) !== [];
    }

    /** As permissões das redes que se podem revogar ao desligar esta ligação de leitura. */
    public static function revocableSocialScopes(SocialConnection $connection): array
    {
        return self::revocable(self::user($connection->meta_user_id), SocialConnection::SCOPES, self::FLOW_SOCIAL . ':' . $connection->id);
    }

    /** Pode revogar todas as permissões das redes desta ligação? */
    public static function canRevokeSocial(SocialConnection $connection): bool
    {
        return array_diff(SocialConnection::SCOPES, self::revocableSocialScopes($connection)) === [];
    }

    /**
     * Na autorização das redes com permissões em falta (nada se guarda): as concedidas que se podem
     * revogar sem cortar outra ligação do mesmo utilizador da Meta.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function revocableNewSocialGrant(?string $metaUserId, array $granted): array
    {
        return self::revocable(self::user($metaUserId), array_values(array_intersect($granted, SocialConnection::SCOPES)), null);
    }

    /** Compatibilidade: pode revogar alguma das permissões de leitura de uma autorização que nada guardou? */
    public static function canRevokeNewSocialGrant(?string $metaUserId): bool
    {
        return self::revocableNewSocialGrant($metaUserId, SocialConnection::SCOPES) !== [];
    }

    /** As permissões de publicar que se podem revogar ao desligar a publicação desta empresa. */
    public static function revocablePublishingScopes(SocialPublishingGrant $grant): array
    {
        return self::revocable(self::user($grant->meta_user_id), SocialPublishingGrant::SCOPES, self::FLOW_PUBLISH . ':' . $grant->id);
    }

    /** Parceiro 2: as permissões da ligação da agência que se podem revogar ao desligá-la. */
    public static function revocableAgencyScopes(AgencyMetaConnection $connection): array
    {
        return self::revocable(self::user($connection->meta_user_id), AgencyMetaConnection::SCOPES, self::FLOW_AGENCY . ':' . $connection->id);
    }

    /**
     * Todas as ligações ativas da Meta, de todos os fluxos: a chave, o utilizador e as permissões
     * que usam.
     *
     * @return Collection<int, array{key: string, meta_user_id: ?string, scopes: list<string>}>
     */
    private static function connections(): Collection
    {
        $row = fn (string $flow, $model, array $scopes) => ['key' => $flow . ':' . $model->id, 'meta_user_id' => self::user($model->meta_user_id), 'scopes' => $scopes];

        return collect()
            ->concat(SocialConnection::where('status', '!=', SocialConnection::STATUS_REVOKED)->whereNotNull('access_token')->get(['id', 'meta_user_id'])
                ->map(fn ($c) => $row(self::FLOW_SOCIAL, $c, SocialConnection::SCOPES)))
            ->concat(SocialPublishingGrant::get(['id', 'meta_user_id'])
                ->map(fn ($g) => $row(self::FLOW_PUBLISH, $g, [...SocialPublishingGrant::SCOPES, ...SocialConnection::SCOPES])))
            ->concat(AgencyMetaConnection::get(['id', 'meta_user_id'])
                ->map(fn ($a) => $row(self::FLOW_AGENCY, $a, AgencyMetaConnection::SCOPES)))
            ->concat(CompanyIntegration::where('platform', 'meta')->where('status', '!=', 'revoked')->get(['id', 'meta_user_id', 'access_token'])
                ->filter(fn (CompanyIntegration $i) => (string) $i->access_token !== '')
                ->map(fn ($i) => $row(self::FLOW_ADS, $i, ['ads_read'])))
            ->values();
    }

    private static function user(mixed $metaUserId): ?string
    {
        return $metaUserId === null || $metaUserId === '' ? null : (string) $metaUserId;
    }
}
