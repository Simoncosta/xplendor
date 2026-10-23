<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

use App\Models\AgencyAssetLink;
use App\Models\AgencyMetaConnection;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialFollowerSnapshot;
use App\Services\AlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parceiro 5: o ciclo de vida dos ativos da agência ligados às empresas geridas.
 *  · propagateToken(): depois de renovar a ligação da Meta da agência (a manual, ao voltar a ligar, ou a
 *    verificação diária), o token novo chega às ligações dos clientes com a origem "agencia": relê o token
 *    de cada Página ligada (GET /{page-id}?fields=access_token) e atualiza a cópia guardada nas contas de
 *    anúncios (company_integrations). O que falhar passa a "sem acesso", com o motivo.
 *  · checkPortfolio(): um ativo que saiu dos portfólios que a agência vê passa a "sem acesso".
 *  · O sino da agência avisa, uma vez, dos ativos que passaram a "sem acesso".
 * A Meta chega só pelo MetaBusinessClientInterface (simulado nos testes).
 */
class AgencyAssetLifecycle
{
    public const LEFT_PORTFOLIO = 'Saiu dos portfólios da Meta que a agência vê. Volte a partilhá-lo com a agência no portfólio do cliente ou desligue-o.';
    public const AGENCY_EXPIRED = 'O acesso da agência à Meta expirou. Um administrador da agência tem de voltar a ligar em Agência › Meta.';
    public const PAGE_FAILED = 'A Meta não respondeu ao renovar o acesso à Página. Volta a tentar na próxima verificação.';
    public const ADS_MISSING = 'A ligação dos anúncios desta empresa já não é a da agência (foi desligada ou passou a ser da própria empresa).';

    public function __construct(
        private readonly MetaBusinessClientInterface $meta,
        private readonly AgencyPortfolioService $portfolios,
        private readonly AlertService $alerts,
    ) {}

    /**
     * $skipLeftPortfolio: na verificação diária, os ativos que saíram dos portfólios ficam com esse motivo.
     *
     * @return array{linked: int, no_access: int}
     */
    public function propagateToken(int $agencyId, ?CarbonImmutable $now = null, bool $skipLeftPortfolio = false): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['linked' => 0, 'no_access' => 0];
        $agency = AgencyMetaConnection::where('agency_company_id', $agencyId)->first();
        $links = AgencyAssetLink::where('agency_company_id', $agencyId)
            ->when($skipLeftPortfolio, fn ($q) => $q->where(fn ($w) => $w->whereNull('status_reason')->orWhere('status_reason', '!=', self::LEFT_PORTFOLIO)))
            ->get();
        if (! $agency || $links->isEmpty()) {
            return $out;
        }
        $token = (string) $agency->access_token;
        $pageTokens = [];
        $newlyLost = [];
        foreach ($links as $link) {
            if ($link->asset_type === AgencyAssetLink::AD_ACCOUNT) {
                // Pelo modelo: o token é cifrado em cada tabela.
                $copies = CompanyIntegration::where('company_id', $link->company_id)->where('platform', 'meta')->where('origin', AgencyAssetLink::ORIGIN)
                    ->where('account_id', $link->external_id)->where('status', '!=', 'revoked')->get();
                $copies->each(fn (CompanyIntegration $i) => $i->forceFill(['access_token' => $agency->access_token, 'token_expires_at' => $agency->token_expires_at,
                    'meta_user_id' => $agency->meta_user_id])->save());
                $reason = $copies->isNotEmpty() ? null : self::ADS_MISSING;
            } else {
                $pageId = (string) $link->page_id;
                $pageTokens[$pageId] ??= $this->meta->pageToken($token, $pageId);
                $r = $pageTokens[$pageId];
                $reason = ! $r['ok'] ? self::PAGE_FAILED : ((string) ($r['access_token'] ?? '') === '' ? AgencyAssetLinkService::NO_TASK_MESSAGE : null);
                if ($reason === null) {
                    $this->writePageToken($link, (string) $r['access_token'], $agency);
                }
            }
            if ($reason === null) {
                $link->fill(['status' => AgencyAssetLink::LINKED, 'status_reason' => null, 'checked_at' => $now])->save();
                $out['linked']++;
            } else {
                if ($link->status !== AgencyAssetLink::NO_ACCESS) {
                    $newlyLost[] = $link;
                }
                $link->fill(['status' => AgencyAssetLink::NO_ACCESS, 'status_reason' => $reason, 'checked_at' => $now])->save();
                $out['no_access']++;
            }
        }
        $this->notifyLost($agencyId, $newlyLost);
        Log::info('[Agência] Token da Meta propagado aos clientes', ['agencia' => $agencyId] + $out);

