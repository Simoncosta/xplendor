<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\CompanyConnectionEvent;
use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use App\Services\MetaAdsService;
use App\Services\Social\MetaSocialClient;
use App\Support\MetaTokenExpiry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ligação dos anúncios da Meta (permissão ads_read), para a equipa e para o link de
 * configuração do cliente:
 *  1. authUrl(): nonce de uso único em cache, com a empresa e a ORIGEM (o utilizador que
 *     iniciou, ou o link de configuração, sem utilizador).
 *  2. handleCallback(): troca o code, confirma a permissão concedida (se a Meta não a
 *     ofereceu, a app ainda não está aprovada para essa conta: não se guarda nada) e guarda o
 *     token na integração existente (company_integrations, platform=meta).
 *  3. adAccounts(): as contas de anúncios a que a autorização dá acesso (/me/adaccounts),
 *     para escolher numa lista em vez de escrever o ID.
 *  4. setAccount(): grava a conta escolhida e dispara a sincronização dos últimos 90 dias.
 */
class MetaAdsConnectionService
{
    public const PERMISSION = 'ads_read';
    private const GRAPH_URL = 'https://graph.facebook.com/v25.0';
    private const DIALOG_URL = 'https://www.facebook.com/v25.0/dialog/oauth';

    public function __construct(
        private readonly MetaAdsService $metaAds,
        private readonly MetaSocialClient $graph,
    ) {}

    public static function stateKey(string $nonce): string
    {
        return 'meta_oauth_state:' . $nonce;
    }

