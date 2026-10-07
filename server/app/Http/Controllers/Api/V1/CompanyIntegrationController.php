<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\CollaboratorService;
use App\Services\CompanyIntegrationService;
use App\Services\Meta\MetaDataPurger;
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
        $this->assertCanManage($request, $companyId);
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

        // 0 ou ausente = sem data de expiração (NULL), nunca 1970.
        $expiresAt = \App\Support\MetaTokenExpiry::fromDebug($tokenInfo);

        $previousAccount = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')->value('account_id');

        $integration = CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'meta'],
            [
                'access_token'     => $longToken,
                'account_id'       => $request->account_id,
                'token_expires_at' => $expiresAt,
                'status'           => 'active',
                'error_message'    => null,
                'connected_by_user_id' => $request->user()?->id,
            ]
        );

        // Ingestão ao nível da conta: backfill de 90 dias (e limpa a conta antiga se mudou).
        app(\App\Services\MetaAccountInsightsService::class)->onAccountChanged($integration, $previousAccount);

        return ApiResponse::success([], 'Meta Ads conectado com sucesso.');
    }

    // PATCH /companies/{id}/integrations/meta/account
    // Define a conta de anúncios DEPOIS do OAuth (o callback no backend guarda o
    // token mas não pode perguntar o account_id). Body: { account_id: string }
    public function setMetaAccount(Request $request, int $companyId): JsonResponse
    {
        $this->assertCanManage($request, $companyId);
        $request->validate(['account_id' => 'required|string']);

        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')
            ->first();

        if (!$integration) {
            return ApiResponse::error('Conta Meta não conectada.', 404);
        }

        // Normaliza "act_123" → "123" (o resto do sistema guarda sem prefixo).
        $accountId = preg_replace('/^act_/', '', trim($request->account_id));
        if ($accountId === '') {
            return ApiResponse::error('Conta de anúncios inválida.', 422);
        }

        $previousAccount = $integration->account_id;
        $integration->update(['account_id' => $accountId]);

        // Definir/mudar a conta DISPARA o backfill de 90 dias (e apaga os dados da
        // conta antiga se mudou). O ecrã mostra "a sincronizar pela primeira vez…".
        app(\App\Services\MetaAccountInsightsService::class)->onAccountChanged($integration, $previousAccount);

        return ApiResponse::success([], 'Conta de anúncios guardada. A sincronizar os últimos 90 dias…');
    }

    // DELETE /companies/{id}/integrations/meta
    // Body (opcional): { purge: bool, confirmation: "APAGAR" }
    //   1. Retira na Meta SÓ a permissão ads_read (DELETE /me/permissions/ads_read) com
    //      o token, ANTES de o apagar; a ligação das redes sociais mantém-se. Se falhar
    //      (token expirado, já revogado), continua e regista.
    //   2. Por omissão mantém o histórico (só apaga o token). Com purge + confirmação,
    //      apaga todos os dados da Meta da empresa (MetaDataPurger), mantendo as vendas.
    //   Também serve para apagar o histórico de uma integração já desligada.
    public function disconnectMeta(Request $request, int $companyId): JsonResponse
    {
        $this->assertCanManage($request, $companyId);
        $data = $request->validate([
            'purge'        => 'sometimes|boolean',
            'confirmation' => 'nullable|string',
        ]);
        $purge = (bool) ($data['purge'] ?? false);

        if ($purge && ($data['confirmation'] ?? null) !== MetaDataPurger::CONFIRMATION) {
            return ApiResponse::error('Para apagar os dados da Meta, confirme escrevendo ' . MetaDataPurger::CONFIRMATION . '.', 422);
        }

        $result = app(\App\Services\Integrations\MetaAdsDisconnector::class)->disconnect($companyId, $purge);

        return ApiResponse::success($result, $purge ? 'Meta Ads desligado e dados da Meta apagados.' : 'Meta Ads desconectado.');
    }

    /** Ligar e desligar os anúncios: admin da empresa, root ou admin da agência gestora, fora de impersonation. */
    private function assertCanManage(Request $request, int $companyId): void
    {
        if (! CollaboratorService::canConfigureIntegrations($request->user(), $companyId)) {
            abort(403, 'Só o administrador da empresa ou um administrador da agência gestora pode ligar ou desligar os anúncios da Meta.');
        }
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
