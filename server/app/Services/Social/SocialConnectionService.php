<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Jobs\ReadSocialFollowersJob;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ligação das redes sociais (Instagram e Facebook), separada da dos anúncios.
 *
 *  1. authUrl(): nonce de uso único em cache (como no hotfix dos anúncios).
 *  2. handleCallback(): troca o code, guarda o token cifrado, fica a aguardar a escolha.
 *  3. candidates() / saveSelection(): o admin escolhe as Páginas e as contas de
 *     Instagram (a conta de Instagram vem através da Página).
 *  4. readCompany(): leitura diária dos seguidores; só grava quando lê de facto.
 *  5. disconnect(): retira só as três permissões das redes, uma a uma; mantém ou
 *     apaga o histórico automático de seguidores.
 */
class SocialConnectionService
{
    public const PURGE_CONFIRMATION = 'APAGAR';

    public function __construct(
        private readonly MetaSocialClient $meta,
        private readonly FollowerSnapshotService $followers,
    ) {}

    // ── 1. Autorização ─────────────────────────────────────────────────────────

    /** Origem: o utilizador que inicia, ou o link de configuração do cliente (sem utilizador). */
    public function authUrl(int $companyId, ?User $actor, ?int $setupLinkId = null): string
    {
        $nonce = Str::random(40);
        $state = ['company_id' => $companyId, 'user_id' => $setupLinkId ? null : $actor?->id];
        if ($setupLinkId) {
            $state['setup_link_id'] = $setupLinkId;
        }
        Cache::put(self::stateKey($nonce), $state, now()->addMinutes(15));

        return $this->meta->authUrl($nonce);
    }

    public static function stateKey(string $nonce): string
    {
        return 'meta_social_oauth_state:' . $nonce;
    }

    // ── 2. Callback ────────────────────────────────────────────────────────────

    /**
     * Devolve [company_id|null, sinal, link de configuração|null] para o redirecionamento:
     * choose | error:<motivo>. O state é lido e apagado (uso único); sem state válido não se
     * sabe a empresa.
     */
    public function handleCallback(string $nonce, ?string $code, ?string $error): array
    {
        $state = $nonce !== '' ? Cache::pull(self::stateKey($nonce)) : null;
        if (! is_array($state) || empty($state['company_id'])) {
            return [null, 'error:state', null];
        }
        $companyId = (int) $state['company_id'];
        $linkId = ! empty($state['setup_link_id']) ? (int) $state['setup_link_id'] : null;

        if ($error !== null || $code === null || $code === '') {
            return [$companyId, $error !== null ? 'error:denied' : 'error:params', $linkId];
        }

        $token = $this->meta->exchangeCode($code);
        if (! $token) {
            return [$companyId, 'error:token', $linkId];
        }
        $missing = array_diff(SocialConnection::SCOPES, $token['scopes']);
        if ($missing !== []) {
            // Sem as três permissões não há leitura possível: não se guarda nada. Se o
            // utilizador não recusou nenhuma, a Meta não as ofereceu (app ainda sem
            // aprovação para contas fora da equipa); se recusou, pede-se de novo.
            $statuses = $this->meta->permissionStatuses($token['token']) ?? [];
            $declined = array_filter($missing, fn ($p) => ($statuses[$p] ?? null) === 'declined');
            foreach ($token['scopes'] as $granted) {
                $this->meta->revokePermission($token['token'], $granted);
            }

            return [$companyId, $declined === [] ? 'error:not_approved' : 'error:scopes', $linkId];
        }

        DB::transaction(function () use ($companyId, $state, $token, $linkId) {
            $connection = SocialConnection::firstOrNew(['company_id' => $companyId]);
            $connection->fill([
                'meta_user_id' => $token['user_id'],
                'access_token' => $token['token'],
                'token_expires_at' => $token['expires_at'],
                'granted_scopes' => $token['scopes'],
                'status' => SocialConnection::STATUS_PENDING_SELECTION,
                // Origem: o utilizador que iniciou, ou o link de configuração do cliente (sem utilizador).
                'connected_by_user_id' => $linkId ? null : ($state['user_id'] ?? null),
                'setup_link_id' => $linkId,
                'connected_at' => now(),
                'last_error_at' => null,
                'last_error_kind' => null,
                'last_error_message' => null,
            ])->save();
            // Nova autorização: os tokens das Páginas escolhidas antes deixam de valer.
            $this->deleteAccounts($connection);
        });

        return [$companyId, 'choose', $linkId];
    }

