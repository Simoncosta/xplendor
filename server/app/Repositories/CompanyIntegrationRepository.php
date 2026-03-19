<?php

namespace App\Repositories;

use App\Models\CompanyIntegration;
use App\Repositories\Contracts\CompanyIntegrationRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanyIntegrationRepository extends BaseRepository implements CompanyIntegrationRepositoryInterface
{
    public function __construct(CompanyIntegration $model)
    {
        parent::__construct($model);
    }

    public function getCompanyIntegrations(int $companyId): Collection
    {
        $activeCampaignsSubquery = DB::table('car_ad_campaigns')
            ->selectRaw('company_id, platform, COUNT(*) as active_campaigns_count')
            ->where('company_id', $companyId)
            ->where('platform', 'meta')
            ->where('is_active', true)
            ->groupBy('company_id', 'platform');

        return $this->model->newQuery()
            ->leftJoinSub($activeCampaignsSubquery, 'active_campaigns', function ($join) {
                $join->on('active_campaigns.company_id', '=', 'company_integrations.company_id')
                    ->on('active_campaigns.platform', '=', 'company_integrations.platform');
            })
            ->where('company_integrations.company_id', $companyId)
            ->selectRaw('
                company_integrations.id,
                company_integrations.platform,
                company_integrations.account_id,
                company_integrations.status,
                company_integrations.last_synced_at,
                company_integrations.token_expires_at,
                CASE
                    WHEN company_integrations.platform = "meta" THEN COALESCE(active_campaigns.active_campaigns_count, 0)
                    ELSE 0
                END as active_campaigns_count
            ')
            ->orderBy('company_integrations.platform')
            ->get();
    }
}
