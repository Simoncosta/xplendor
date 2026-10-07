<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Integrations\MetaAdsDisconnector;
use App\Services\Social\SocialConnectionService;

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
