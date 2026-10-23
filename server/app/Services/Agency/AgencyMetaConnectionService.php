<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Integrations\SharedMetaUser;
use App\Services\Social\MetaSocialClientInterface;
use App\Services\Social\SocialConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Parceiro 2: a ligação da Meta da agência (uma por agência, decisão de 22/10).
 *  1. authUrl(): o administrador da agência autoriza com o seu login da Meta; volta pelo callback
 *     das redes (purpose "agency"), com as permissões das redes, o business_management e o ads_read.
 *  2. handleCallback(): sem todas as permissões não se guarda nada nem se revoga; com elas, guarda o
 *     token de longa duração (cifrado), o utilizador da Meta, as permissões e o estado.
 *  3. warnRenewals(): avisa a agência no sino, uma vez, quando faltam 10 dias ou menos para o fim do
 *     token, e marca a ligação como expirada quando passa.
 *  4. disconnect(): a ÚNICA ação que revoga na Meta, só as permissões que nenhuma outra ligação do
 *     mesmo utilizador usa (SharedMetaUser); o token apaga-se na XPLENDOR na mesma.
 * Sem utilizador de sistema por agora (fica para quando houver outra agência).
 */
class AgencyMetaConnectionService
{
    public const PURPOSE = 'agency';

    public function __construct(private readonly MetaSocialClientInterface $meta, private readonly AlertService $alerts) {}

    public function authUrl(Company $agency, User $actor): string
    {
        $nonce = Str::random(40);
        Cache::put(SocialConnectionService::stateKey($nonce), ['company_id' => $agency->id, 'user_id' => $actor->id, 'purpose' => self::PURPOSE], now()->addMinutes(15));

        return $this->meta->authUrl($nonce, AgencyMetaConnection::EXTRA_SCOPES);
    }

    /** O sinal para o redirecionamento: agency_ok | agency_error:<motivo>. */
    public function handleCallback(int $agencyId, array $state, ?string $code, ?string $error): string
    {
        if ($error !== null || $code === null || $code === '') {
            return $error !== null ? 'agency_error:denied' : 'agency_error:params';
        }
        if (! Company::whereKey($agencyId)->whereNotNull('agency_enabled_at')->exists()) {
            return 'agency_error:state';
        }
        $token = $this->meta->exchangeCode($code);
        if (! $token) {
            return 'agency_error:token';
        }
        if (array_diff(AgencyMetaConnection::SCOPES, (array) $token['scopes']) !== []) {
            // Sem todas as permissões não se guarda nada; não se revoga (podia cortar outras ligações).
            return 'agency_error:scopes';
        }
        DB::transaction(fn () => AgencyMetaConnection::updateOrCreate(['agency_company_id' => $agencyId], [
            'meta_user_id' => $token['user_id'] ?? null, 'access_token' => $token['token'], 'token_expires_at' => $token['expires_at'],
            'granted_scopes' => array_values(array_intersect((array) $token['scopes'], AgencyMetaConnection::SCOPES)),
            'status' => AgencyMetaConnection::STATUS_ACTIVE, 'connected_by_user_id' => $state['user_id'] ?? null,
            'connected_at' => now(), 'renewal_warned_at' => null,
        ]));
        Log::info('[Agência] Ligação da Meta guardada', ['agencia' => $agencyId]);

        return 'agency_ok';
    }

    /** O estado para o ecrã (sem o token). */
    public function status(int $agencyId): array
    {
        $c = AgencyMetaConnection::with('connectedBy:id,name')->where('agency_company_id', $agencyId)->first();
        if (! $c) {
            return ['connected' => false, 'status' => null, 'meta_user_id' => null, 'scopes' => [], 'missing_scopes' => AgencyMetaConnection::SCOPES,
                'connected_at' => null, 'connected_by' => null, 'expires_at' => null, 'days_left' => null, 'renew_soon' => false];
        }
        $daysLeft = $c->token_expires_at ? (int) floor(now()->diffInDays($c->token_expires_at, false)) : null;

        return [
            'connected' => true, 'status' => $c->status, 'meta_user_id' => $c->meta_user_id,
            'scopes' => array_values((array) $c->granted_scopes),
            'missing_scopes' => array_values(array_diff(AgencyMetaConnection::SCOPES, (array) $c->granted_scopes)),
            'connected_at' => $c->connected_at?->toIso8601String(), 'connected_by' => $c->connectedBy?->name,
            'expires_at' => $c->token_expires_at?->toIso8601String(), 'days_left' => $daysLeft,
            'renew_soon' => $daysLeft !== null && $daysLeft <= AgencyMetaConnection::RENEW_WARNING_DAYS,
        ];
    }

    /** @return array{warned: int, expired: int} */
    public function warnRenewals(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['warned' => 0, 'expired' => 0];
        $soon = AgencyMetaConnection::whereNotNull('token_expires_at')->where('token_expires_at', '<=', $now->addDays(AgencyMetaConnection::RENEW_WARNING_DAYS))->get();
        foreach ($soon as $c) {
            if ($c->token_expires_at->lte($now) && $c->status !== AgencyMetaConnection::STATUS_EXPIRED) {
                $c->update(['status' => AgencyMetaConnection::STATUS_EXPIRED]);
                $this->alerts->createSystemAlert($c->agency_company_id, 'urgent', 'Ligação da Meta da agência expirou',
                    'O acesso da agência à Meta expirou. Um administrador da agência tem de voltar a ligar em Agência › Meta.', 'high', '/agency?tab=meta');
                $out['expired']++;
            } elseif ($c->token_expires_at->gt($now) && $c->renewal_warned_at === null) {
                $days = max(1, (int) ceil($now->diffInDays($c->token_expires_at)));
                $this->alerts->createSystemAlert($c->agency_company_id, 'warning', 'Renovar a ligação da Meta da agência',
                    "A ligação da Meta da agência acaba dentro de {$days} " . ($days === 1 ? 'dia' : 'dias') . '. Um administrador da agência deve voltar a ligar em Agência › Meta.', 'medium', '/agency?tab=meta');
                $c->update(['renewal_warned_at' => $now]);
                $out['warned']++;
            }
        }

        return $out;
    }

    /** @return array{revoked: list<string>, kept_shared: list<string>} */
    public function disconnect(int $agencyId): array
    {
        $c = AgencyMetaConnection::where('agency_company_id', $agencyId)->firstOrFail();
        $revocable = SharedMetaUser::revocableAgencyScopes($c);
        $revoked = [];
        foreach ($revocable as $permission) {
            if ($this->meta->revokePermission((string) $c->access_token, $permission)['revoked']) {
                $revoked[] = $permission;
            }
        }
        $c->delete();
        Log::info('[Agência] Ligação da Meta desligada', ['agencia' => $agencyId, 'revogadas' => $revoked]);

        return ['revoked' => $revoked, 'kept_shared' => array_values(array_diff(AgencyMetaConnection::SCOPES, $revocable))];
    }
}
