<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublishingGrant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * F2.8 · "Ligar publicação" (Integrações › Redes sociais): uma autorização à parte que pede
 * instagram_content_publish e pages_manage_posts, sem mexer na ligação de leitura. Usa o mesmo
 * endereço de retorno da Meta (o state diz que é de publicação). Os tokens das Páginas desta
 * autorização ficam em cada conta já escolhida na leitura (publish_access_token), e são os que o
 * publicador usa. Desligar a publicação retira só as duas permissões de publicar (e só se nenhuma
 * outra empresa usar o mesmo utilizador da Meta para publicar); a leitura continua.
 */
class SocialPublishingConnectionService
{
    public const PURPOSE = 'publish';

    public function __construct(private readonly MetaSocialClientInterface $meta) {}

    /** Só com a leitura ligada e as contas escolhidas: a publicação usa as mesmas contas. */
    public function authUrl(int $companyId, ?User $actor): string
    {
        $connection = SocialConnection::where('company_id', $companyId)->first();
        if (! $connection || $connection->status !== SocialConnection::STATUS_ACTIVE
            || ! SocialConnectionAccount::where('social_connection_id', $connection->id)->exists()) {
            throw ValidationException::withMessages(['social' => 'Primeiro ligue as redes sociais e escolha as contas; depois ligue a publicação.']);
        }
        $nonce = Str::random(40);
        Cache::put(SocialConnectionService::stateKey($nonce), ['company_id' => $companyId, 'user_id' => $actor?->id, 'purpose' => self::PURPOSE], now()->addMinutes(15));

        return $this->meta->authUrl($nonce, SocialPublishingGrant::SCOPES);
    }

    /**
     * Retorno da Meta (chamado pelo callback das redes quando o state é de publicação).
     * Sinal: publish_ok | error:<motivo>.
     */
    public function handleCallback(int $companyId, array $state, ?string $code, ?string $error): string
    {
        if ($error !== null || $code === null || $code === '') {
            return $error !== null ? 'error:denied' : 'error:params';
        }
        $token = $this->meta->exchangeCode($code);
        if (! $token) {
            return 'error:token';
        }
        if (array_diff(SocialPublishingGrant::SCOPES, (array) $token['scopes']) !== []) {
            // Sem as duas permissões não se publica. Não se revoga nada: o mesmo utilizador da Meta
            // costuma ser o da leitura, e revogar podia cortá-la.
            return 'error:publish_scopes';
        }
        $pages = $this->meta->pages((string) $token['token']);
        if (! ($pages['ok'] ?? false)) {
            return 'error:token';
        }
        $byPage = collect($pages['pages'] ?? [])->keyBy('id');
        $matched = 0;

        DB::transaction(function () use ($companyId, $state, $token, $byPage, &$matched) {
            foreach (SocialConnectionAccount::where('company_id', $companyId)->get() as $account) {
                $pageId = (string) ($account->platform === 'facebook' ? $account->external_id : $account->page_id);
                $pageToken = (string) ($byPage->get($pageId)['access_token'] ?? '');
                $account->forceFill(['publish_access_token' => $pageToken !== '' ? $pageToken : null])->save();
                $matched += $pageToken !== '' ? 1 : 0;
            }
            if ($matched === 0) {
                return;
            }
            SocialPublishingGrant::updateOrCreate(['company_id' => $companyId], [
                'meta_user_id' => $token['user_id'] ?? null,
                'access_token' => $token['token'],
                'token_expires_at' => $token['expires_at'] ?? null,
                'granted_scopes' => $token['scopes'],
                'connected_by_user_id' => $state['user_id'] ?? null,
                'connected_at' => now(),
            ]);
        });

        return $matched > 0 ? 'publish_ok' : 'error:publish_pages';
    }

    /** Para o ecrã: ligada (com contas com token) ou não, e quando. */
    public function status(int $companyId): array
    {
        $grant = SocialPublishingGrant::where('company_id', $companyId)->first();
        $accounts = SocialConnectionAccount::where('company_id', $companyId)->whereNotNull('publish_access_token')->pluck('platform')->unique()->values()->all();

        return [
            'connected' => $grant !== null && $accounts !== [],
            'connected_at' => $grant?->connected_at?->toIso8601String(),
            'platforms' => $accounts,
        ];
    }

    /** @return array{permission_kept_shared: bool} */
    public function disconnect(int $companyId): array
    {
        $grant = SocialPublishingGrant::where('company_id', $companyId)->first();
        $shared = false;
        if ($grant) {
            $others = SocialPublishingGrant::where('company_id', '!=', $companyId)->get();
            $shared = $grant->meta_user_id === null
                || $others->contains(fn (SocialPublishingGrant $o) => $o->meta_user_id === null || (string) $o->meta_user_id === (string) $grant->meta_user_id);
            if (! $shared) {
                foreach (SocialPublishingGrant::SCOPES as $permission) {
                    $result = $this->meta->revokePermission((string) $grant->access_token, $permission);
                    if (! ($result['revoked'] ?? false)) {
                        Log::info('[Ligar publicação] Permissão não revogada na Meta', ['company_id' => $companyId, 'permission' => $permission]);
                    }
                }
            } else {
                Log::info('[Ligar publicação] Permissões mantidas: o mesmo utilizador da Meta publica noutra empresa', ['company_id' => $companyId]);
            }
            $grant->delete();
        }
        SocialConnectionAccount::where('company_id', $companyId)->update(['publish_access_token' => null]);

        return ['permission_kept_shared' => $shared];
    }
}
