<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\SyncPingwinJob;
use App\Jobs\SyncRestaurantJob;
use App\Jobs\ValidatePingwinConnectionJob;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Services\CoverManagerService;
use App\Services\PingwinDashboardService;
use App\Services\PingwinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — PingWin (POS), lado STAND. Cadastrar credenciais (senha cifrada),
 * sincronizar (lojas + vendas) e listar. Guard tenant de 2 camadas + gate de
 * módulo 'pingwin' na rota (Fase 3). A senha nunca sai em texto simples.
 */
class CompanyPingwinController extends Controller
{
    public function __construct(private readonly PingwinService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    /**
     * Cadastrar/atualizar credenciais PingWin. GUARDA (senha cifrada) com estado
     * "a validar" e despacha a validação para a FILA (worker, com docker socket) —
     * a validação síncrona no php-fpm falharia (sem socket). O utilizador é
     * notificado no sino com o resultado (sucesso ou o motivo real da falha).
     */
    public function connect(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Só os 3 que VARIAM por restaurante. Tudo o resto (URLs, versão,
        // report_id, etc.) é global e vem do .env — ver config('services.pingwin').
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'database' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $password = $data['password'];
        unset($data['password']);

        // Guarda com estado "validating" (não valida aqui) e valida pela fila.
        $integration = $this->service->saveCredentials($companyId, $data, $password);
        ValidatePingwinConnectionJob::dispatch($companyId);

        return ApiResponse::success([
            'platform' => 'pingwin',
            'status' => $integration->status, // 'validating'
        ], 'Credenciais guardadas — a validar a ligação. Serás notificado quando terminar.');
    }

    /** Sincroniza lojas + resumo de vendas (LOGOUT garantido no cliente). */
    public function sync(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $result = $this->service->sync($companyId, $data['date'] ?? null);

        return ApiResponse::success([
            'date' => $result['date'] ?? null,
            'locations' => PingwinLocation::where('company_id', $companyId)->get(),
        ], 'Sincronização concluída.');
    }

    /** Cards (anual/mensal/diário) + tabela de lojas do dashboard de restauração. */
    public function dashboard(Request $request, int $companyId, PingwinDashboardService $dashboard)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success($dashboard->build($companyId, $data['date'] ?? null), 'Dashboard PingWin carregado.');
    }

    /**
     * Gatilho do dashboard: mete a sincronização na FILA (não trava a tela). O
     * utilizador é notificado no sino quando o job termina. Serializado por
     * empresa dentro do Job (WithoutOverlapping).
     */
    public function queueSync(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        // Sem lojas ativas cadastradas → mensagem clara já aqui (o relatório precisa
        // dos winrest_store_id). Não despacha um job que iria falhar.
        $hasLocations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->exists();
        if (! $hasLocations) {
            return ApiResponse::error('Cadastra pelo menos uma loja (ID PingWin) para sincronizar vendas.', 422);
        }

        // Etapa 4: sincronização COMPLETA (PingWin + CoverManager de TODAS as lojas)
        // orquestrada num só job, com UMA notificação no fim. Serializada por empresa.
        SyncRestaurantJob::dispatch($companyId, $data['date'] ?? null);

        return ApiResponse::success([
            'queued' => true,
            'date' => $data['date'] ?? null,
        ], 'A atualizar… vais ser notificado quando os dados estiverem prontos.');
    }

    // ── Gestão manual de lojas (o match automático fica para fase futura) ──────

    private function locationRules(): array
    {
        return [
            'winrest_store_id' => ['required', 'string', 'max:120'],
            'winrest_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'opened_on' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['nullable', 'boolean'],
            // CoverManager (por loja) — slug não-secreto; token cifrado (cast).
            'cm_slug' => ['nullable', 'string', 'max:255'],
            'cm_token' => ['nullable', 'string', 'max:255'],
            'cm_base_url' => ['nullable', 'url', 'max:255'],
        ];
    }

    /** Campos não-secretos do CoverManager a gravar (o token trata-se à parte). */
    private function coverManagerFields(array $data): array
    {
        return [
            'cm_slug' => $data['cm_slug'] ?? null,
            'cm_base_url' => $data['cm_base_url'] ?? null,
        ];
    }

    /** Lista as lojas cadastradas da empresa. */
    public function listLocations(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Última sincronização de RESERVAS por loja (max synced_at das reservas).
        $lastReserv = \App\Models\CmReservationShiftSummary::where('company_id', $companyId)
            ->selectRaw('location_id, MAX(synced_at) as last')->groupBy('location_id')->pluck('last', 'location_id');

        $locations = PingwinLocation::where('company_id', $companyId)->orderBy('display_name')->get()
            ->map(function (PingwinLocation $l) use ($lastReserv) {
                $l->setAttribute('cm_last_synced_at', isset($lastReserv[$l->id]) ? \Carbon\Carbon::parse($lastReserv[$l->id])->toIso8601String() : null);
                return $l;
            });

        return ApiResponse::success($locations, 'Lojas carregadas.');
    }

    /** Cadastra uma loja (winrest_store_id único por empresa). */
    public function storeLocation(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate($this->locationRules());

        if (PingwinLocation::where('company_id', $companyId)->where('winrest_store_id', $data['winrest_store_id'])->exists()) {
            return ApiResponse::error('Já existe uma loja com esse ID PingWin.', 422);
        }

        $location = PingwinLocation::create(array_merge([
            'company_id' => $companyId,
            'winrest_store_id' => $data['winrest_store_id'],
            'winrest_name' => $data['winrest_name'] ?? null,
            'display_name' => $data['display_name'] ?? ($data['winrest_name'] ?? $data['winrest_store_id']),
            'opened_on' => $data['opened_on'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ], $this->coverManagerFields($data), [
            // Token só se veio preenchido (cifrado pelo cast).
            ...(! empty($data['cm_token']) ? ['cm_token' => $data['cm_token']] : []),
        ]));

        return ApiResponse::success($location, 'Loja cadastrada.');
    }

    /** Atualiza uma loja (tenancy: só lojas da própria empresa). */
    public function updateLocation(Request $request, int $companyId, int $locationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $location = PingwinLocation::where('company_id', $companyId)->where('id', $locationId)->first();
        if (! $location) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }

        $data = $request->validate($this->locationRules());

        $clash = PingwinLocation::where('company_id', $companyId)
            ->where('winrest_store_id', $data['winrest_store_id'])
            ->where('id', '!=', $locationId)->exists();
        if ($clash) {
            return ApiResponse::error('Já existe uma loja com esse ID PingWin.', 422);
        }

        $location->update(array_merge([
            'winrest_store_id' => $data['winrest_store_id'],
            'winrest_name' => $data['winrest_name'] ?? null,
            'display_name' => $data['display_name'] ?? ($data['winrest_name'] ?? $data['winrest_store_id']),
            'opened_on' => $data['opened_on'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ], $this->coverManagerFields($data), [
            // Token só é reescrito se veio preenchido (em branco = manter o atual).
            ...(! empty($data['cm_token']) ? ['cm_token' => $data['cm_token']] : []),
        ]));

        return ApiResponse::success($location->fresh(), 'Loja atualizada.');
    }

    /** Remove uma loja (tenancy). */
    public function deleteLocation(int $companyId, int $locationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $location = PingwinLocation::where('company_id', $companyId)->where('id', $locationId)->first();
        if (! $location) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }

        $location->delete();

        return ApiResponse::success(null, 'Loja removida.');
    }

    // ── CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno ─────

    /**
     * Sincroniza as reservas (agregado por turno, SEM PII) de uma data para as
     * lojas com credencial CoverManager. Síncrono e leve (HTTP normal); uma loja
     * a falhar não aborta as outras. Notifica no sino e devolve SÓ os números.
     */
    public function coverManagerSync(Request $request, int $companyId, CoverManagerService $cover)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        // Sincronizável = loja com slug + token resolvível (loja OU empresa).
        $companyToken = $cover->companyToken($companyId);
        if ($cover->syncableLocations($companyId, $companyToken)->isEmpty()) {
            return ApiResponse::error('Liga o CoverManager (token de empresa em Integrações) e mete o slug nas lojas.', 422);
        }

        $result = $cover->sync($companyId, $data['date']);

        return ApiResponse::success($result, 'Reservas sincronizadas.');
    }

