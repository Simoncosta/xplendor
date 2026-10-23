<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\AgencyAssetLink;
use App\Models\CompanyConnectionEvent;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Integrations\MetaAdsDisconnector;
use App\Services\Social\SocialConnectionService;
use Illuminate\Support\Facades\DB;

/**
 * As ligações à Meta feitas pela agência numa empresa gerida (anúncios da Meta e redes
 * sociais). "Feita pela agência": ligada por uma pessoa da agência, ou sem registo de quem a
 * ligou (ligações anteriores a esse registo). As ligações autorizadas pelo cliente no link de
 * configuração (setup_link_id) são do cliente: não contam como da agência. Quando a relação termina, o admin do cliente
 * escolhe mantê-las ou desligá-las; sem admin, desligam-se.
 */
class AgencyConnectionsService
{
    public function __construct(
        private readonly MetaAdsDisconnector $metaAds,
        private readonly SocialConnectionService $social,
    ) {}

    /** @return array<int, array{kind: string, label: string}> */
    public function agencyMade(int $companyId, int $agencyId): array
    {
        $agencyUsers = User::where('company_id', $agencyId)->pluck('id')->all();
        $byAgency = fn (?int $userId, ?int $setupLinkId) => $setupLinkId === null && ($userId === null || in_array($userId, $agencyUsers, true));
        $out = [];

        $meta = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        if ($meta && $meta->status === 'active' && (string) $meta->access_token !== '' && $byAgency($meta->connected_by_user_id, $meta->setup_link_id)) {
            $out[] = ['kind' => 'meta_ads', 'label' => 'Anúncios da Meta'];
        }
        $social = SocialConnection::where('company_id', $companyId)->first();
        if ($social && $social->status !== SocialConnection::STATUS_REVOKED && $social->access_token && $byAgency($social->connected_by_user_id, $social->setup_link_id)) {
            $out[] = ['kind' => 'social', 'label' => 'Redes sociais (Instagram e Página de Facebook)'];
        }

        return $out;
    }

    /**
     * Parceiro 5: no fim da relação, as ligações com a origem "agencia" (os ativos da Meta da agência, com o
     * token da agência) desligam-se SÓ na XPLENDOR: nada se revoga na Meta (a agência continua a usar a sua
     * ligação com os outros clientes) e o cliente não as pode manter. Os ativos da agência nesta empresa saem.
     * O histórico de dados fica. @return string[] as que desligou (meta_ads, social)
     */
    public function endAgencyOrigin(int $companyId, int $agencyId): array
    {
        $done = [];
        DB::transaction(function () use ($companyId, $agencyId, &$done) {
            $ads = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->where('origin', AgencyAssetLink::ORIGIN)
                ->where('status', '!=', 'revoked')->get();
            foreach ($ads as $i) {
                CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_META_ADS, CompanyConnectionEvent::DISCONNECTED, null, null,
                    ['origin' => AgencyAssetLink::ORIGIN, 'reason' => 'fim_da_relacao', 'account_id' => $i->account_id]);
                $i->forceFill(['status' => 'revoked', 'access_token' => ''])->save();
                $done[] = 'meta_ads';
            }
            $social = SocialConnection::where('company_id', $companyId)->where('origin', AgencyAssetLink::ORIGIN)
                ->where('status', '!=', SocialConnection::STATUS_REVOKED)->first();
            if ($social) {
                CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_SOCIAL, CompanyConnectionEvent::DISCONNECTED, null, null,
                    ['origin' => AgencyAssetLink::ORIGIN, 'reason' => 'fim_da_relacao']);
                $social->accounts()->delete();
                $social->forceFill(['status' => SocialConnection::STATUS_REVOKED, 'access_token' => null, 'granted_scopes' => null, 'meta_user_id' => null])->save();
                $done[] = 'social';
            }
            AgencyAssetLink::where('agency_company_id', $agencyId)->where('company_id', $companyId)->delete();
        });

        return $done;
    }

    /** Desliga as ligações feitas pela agência (mantém o histórico de dados). @return string[] as que desligou */
    public function disconnect(int $companyId, int $agencyId): array
    {
        $done = [];
        foreach ($this->agencyMade($companyId, $agencyId) as $c) {
            if ($c['kind'] === 'meta_ads') {
                $this->metaAds->disconnect($companyId);
            } else {
                $this->social->disconnect($companyId, false);
            }
            $done[] = $c['kind'];
        }

        return $done;
    }
}
