<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\GoogleAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Tráfego do site do cliente (GA4), LADO DO STAND. A autenticação com
 * a Google é por Service Account do SERVIDOR (uma para toda a XPLENDOR); o que
 * varia por empresa é o `property_id` da propriedade GA4, guardado em
 * `company_integrations` (platform='google').
 *
 * Guard tenant de 2 camadas: o utilizador pertence à empresa da rota (ou é root);
 * os dados devolvidos são SEMPRE os da propriedade dessa empresa (nunca de outra).
 */
class GoogleAnalyticsController extends Controller
{
    public function __construct(private readonly GoogleAnalyticsService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function integration(int $companyId): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'google')
            ->first();
    }

    /** Liga a propriedade GA4 da empresa (guarda o property_id). */
    public function connect(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            // O ID da propriedade GA4 é numérico (ex.: 398765432). Aceitamos só dígitos.
            'property_id' => ['required', 'regex:/^\d{6,15}$/'],
        ]);

        CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'google'],
            [
                'property_id' => $data['property_id'],
                'access_token' => '',            // Service Account: sem token por cliente
                'status' => 'active',
                'error_message' => null,
            ]
        );

        // Nova propriedade → limpar cache antiga (se existia).
        $this->service->forget($companyId, (int) $data['property_id']);

        return ApiResponse::success([
            'platform' => 'google',
            'property_id' => $data['property_id'],
            'status' => 'active',
        ], 'Google Analytics ligado com sucesso.');
    }

    /** Desliga a integração GA4 da empresa. */
    public function disconnect(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'google')
            ->update(['status' => 'revoked', 'property_id' => null]);

        return ApiResponse::success([], 'Google Analytics desligado.');
    }

    /**
     * Tráfego do site (cacheado). Nunca rebenta o dashboard: devolve sempre 200
     * com flags — connected / error — para o frontend tratar graciosamente.
     */
    public function traffic(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $integration = $this->integration($companyId);

        if (! $integration || $integration->status === 'revoked' || empty($integration->property_id)) {
            return ApiResponse::success([
                'connected' => false,
                'sa_email' => config('services.ga4.sa_email'),
            ], 'Sem propriedade GA4 ligada.');
        }

        $days = (int) $request->query('days', 28);
        $fresh = $request->boolean('fresh'); // ?fresh=1 → ignora a cache (testar leitura fresca)
        $propertyId = (int) $integration->property_id;

        try {
            $traffic = $this->service->getTraffic($companyId, $propertyId, $days, $fresh);

            // Sucesso → marcar sincronização.
            $integration->update(['status' => 'active', 'error_message' => null, 'last_synced_at' => now()]);

            return ApiResponse::success([
                'connected' => true,
                'property_id' => (string) $propertyId,
                'traffic' => $traffic,
            ]);
        } catch (\Throwable $e) {
            // NÃO engolir o erro: log completo + (em debug) devolver o erro REAL do
            // Google ao Simon (classe + mensagem) para ele ver a causa exata.
            // Redigir a mensagem: o SDK ecoa o keyfile (com a private_key) no erro.
            $safe = \App\Services\Ga4\Ga4Redact::message($e->getMessage());
            Log::error('[GA4] Falha ao obter tráfego', [
                'company_id' => $companyId,
                'property_id' => $propertyId,
                'exception' => get_class($e),
                'error' => $safe,
            ]);
            $integration->update(['status' => 'error', 'error_message' => mb_substr($safe, 0, 500)]);

            $payload = [
                'connected' => true,
                'property_id' => (string) $propertyId,
                'traffic' => null,
                'error' => 'Não foi possível ler os dados do GA4. Confirma que o email da Service Account tem acesso de Visualizador a esta propriedade e que o ID está correto.',
            ];

            // Em ambiente de debug, expõe o erro REAL do Google (já redigido) para diagnóstico.
            if (config('app.debug')) {
                $payload['error_detail'] = get_class($e) . ': ' . $safe;
            }

            return ApiResponse::success($payload, 'Erro ao ler GA4.');
        }
    }
}
