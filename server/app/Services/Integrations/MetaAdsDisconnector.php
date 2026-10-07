<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\CompanyIntegration;
use App\Services\Meta\MetaDataPurger;
use App\Services\MetaAdsService;
use Illuminate\Support\Facades\Log;

/**
 * Desligar os anúncios da Meta de uma empresa: retira a permissão ads_read à aplicação (sem
 * bloquear se a Meta falhar) e marca a integração como revogada; com $purge, apaga também os
 * dados da Meta guardados. Usado pelo ecrã de Integrações e pelo fim da relação com a agência.
 */
class MetaAdsDisconnector
{
    public function __construct(private readonly MetaAdsService $metaAds) {}

    /** @return array{permissions_revoked: bool, purged: bool, deleted: mixed} */
    public function disconnect(int $companyId, bool $purge = false): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();

        $revoked = false;
        $token = (string) ($integration?->access_token ?? '');
        if ($token !== '') {
            $result = $this->metaAds->revokePermissions($token);
            $revoked = $result['revoked'];
            if (! $revoked) {
                Log::warning('Meta: não foi possível retirar a permissão ads_read ao desligar; desligado na mesma.', [
                    'company_id' => $companyId,
                    'status'     => $integration->status,
                    'error'      => $result['error'],
                ]);
            }
        }

        if ($purge) {
            $deleted = app(MetaDataPurger::class)->purge($companyId);
            Log::info('Meta: dados apagados a pedido do cliente.', ['company_id' => $companyId, 'deleted' => $deleted]);

            return ['permissions_revoked' => $revoked, 'purged' => true, 'deleted' => $deleted];
        }

        if ($integration) {
            CompanyIntegration::whereKey($integration->id)->update(['status' => 'revoked', 'access_token' => '']);
        }

        return ['permissions_revoked' => $revoked, 'purged' => false, 'deleted' => null];
    }
}
