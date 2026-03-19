<?php

namespace App\Services;

use App\Repositories\Contracts\CompanyIntegrationRepositoryInterface;
use Illuminate\Support\Collection;

class CompanyIntegrationService extends BaseService
{
    public function __construct(
        protected CompanyIntegrationRepositoryInterface $companyIntegrationRepository,
    ) {
        parent::__construct($companyIntegrationRepository);
    }

    public function getCompanyIntegrations(int $companyId): Collection
    {
        return $this->companyIntegrationRepository
            ->getCompanyIntegrations($companyId)
            ->map(function ($integration) {
                return [
                    'id' => $integration->id,
                    'platform' => $integration->platform,
                    'account_id' => $integration->account_id,
                    'status' => $integration->status,
                    'last_synced_at' => $integration->last_synced_at,
                    'token_expires_at' => $integration->token_expires_at,
                    'active_campaigns_count' => (int) $integration->active_campaigns_count,
                ];
            });
    }
}
