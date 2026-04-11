<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CarAdCampaign;
use App\Services\MetaAdsTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarAdCampaignController extends Controller
{
    public function __construct(
        protected MetaAdsTargetResolver $targetResolver,
    ) {}

    // GET /companies/{id}/cars/{car}/ad-campaigns
    public function index(int $companyId, int $carId): JsonResponse
    {
        $campaigns = CarAdCampaign::where('company_id', $companyId)
            ->where('car_id', $carId)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($campaigns);
    }

    // GET /companies/{id}/cars/{car}/ad-campaigns/active-targets
    public function activeTargets(int $companyId, int $carId): JsonResponse
    {
        $targets = $this->targetResolver->getActiveMappingsForCar($companyId, $carId);

        return ApiResponse::success($targets);
    }

    // POST /companies/{id}/cars/{car}/ad-campaigns
    // Body: { platform, campaign_id, campaign_name, adset_id, adset_name, level, spend_split_pct }
    public function store(Request $request, int $companyId, int $carId): JsonResponse
    {
        $request->validate([
            'platform'        => 'required|in:meta,google',
            'campaign_id'     => 'required|string',
            'campaign_name'   => 'nullable|string|max:255',
            'adset_id'        => 'nullable|string',
            'adset_name'      => 'nullable|string|max:255',
            'ad_id'           => 'nullable|string',
            'ad_name'         => 'nullable|string|max:255',
            'level'           => 'nullable|in:campaign,adset,ad',
            'spend_split_pct' => 'required|numeric|min:1|max:100',
        ]);

        $campaignId = (string) $request->campaign_id;
        $adsetId = $request->filled('adset_id') ? (string) $request->adset_id : null;
        $adId = $request->filled('ad_id') ? (string) $request->ad_id : null;
        $resolvedLevel = $this->targetResolver->resolveLevel($campaignId, $adsetId, $adId);

        $mappingQuery = CarAdCampaign::query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('platform', $request->platform);

        match ($resolvedLevel) {
            'ad' => $mappingQuery
                ->whereNotNull('ad_id')
                ->where('ad_id', $adId),
            'adset' => $mappingQuery
                ->whereNull('ad_id')
                ->where('adset_id', $adsetId),
            default => $mappingQuery
                ->whereNull('ad_id')
                ->whereNull('adset_id')
                ->where('campaign_id', $campaignId),
        };

        $campaign = $mappingQuery->first();

        $payload = [
            'campaign_id' => $request->campaign_id,
            'campaign_name' => $request->campaign_name,
            'adset_id' => $adsetId,
            'adset_name' => $request->adset_name,
            'ad_id' => $adId,
            'ad_name' => $request->ad_name,
            'level' => $resolvedLevel,
            'spend_split_pct' => $request->spend_split_pct,
            'is_active' => true,
        ];

        if ($campaign) {
            $campaign->update($payload);

            return ApiResponse::success($campaign->fresh(), 'Mapeamento Meta Ads atualizado com sucesso.');
        }

        $campaign = CarAdCampaign::create([
            'company_id' => $companyId,
            'car_id' => $carId,
            'platform' => $request->platform,
            ...$payload,
        ]);

        return ApiResponse::success($campaign, 'Campanha mapeada com sucesso.');
    }

    // DELETE /companies/{id}/cars/{car}/ad-campaigns/{campaign}
    public function destroy(int $companyId, int $carId, int $campaignId): JsonResponse
    {
        CarAdCampaign::where('id', $campaignId)
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->delete();

        return ApiResponse::success([], 'Mapeamento removido.');
    }

    // PATCH /companies/{id}/cars/{car}/ad-campaigns/{campaign}/toggle
    public function toggle(int $companyId, int $carId, int $campaignId): JsonResponse
    {
        $campaign = CarAdCampaign::where('id', $campaignId)
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->firstOrFail();

        $campaign->update(['is_active' => !$campaign->is_active]);

        return ApiResponse::success($campaign, 'Estado actualizado.');
    }
}