    public function authUrl(int $companyId, ?int $userId, ?int $setupLinkId = null): string
    {
        $nonce = Str::random(40);
        Cache::put(self::stateKey($nonce), ['company_id' => $companyId, 'user_id' => $userId, 'setup_link_id' => $setupLinkId], now()->addMinutes(15));

        return self::DIALOG_URL . '?' . http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'scope' => self::PERMISSION,
            'response_type' => 'code',
            'state' => $nonce,
        ]);
    }

    /**
     * @return array{0: ?int, 1: string, 2: ?int} empresa (null = state inválido), sinal
     *   (connected | choose_account | error:<motivo>) e o link de configuração de origem.
     */
    public function handleCallback(string $nonce, ?string $code, ?string $error): array
    {
        $state = $nonce !== '' ? Cache::pull(self::stateKey($nonce)) : null;
        // Formato antigo (só o id da empresa, e o utilizador numa segunda chave).
        if (is_numeric($state)) {
            $state = ['company_id' => (int) $state, 'user_id' => Cache::pull(self::stateKey($nonce) . ':user'), 'setup_link_id' => null];
        }
        if (! is_array($state) || empty($state['company_id'])) {
            return [null, 'error:state', null];
        }
        $companyId = (int) $state['company_id'];
        $linkId = isset($state['setup_link_id']) ? (int) $state['setup_link_id'] ?: null : null;

        if ($error !== null || $code === null || $code === '') {
            return [$companyId, $error !== null ? 'error:denied' : 'error:params', $linkId];
        }

        $short = $this->send(fn () => Http::asForm()->timeout(15)->post(self::GRAPH_URL . '/oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'code' => $code,
        ]));
        if (! $short || $short->failed() || ! $short->json('access_token')) {
            Log::error('MetaOAuth: falha ao trocar code (redirect)', ['status' => $short?->status()]);

            return [$companyId, 'error:token', $linkId];
        }
        $longToken = $this->metaAds->getLongLivedToken((string) config('services.meta.app_id'), (string) config('services.meta.app_secret'), (string) $short->json('access_token'));
        if (! $longToken) {
            return [$companyId, 'error:token', $linkId];
        }
        $tokenInfo = $this->metaAds->debugToken($longToken, config('services.meta.app_id') . '|' . config('services.meta.app_secret'));

        // A permissão tem de ter sido concedida. Se não foi recusada pela pessoa, a Meta não a
        // ofereceu (app ainda sem aprovação para contas fora da equipa): não se guarda nada.
        if (isset($tokenInfo['scopes']) && ! in_array(self::PERMISSION, (array) $tokenInfo['scopes'], true)) {
            $statuses = $this->graph->permissionStatuses($longToken) ?? [];
            $this->metaAds->revokePermissions($longToken);

            return [$companyId, ($statuses[self::PERMISSION] ?? null) === 'declined' ? 'error:scopes' : 'error:not_approved', $linkId];
        }

        $integration = CompanyIntegration::firstOrNew(['company_id' => $companyId, 'platform' => 'meta']);
        $integration->access_token = $longToken;
        $integration->token_expires_at = MetaTokenExpiry::fromDebug($tokenInfo);
        $integration->status = 'active';
        $integration->error_message = null;
        // Origem desta autorização: o utilizador que iniciou, ou o link do cliente (sem utilizador).
        $integration->connected_by_user_id = $linkId ? null : ($state['user_id'] ?? null);
        $integration->setup_link_id = $linkId;
        $integration->save();

        CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_META_ADS, CompanyConnectionEvent::CONNECTED,
            $integration->connected_by_user_id, $linkId, ['account_id' => $integration->account_id]);

        // Backfill de 90 dias (sem conta fica needs_account e corre quando a conta for escolhida).
        app(MetaAccountInsightsService::class)->scheduleBackfill($integration);

        return [$companyId, $integration->account_id ? 'connected' : 'choose_account', $linkId];
    }

    /**
     * Contas de anúncios a que a autorização dá acesso: id (sem "act_"), nome, moeda, empresa
     * (Business) e se está ativa.
     *
     * @return array<int, array{id: string, name: string, currency: ?string, business: ?string, active: bool}>
     */
    public function adAccounts(int $companyId): array
    {
        $integration = $this->integrationWithToken($companyId);
        $accounts = [];
        $url = self::GRAPH_URL . '/me/adaccounts';
        $query = ['fields' => 'account_id,name,account_status,currency,business{name}', 'limit' => 100, 'access_token' => (string) $integration->access_token];
        for ($i = 0; $i < 5 && $url; $i++) {
            $r = $this->send(fn () => Http::timeout(15)->get($url, $query));
            if (! $r || ! $r->successful()) {
                $kind = MetaSocialClient::classify($r);
                if ($kind === MetaSocialClient::ERR_EXPIRED) {
                    $integration->update(['status' => 'expired', 'error_message' => 'A autorização da Meta expirou.']);
                    throw new HttpException(409, 'A autorização da Meta expirou: volte a ligar os anúncios.');
                }
                throw new HttpException(409, $kind === MetaSocialClient::ERR_PERMISSION
                    ? 'A Meta não deu acesso às contas de anúncios desta autorização.'
                    : 'Não foi possível contactar a Meta. Tente novamente dentro de alguns minutos.');
            }
            foreach ((array) $r->json('data', []) as $a) {
                $id = (string) ($a['account_id'] ?? preg_replace('/^act_/', '', (string) ($a['id'] ?? '')));
                if ($id === '') {
                    continue;
                }
                $accounts[] = [
                    'id' => $id,
                    'name' => (string) ($a['name'] ?? $id),
                    'currency' => $a['currency'] ?? null,
                    'business' => $a['business']['name'] ?? null,
                    'active' => (int) ($a['account_status'] ?? 0) === 1,
                ];
            }
            $url = $r->json('paging.next');
            $query = [];
        }

        return $accounts;
    }

    /**
     * Grava a conta de anúncios ("act_123" passa a "123"). Mudar de conta dispara o backfill e
     * apaga os dados da conta antiga. $mustBeListed: só aceita uma conta da lista desta autorização.
     */
    public function setAccount(int $companyId, string $accountId, ?int $userId, ?int $setupLinkId = null, bool $mustBeListed = false): CompanyIntegration
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        if (! $integration) {
            throw new HttpException(404, 'Conta Meta não conectada.');
        }
        $accountId = (string) preg_replace('/^act_/', '', trim($accountId));
        if ($accountId === '' || ! preg_match('/^[A-Za-z0-9_]{1,50}$/', $accountId)) {
            throw new HttpException(422, 'Conta de anúncios inválida.');
        }
        $name = null;
        if ($mustBeListed) {
            $match = collect($this->adAccounts($companyId))->firstWhere('id', $accountId);
            if (! $match) {
                throw new HttpException(422, 'Esta conta de anúncios não está disponível nesta autorização. Atualize a lista e tente novamente.');
            }
            $name = $match['name'];
        }

        $previous = $integration->account_id;
        $integration->update(['account_id' => $accountId]);
        app(MetaAccountInsightsService::class)->onAccountChanged($integration, $previous);
        CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_META_ADS, CompanyConnectionEvent::ACCOUNT_CHANGED,
            $userId, $setupLinkId, array_filter(['account_id' => $accountId, 'account_name' => $name, 'previous_account_id' => $previous]));

        return $integration->fresh();
    }

    private function integrationWithToken(int $companyId): CompanyIntegration
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        if (! $integration || $integration->status === 'revoked' || (string) $integration->access_token === '') {
            throw new HttpException(404, 'Os anúncios da Meta não estão ligados.');
        }

        return $integration;
    }

    private function send(callable $call): ?\Illuminate\Http\Client\Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            return null;
        }
    }
}
