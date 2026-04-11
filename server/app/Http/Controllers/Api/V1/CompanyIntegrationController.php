<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\CompanyIntegrationService;
use App\Services\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyIntegrationController extends Controller
{
    public function __construct(
        private readonly MetaAdsService $metaAds,
        private readonly CompanyIntegrationService $companyIntegrationService
    ) {}

    // GET /companies/{id}/integrations
    public function index(int $companyId): JsonResponse
    {
        $integrations = $this->companyIntegrationService->getCompanyIntegrations($companyId);

        return ApiResponse::success($integrations);
    }

    // POST /companies/{id}/integrations/meta/connect
    // Body: { short_lived_token: string, account_id: string }
    public function connectMeta(Request $request, int $companyId): JsonResponse
    {
        $request->validate([
            'short_lived_token' => 'required|string',
            'account_id'        => 'required|string',
        ]);

        // Trocar token curto por token de longa duração (~60 dias)
        $longToken = $this->metaAds->getLongLivedToken(
            config('services.meta.app_id'),
            config('services.meta.app_secret'),
            $request->short_lived_token
        );

        if (!$longToken) {
            return ApiResponse::error('Não foi possível obter o token de longa duração. Verifica as credenciais.', 422);
        }

        // Verificar token e obter data de expiração
        $appToken  = config('services.meta.app_id') . '|' . config('services.meta.app_secret');
        $tokenInfo = $this->metaAds->debugToken($longToken, $appToken);

        $expiresAt = isset($tokenInfo['expires_at'])
            ? \Carbon\Carbon::createFromTimestamp($tokenInfo['expires_at'])
            : now()->addDays(60);

        CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'meta'],
            [
                'access_token'     => $longToken,
                'account_id'       => $request->account_id,
                'token_expires_at' => $expiresAt,
                'status'           => 'active',
                'error_message'    => null,
            ]
        );

        return ApiResponse::success([], 'Meta Ads conectado com sucesso.');
    }

    // DELETE /companies/{id}/integrations/meta
    public function disconnectMeta(int $companyId): JsonResponse
    {
        CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')
            ->update(['status' => 'revoked', 'access_token' => '']);

        return ApiResponse::success([], 'Meta Ads desconectado.');
    }

    // GET /companies/{id}/integrations/meta/adsets
    // Lista a hierarquia Meta disponível na conta para mapeamento
    public function listMetaAdsets(int $companyId): JsonResponse
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')
            ->active()
            ->first();

        if (!$integration) {
            return ApiResponse::error('Conta Meta não conectada.', 404);
        }

        if ($integration->isTokenExpired()) {
            $integration->update(['status' => 'expired']);
            return ApiResponse::error('Token Meta expirado. Reconecta a conta.', 401);
        }

        $campaigns = $this->metaAds->getCampaignHierarchy(
            $integration->access_token,
            $integration->account_id
        );

        return ApiResponse::success($campaigns);
    }
}