        return $out;
    }

    /**
     * Os ativos que já não estão nos portfólios que a agência vê passam a "sem acesso". Uma leitura que
     * falha não muda nada, salvo o token expirado (todos ficam sem acesso, com esse motivo).
     *
     * @return array{ok: bool, no_access: int}
     */
    public function checkPortfolio(int $agencyId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $list = $this->portfolios->list($agencyId, true);
        $links = AgencyAssetLink::where('agency_company_id', $agencyId)->get();
        if (! $list['ok']) {
            if (($list['kind'] ?? null) !== \App\Services\Social\MetaSocialClient::ERR_EXPIRED) {
                return ['ok' => false, 'no_access' => 0];
            }
            $lost = $links->filter(fn ($l) => $l->status !== AgencyAssetLink::NO_ACCESS)->values()->all();
            AgencyAssetLink::where('agency_company_id', $agencyId)->update(['status' => AgencyAssetLink::NO_ACCESS, 'status_reason' => self::AGENCY_EXPIRED, 'checked_at' => $now]);
            $this->notifyLost($agencyId, $lost);

            return ['ok' => false, 'no_access' => $links->count()];
        }
        $seen = ['page' => [], 'instagram' => [], 'ad_account' => []];
        foreach ($list['businesses'] as $b) {
            foreach ($b['pages'] ?? [] as $p) {
                $seen['page'][(string) $p['id']] = true;
                if (! empty($p['instagram']['id'])) {
                    $seen['instagram'][(string) $p['instagram']['id']] = true;
                }
            }
            foreach ($b['ad_accounts'] ?? [] as $a) {
                $seen['ad_account'][(string) $a['account_id']] = true;
            }
        }
        $lost = [];
        foreach ($links as $link) {
            if (isset($seen[$link->asset_type][$link->external_id])) {
                // Voltou aos portfólios: o motivo antigo sai e a renovação do token decide o estado.
                $link->fill(['checked_at' => $now] + ($link->status_reason === self::LEFT_PORTFOLIO ? ['status_reason' => null] : []))->save();
                continue;
            }
            if ($link->status !== AgencyAssetLink::NO_ACCESS) {
                $lost[] = $link;
            }
            $link->fill(['status' => AgencyAssetLink::NO_ACCESS, 'status_reason' => self::LEFT_PORTFOLIO, 'checked_at' => $now])->save();
        }
        $this->notifyLost($agencyId, $lost);

        return ['ok' => true, 'no_access' => count($lost)];
    }

    /**
     * A verificação diária de cada agência com a Meta ligada e ativa: os portfólios e, depois, o token
     * nas ligações dos clientes (só os ativos ainda nos portfólios).
     *
     * @return array<int, array{portfolio: array, token: ?array}>
     */
    public function daily(?CarbonImmutable $now = null): array
    {
        $out = [];
        foreach (AgencyMetaConnection::where('status', AgencyMetaConnection::STATUS_ACTIVE)->pluck('agency_company_id') as $agencyId) {
            if (! AgencyAssetLink::where('agency_company_id', $agencyId)->exists()) {
                continue;
            }
            $portfolio = $this->checkPortfolio((int) $agencyId, $now);
            $out[(int) $agencyId] = ['portfolio' => $portfolio, 'token' => $portfolio['ok'] ? $this->propagateToken((int) $agencyId, $now, true) : null];
        }

        return $out;
    }

    private function writePageToken(AgencyAssetLink $link, string $pageToken, AgencyMetaConnection $agency): void
    {
        DB::transaction(function () use ($link, $pageToken, $agency) {
            $connection = SocialConnection::where('company_id', $link->company_id)->where('origin', AgencyAssetLink::ORIGIN)->first();
            if (! $connection) {
                return;
            }
            $connection->forceFill(['access_token' => $agency->access_token, 'token_expires_at' => $agency->token_expires_at, 'meta_user_id' => $agency->meta_user_id,
                'granted_scopes' => $agency->granted_scopes, 'status' => SocialConnection::STATUS_ACTIVE, 'last_error_at' => null, 'last_error_kind' => null, 'last_error_message' => null])->save();
            $platform = $link->asset_type === AgencyAssetLink::PAGE ? SocialFollowerSnapshot::PLATFORM_FACEBOOK : SocialFollowerSnapshot::PLATFORM_INSTAGRAM;
            SocialConnectionAccount::where('social_connection_id', $connection->id)->where('platform', $platform)->where('external_id', $link->external_id)
                ->get()->each(fn ($a) => $a->forceFill(['page_access_token' => $pageToken])->save());
        });
    }

    /** @param list<AgencyAssetLink> $lost */
    private function notifyLost(int $agencyId, array $lost): void
    {
        if ($lost === []) {
            return;
        }
        $names = array_map(fn (AgencyAssetLink $l) => (string) ($l->name ?: $l->external_id), $lost);
        $n = count($lost);
        $this->alerts->createSystemAlert($agencyId, 'warning', $n === 1 ? 'Um ativo da Meta ficou sem acesso' : "{$n} ativos da Meta ficaram sem acesso",
            mb_substr(implode(', ', $names) . '. Veja o motivo de cada um em Agência › Meta › Ativos ligados.', 0, 900), 'medium', '/agency?tab=meta');
    }
}
