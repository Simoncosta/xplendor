<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CarAdCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarAdCampaignController extends Controller
{
    // GET /companies/{id}/cars/{car}/ad-campaigns
    public function index(int $companyId, int $carId): JsonResponse
    {
        $campaigns = CarAdCampaign::where('company_id', $companyId)
            ->where('car_id', $carId)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($campaigns);
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
            'level'           => 'required|in:campaign,adset,ad',
            'spend_split_pct' => 'required|numeric|min:1|max:100',
        ]);

        // Validar que o split total para o mesmo adset não ultrapassa 100%
        if ($request->adset_id) {
            $existingSplit = CarAdCampaign::where('company_id', $companyId)
                ->where('platform', $request->platform)
                ->where('adset_id', $request->adset_id)
                ->where('car_id', '!=', $carId)
                ->sum('spend_split_pct');

            if ($existingSplit + $request->spend_split_pct > 100) {
                return ApiResponse::error(
                    "O split total para este adset já é {$existingSplit}%. Não podes adicionar {$request->spend_split_pct}%.",
                    422
                );
            }
        }

        $campaign = CarAdCampaign::updateOrCreate(
            [
                'company_id' => $companyId,
                'car_id'     => $carId,
                'platform'   => $request->platform,
                'adset_id'   => $request->adset_id,
            ],
            [
                'campaign_id'     => $request->campaign_id,
                'campaign_name'   => $request->campaign_name,
                'adset_name'      => $request->adset_name,
                'ad_id'           => $request->ad_id,
                'ad_name'         => $request->ad_name,
                'level'           => $request->level,
                'spend_split_pct' => $request->spend_split_pct,
                'is_active'       => true,
            ]
        );

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