    // ── CoverManager — token AO NÍVEL DA EMPRESA (Integrações) ────────────────

    /** Estado da integração CoverManager da empresa (token de empresa configurado?). */
    public function coverManagerIndex(int $companyId, CoverManagerService $cover)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['connected' => $cover->companyHasIntegration($companyId)], 'CoverManager (empresa).');
    }

    /** Liga o CoverManager à EMPRESA: guarda o token (cifrado). NUNCA o expõe. */
    public function coverManagerConnect(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        \App\Models\CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'covermanager'],
            ['access_token' => $data['token'], 'status' => 'active', 'error_message' => null], // cifrado pelo cast
        );

        return ApiResponse::success(['connected' => true], 'CoverManager ligado à empresa.');
    }

    /** Desliga o CoverManager da empresa (o token deixa de ser fallback). */
    public function coverManagerDisconnect(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Models\CompanyIntegration::where('company_id', $companyId)->where('platform', 'covermanager')
            ->update(['status' => 'revoked']);

        return ApiResponse::success(null, 'CoverManager desligado.');
    }

    /** Lê a flag do ticket médio (CoverManager) da empresa. */
    public function coverManagerSettings(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $enabled = (bool) (\App\Models\Company::where('id', $companyId)->value('cm_avg_ticket_enabled') ?? true);

        return ApiResponse::success(['avg_ticket_enabled' => $enabled], 'Definições CoverManager.');
    }

    /** Liga/desliga o ticket médio com o CoverManager (por empresa). */
    public function updateCoverManagerSettings(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['avg_ticket_enabled' => ['required', 'boolean']]);

        \App\Models\Company::where('id', $companyId)->update(['cm_avg_ticket_enabled' => $data['avg_ticket_enabled']]);

        return ApiResponse::success(['avg_ticket_enabled' => (bool) $data['avg_ticket_enabled']], 'Definições atualizadas.');
    }

    /** Lista as lojas descobertas + estado da ligação. */
    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'pingwin')->first();

        return ApiResponse::success([
            'connected' => $integration && $integration->status === 'active',
            'status' => $integration->status ?? null,
            // Motivo real da última falha de validação (para o ecrã mostrar).
            'error_message' => $integration?->error_message,
            'last_synced_at' => optional($integration?->last_synced_at)->toIso8601String(),
            // IDs não-secretos (para pré-preencher o formulário ao reconfigurar).
            // A SENHA vive em access_token (cifrada) e NUNCA é devolvida aqui.
            'config' => $integration?->config ?? null,
            // Lojas descobertas (mapeadas para o formato que o cartão de Integrações usa).
            'stores' => PingwinLocation::where('company_id', $companyId)->get()->map(fn ($l) => [
                'id' => $l->id,
                'external_id' => $l->winrest_store_id,
                'code' => $l->winrest_store_id,
                'description' => $l->display_name ?: $l->winrest_name,
            ]),
        ], 'PingWin carregado.');
    }
}