    // ── 3. Escolha das contas ──────────────────────────────────────────────────

    /** Páginas e contas de Instagram disponíveis (sem tokens) e o que já está escolhido. */
    public function candidates(int $companyId): array
    {
        $connection = $this->connectionWithToken($companyId);
        $result = $this->meta->pages((string) $connection->access_token);
        if (! $result['ok']) {
            $status = $this->applyError($connection, $result);
            throw new HttpException(409, self::statusMessage($status));
        }

        $selected = $connection->accounts()->get()->keyBy(fn ($a) => $a->platform . ':' . $a->external_id);

        return [
            'pages' => array_map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'selected' => $selected->has('facebook:' . $p['id']),
                'is_primary' => (bool) $selected->get('facebook:' . $p['id'])?->is_primary,
                'instagram' => $p['instagram'] ? $p['instagram'] + [
                    'selected' => $selected->has('instagram:' . $p['instagram']['id']),
                    'is_primary' => (bool) $selected->get('instagram:' . $p['instagram']['id'])?->is_primary,
                ] : null,
            ], $result['pages']),
        ];
    }

    /**
     * Guarda a escolha. Os ids são confirmados de novo na Meta (nunca se confia no
     * cliente) e os tokens das Páginas vêm daí. A principal de cada rede é a indicada
     * ou, por omissão, a primeira escolhida. Lê logo os seguidores (job).
     */
    public function saveSelection(int $companyId, array $facebookIds, array $instagramIds, ?string $primaryFacebook, ?string $primaryInstagram, ?int $actorId = null): SocialConnection
    {
        $connection = $this->connectionWithToken($companyId);
        if ($facebookIds === [] && $instagramIds === []) {
            throw new HttpException(422, 'Escolha pelo menos uma Página ou uma conta de Instagram.');
        }

        $result = $this->meta->pages((string) $connection->access_token);
        if (! $result['ok']) {
            $status = $this->applyError($connection, $result);
            throw new HttpException(409, self::statusMessage($status));
        }

        $pages = collect($result['pages']);
        $byPage = $pages->keyBy('id');
        $byInstagram = $pages->filter(fn ($p) => $p['instagram'])->keyBy(fn ($p) => $p['instagram']['id']);

        $unknown = array_merge(array_diff($facebookIds, $byPage->keys()->all()), array_diff($instagramIds, $byInstagram->keys()->all()));
        if ($unknown !== []) {
            throw new HttpException(422, 'Algumas contas escolhidas já não estão disponíveis nesta autorização. Atualize a lista e tente novamente.');
        }

        $primaryFacebook = in_array($primaryFacebook, $facebookIds, true) ? $primaryFacebook : ($facebookIds[0] ?? null);
        $primaryInstagram = in_array($primaryInstagram, $instagramIds, true) ? $primaryInstagram : ($instagramIds[0] ?? null);

        DB::transaction(function () use ($connection, $companyId, $facebookIds, $instagramIds, $byPage, $byInstagram, $primaryFacebook, $primaryInstagram) {
            $this->deleteAccounts($connection);
            foreach ($facebookIds as $id) {
                $p = $byPage[$id];
                SocialConnectionAccount::create([
                    'company_id' => $companyId, 'social_connection_id' => $connection->id, 'platform' => SocialFollowerSnapshot::PLATFORM_FACEBOOK,
                    'external_id' => $id, 'page_id' => $id, 'name' => $p['name'], 'page_access_token' => $p['access_token'], 'is_primary' => $id === $primaryFacebook,
                ]);
            }
            // Instagram e Página são escolhas independentes: a conta de Instagram guarda a
            // ligação da Página a que está ligada (page_id e token, cifrado), necessária para
            // a ler, mesmo quando a Página não foi escolhida (e então os seguidores da Página
            // não são lidos nem guardados).
            foreach ($instagramIds as $id) {
                $p = $byInstagram[$id];
                SocialConnectionAccount::create([
                    'company_id' => $companyId, 'social_connection_id' => $connection->id, 'platform' => SocialFollowerSnapshot::PLATFORM_INSTAGRAM,
                    'external_id' => $id, 'page_id' => $p['id'], 'name' => $p['instagram']['name'] ?? $p['name'], 'username' => $p['instagram']['username'] ?? null,
                    'page_access_token' => $p['access_token'], 'is_primary' => $id === $primaryInstagram,
                ]);
            }
            $connection->update(['status' => SocialConnection::STATUS_ACTIVE, 'last_error_at' => null, 'last_error_kind' => null, 'last_error_message' => null]);
        });

        ReadSocialFollowersJob::dispatch($companyId);
        \App\Models\CompanyConnectionEvent::record($companyId, \App\Models\CompanyConnectionEvent::KIND_SOCIAL, \App\Models\CompanyConnectionEvent::CONNECTED,
            $connection->setup_link_id ? null : ($actorId ?? $connection->connected_by_user_id), $connection->setup_link_id, [
                'facebook' => array_values(array_map(fn ($id) => $byPage[$id]['name'], $facebookIds)),
                'instagram' => array_values(array_map(fn ($id) => $byInstagram[$id]['instagram']['username'] ?? $byInstagram[$id]['instagram']['name'] ?? $id, $instagramIds)),
            ]);

        return $connection->fresh('accounts');
    }

    // ── 4. Leitura dos seguidores ──────────────────────────────────────────────

    /**
     * Lê os seguidores de todas as contas escolhidas. Grava em social_follower_snapshots
     * (origem "api", ganha ao registo manual do dia) só a conta principal de cada rede e
     * só quando a leitura devolveu de facto um número. Atualiza o estado da ligação.
     */
    public function readCompany(int $companyId): array
    {
        $connection = SocialConnection::where('company_id', $companyId)->first();
        if (! $connection || ! in_array($connection->status, SocialConnection::READABLE, true)) {
            return ['read' => 0, 'failed' => 0, 'skipped' => true];
        }

        $read = 0;
        $failures = [];
        foreach ($connection->accounts()->orderByDesc('is_primary')->get() as $account) {
            $token = (string) $account->page_access_token;
            $result = $account->platform === SocialFollowerSnapshot::PLATFORM_INSTAGRAM
                ? $this->meta->instagramFollowers($account->external_id, $token)
                : $this->meta->pageFollowers($account->external_id, $token);

            if ($result['ok']) {
                $read++;
                $account->update(['last_followers_count' => $result['followers'], 'last_read_at' => now(), 'last_error_at' => null, 'last_error_kind' => null]);
                $this->refreshProfile($account, $result['picture_url'] ?? null, $result['username'] ?? null);
                if ($account->is_primary) {
                    $this->followers->recordAutomatic($companyId, $account->platform, $result['followers'], $result['follows'], $result['media']);
                }
            } else {
                $failures[] = $result;
                $account->update(['last_error_at' => now(), 'last_error_kind' => $result['kind']]);
            }
        }

        if ($failures === []) {
            $connection->update([
                'status' => SocialConnection::STATUS_ACTIVE,
                'last_read_at' => $read > 0 ? now() : $connection->last_read_at,
                'last_error_at' => null, 'last_error_kind' => null, 'last_error_message' => null,
            ]);
        } else {
            if ($read > 0) {
                $connection->last_read_at = now();
            }
            // O erro mais grave decide o estado: expirado > permissão > falha pontual.
            $worst = collect($failures)->sortBy(fn ($f) => ['expired' => 0, 'permission' => 1][$f['kind']] ?? 2)->first();
            $this->applyError($connection, $worst);
        }

        return ['read' => $read, 'failed' => count($failures), 'skipped' => false];
    }

    /** Apaga as contas escolhidas e as fotos de perfil copiadas. */
    private function deleteAccounts(SocialConnection $connection): void
    {
        $paths = $connection->accounts()->whereNotNull('profile_picture_path')->pluck('profile_picture_path')->all();
        $connection->accounts()->delete();
        if ($paths) {
            \App\Services\Media\MediaService::disk()->delete($paths);
        }
    }

    /**
     * Foto de perfil e nome de utilizador reais (para a pré-visualização como na rede).
     * A Meta dá um URL temporário: a foto é copiada para o disco privado "media", no
     * máximo uma vez por semana. Falhar aqui nunca afeta a leitura dos seguidores.
     */
    private function refreshProfile(SocialConnectionAccount $account, ?string $pictureUrl, ?string $username): void
    {
        try {
            if ($username && $username !== $account->username) {
                $account->update(['username' => mb_substr($username, 0, 190)]);
            }
            $fresh = $account->profile_picture_updated_at && $account->profile_picture_updated_at->gt(now()->subDays(7));
            if (! $pictureUrl || ! str_starts_with($pictureUrl, 'https://') || ($fresh && $account->profile_picture_path)) {
                return;
            }
            $r = \Illuminate\Support\Facades\Http::timeout(10)->get($pictureUrl);
            $type = strtolower(trim(explode(';', (string) $r->header('Content-Type'))[0]));
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type] ?? null;
            $body = $r->body();
            if (! $r->successful() || ! $ext || strlen($body) === 0 || strlen($body) > 2 * 1024 * 1024) {
                return;
            }
            $disk = \App\Services\Media\MediaService::disk();
            $path = "social/company_{$account->company_id}/account_{$account->id}.{$ext}";
            if ($account->profile_picture_path && $account->profile_picture_path !== $path) {
                $disk->delete($account->profile_picture_path);
            }
            $disk->put($path, $body);
            $account->update(['profile_picture_path' => $path, 'profile_picture_updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[Redes sociais] Foto de perfil não atualizada', ['account_id' => $account->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    // ── 5. Desligar ────────────────────────────────────────────────────────────

    /**
     * Retira as três permissões das redes, uma a uma, com o token do utilizador (a
     * permissão dos anúncios fica intacta). Se a Meta não aceitar (token já expirado),
     * desliga na mesma. Por omissão mantém o histórico de seguidores; com purge apaga
     * as leituras automáticas (as manuais são do cliente e ficam).
     */
    public function disconnect(int $companyId, bool $purge, ?int $actorId = null): array
    {
        $connection = SocialConnection::where('company_id', $companyId)->first();
        if ($connection && $connection->status !== SocialConnection::STATUS_REVOKED) {
            \App\Models\CompanyConnectionEvent::record($companyId, \App\Models\CompanyConnectionEvent::KIND_SOCIAL, \App\Models\CompanyConnectionEvent::DISCONNECTED,
                $actorId, null, ['purge' => $purge, 'was_setup_link_id' => $connection->setup_link_id]);
        }

        $revoked = [];
        $token = (string) ($connection?->access_token ?? '');
        if ($token !== '') {
            foreach (SocialConnection::SCOPES as $permission) {
                $r = $this->meta->revokePermission($token, $permission);
                $revoked[$permission] = $r['revoked'];
                if (! $r['revoked']) {
                    Log::warning('[Redes sociais] Não foi possível retirar a permissão ao desligar; desligado na mesma.', [
                        'company_id' => $companyId, 'permission' => $permission, 'error' => $r['error'],
                    ]);
                }
            }
        }

        $deleted = null;
        DB::transaction(function () use ($connection, $companyId, $purge, &$deleted) {
            if ($connection) {
                $this->deleteAccounts($connection);
            }
            if ($purge) {
                $deleted = SocialFollowerSnapshot::where('company_id', $companyId)
                    ->where('source', '!=', SocialFollowerSnapshot::SOURCE_MANUAL)
                    ->delete();
                $connection?->delete();
            } elseif ($connection) {
                $connection->update([
                    'status' => SocialConnection::STATUS_REVOKED, 'access_token' => null, 'granted_scopes' => null,
                    'meta_user_id' => null, 'last_error_at' => null, 'last_error_kind' => null, 'last_error_message' => null,
                ]);
            }
        });

        return [
            'permissions_revoked' => $revoked !== [] && ! in_array(false, $revoked, true),
            'permissions' => $revoked,
            'purged' => $purge,
            'deleted_snapshots' => $deleted,
        ];
    }

    // ── Estado ─────────────────────────────────────────────────────────────────

    public function status(int $companyId): array
    {
        $connection = SocialConnection::with('accounts')->where('company_id', $companyId)->first();
        $automatic = SocialFollowerSnapshot::where('company_id', $companyId)->where('source', '!=', SocialFollowerSnapshot::SOURCE_MANUAL)->exists();

        return [
            'status' => $connection?->status,
            'connected_at' => $connection?->connected_at?->toIso8601String(),
            'token_expires_at' => $connection?->token_expires_at?->toIso8601String(),
            'last_read_at' => $connection?->last_read_at?->toIso8601String(),
            'last_error_at' => $connection?->last_error_at?->toIso8601String(),
            'last_error_kind' => $connection?->last_error_kind,
            'accounts' => $connection ? $connection->accounts->sortBy([['platform', 'asc'], ['is_primary', 'desc']])->values()->map(fn (SocialConnectionAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'external_id' => $a->external_id,
                'name' => $a->name,
                'username' => $a->username,
                'is_primary' => $a->is_primary,
                'last_followers_count' => $a->last_followers_count,
                'last_read_at' => $a->last_read_at?->toIso8601String(),
                'last_error_at' => $a->last_error_at?->toIso8601String(),
                'last_error_kind' => $a->last_error_kind,
            ])->all() : [],
            'has_automatic_history' => $automatic,
        ];
    }

    /** Resumo para o cartão de seguidores: por rede, se é automático e o estado da última leitura. */
    public function automation(int $companyId): array
    {
        $connection = SocialConnection::with(['accounts' => fn ($q) => $q->where('is_primary', true)])->where('company_id', $companyId)->first();
        $out = [];
        foreach (SocialFollowerSnapshot::PLATFORMS as $platform) {
            $account = $connection?->accounts->firstWhere('platform', $platform);
            $entry = $account && $connection->status !== SocialConnection::STATUS_REVOKED ? [
                'connected' => true,
                'connection_status' => $connection->status,
                'account' => $account->username ? '@' . $account->username : $account->name,
                'last_read_at' => $account->last_read_at?->toIso8601String(),
                'last_error_at' => $account->last_error_at?->toIso8601String(),
                'last_error_kind' => $account->last_error_kind,
            ] : ['connected' => false];
            $out[$platform] = $entry + self::manualEntry($entry);
        }

        return $out;
    }

    /**
     * Registo manual dos seguidores de uma rede: escondido enquanto a leitura automática
     * funciona; disponível (com o porquê) se a rede não está ligada, se a ligação expirou,
     * foi retirada, aguarda a aprovação da Meta, ou se a última leitura falhou.
     *
     * @return array{manual_allowed: bool, manual_reason: ?string}
     */
    public static function manualEntry(array $automation): array
    {
        if (! ($automation['connected'] ?? false)) {
            return ['manual_allowed' => true, 'manual_reason' => null];
        }

        $reason = match ($automation['connection_status'] ?? null) {
            SocialConnection::STATUS_EXPIRED => 'A ligação expirou: registe os seguidores à mão até voltar a ligar a rede.',
            SocialConnection::STATUS_PERMISSION_REMOVED => 'A autorização foi retirada no Facebook: registe os seguidores à mão até voltar a ligar a rede.',
            SocialConnection::STATUS_NOT_APPROVED => 'A leitura automática aguarda a aprovação da Meta: registe os seguidores à mão por agora.',
            default => ! empty($automation['last_error_at'])
                ? 'A última leitura automática falhou: pode registar à mão o valor de hoje.'
                : null,
        };

        return ['manual_allowed' => $reason !== null, 'manual_reason' => $reason];
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────────

    private function connectionWithToken(int $companyId): SocialConnection
    {
        $connection = SocialConnection::where('company_id', $companyId)->first();
        if (! $connection || $connection->status === SocialConnection::STATUS_REVOKED || (string) $connection->access_token === '') {
            throw new HttpException(404, 'As redes sociais não estão ligadas.');
        }

        return $connection;
    }

    /** Aplica um erro de leitura ao estado da ligação e devolve o estado resultante. */
    private function applyError(SocialConnection $connection, array $failure): string
    {
        $status = match ($failure['kind']) {
            MetaSocialClient::ERR_EXPIRED => SocialConnection::STATUS_EXPIRED,
            MetaSocialClient::ERR_PERMISSION => $this->meta->permissionState((string) $connection->access_token),
            default => in_array($connection->status, [SocialConnection::STATUS_PENDING_SELECTION], true) ? $connection->status : SocialConnection::STATUS_ACTIVE,
        };
        $kind = $failure['kind'] === MetaSocialClient::ERR_FAILED ? SocialConnection::ERROR_FAILED : $status;

        $connection->fill([
            'status' => $status,
            'last_error_at' => now(),
            'last_error_kind' => $kind,
            'last_error_message' => mb_substr((string) ($failure['message'] ?? ''), 0, 500),
        ])->save();

        return $status;
    }

    public static function statusMessage(string $status): string
    {
        return match ($status) {
            SocialConnection::STATUS_EXPIRED => 'Ligação expirada: voltar a ligar.',
            SocialConnection::STATUS_PERMISSION_REMOVED => 'A autorização foi retirada no Facebook: voltar a ligar.',
            SocialConnection::STATUS_NOT_APPROVED => 'A leitura automática fica disponível depois da aprovação da Meta. Pode continuar a registar os seguidores manualmente.',
            default => 'Não foi possível contactar a Meta. Tente novamente dentro de alguns minutos.',
        };
    }
}
