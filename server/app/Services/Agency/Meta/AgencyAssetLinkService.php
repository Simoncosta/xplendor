<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

use App\Jobs\ReadSocialFollowersJob;
use App\Models\AgencyAssetLink;
use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use App\Services\MetaAccountInsightsService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Parceiro 4: ligar um ativo da Meta da agência (Página, conta de Instagram ou conta de anúncios) a uma
 * empresa gerida. Só clientes ativos desta agência; cada ativo só numa empresa. A ligação escreve também
 * nas tabelas de sempre, com a origem "agencia", para a leitura dos seguidores e as métricas de anúncios
 * funcionarem sem mudanças:
 *  · Página e Instagram: social_connections (o token da agência) e social_connection_accounts (o token da
 *    Página, pedido à Meta por GET /{page-id}?fields=access_token; sem tarefa na Página, a Meta não o dá e a
 *    ligação fica "sem_tarefa", com o que fazer);
 *  · conta de anúncios: company_integrations (plataforma meta, o token da agência e a conta).
 * Uma empresa com a sua própria ligação (por exemplo, feita com outro login) fica como está, a não ser que
 * se peça para passar para a ligação da agência (replace), empresa a empresa.
 */
class AgencyAssetLinkService
{
    public const NO_TASK_MESSAGE = 'A Meta não deu acesso a esta Página: no portfólio da Meta (Business Manager), atribua uma tarefa na Página à pessoa que ligou a Meta da agência e tente de novo.';

    public function __construct(
        private readonly MetaBusinessClientInterface $meta,
        private readonly AgencyPortfolioService $portfolios,
    ) {}

    /** As empresas ativas que esta agência gere (as únicas a que se pode ligar um ativo). @return array<int, string> id => nome */
    public function clients(int $agencyId): array
    {
        $ids = CompanyManagement::active()->where('agency_company_id', $agencyId)->pluck('managed_company_id');

        return Company::whereIn('id', $ids)->orderBy('fiscal_name')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => (string) ($c->trade_name ?: $c->fiscal_name)])->all();
    }

    public function isClient(int $agencyId, int $companyId): bool
    {
        return CompanyManagement::active()->where('agency_company_id', $agencyId)->where('managed_company_id', $companyId)->exists();
    }

    /** As ligações da agência, para o ecrã. */
    public function list(int $agencyId): array
    {
        $clients = $this->clients($agencyId);

        return [
            'clients' => collect($clients)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all(),
            'links' => AgencyAssetLink::where('agency_company_id', $agencyId)->orderBy('asset_type')->orderBy('name')->get()
                ->map(fn (AgencyAssetLink $l) => $this->present($l, $clients))->values()->all(),
        ];
    }

    private function present(AgencyAssetLink $l, array $clients): array
    {
        return ['id' => $l->id, 'asset_type' => $l->asset_type, 'external_id' => $l->external_id, 'page_id' => $l->page_id, 'name' => $l->name,
            'company_id' => $l->company_id, 'company' => $clients[$l->company_id] ?? null, 'status' => $l->status,
            'message' => $l->status === AgencyAssetLink::NO_TASK ? self::NO_TASK_MESSAGE : ($l->status === AgencyAssetLink::NO_ACCESS ? $l->status_reason : null),
            // Parceiro 5: a última verificação (diária, ou ao voltar a ligar a Meta da agência).
            'checked_at' => $l->checked_at?->toIso8601String()];
    }

    /**
     * Liga o ativo à empresa. Erros: 403 (empresa que a agência não gere), 409 (sem ligação da Meta ou a
     * empresa tem a sua própria ligação e não se pediu para passar), 422 (ativo desconhecido ou já noutra empresa).
     */
    public function link(int $agencyId, int $companyId, string $type, string $externalId, User $actor, bool $replace = false): array
    {
        if (! $this->isClient($agencyId, $companyId)) {
            throw new HttpException(403, 'Esta empresa não é um cliente ativo desta agência.');
        }
        $connection = AgencyMetaConnection::where('agency_company_id', $agencyId)->first();
        if (! $connection || $connection->status !== AgencyMetaConnection::STATUS_ACTIVE) {
            throw new HttpException(409, 'Ligue primeiro a Meta da agência (ou volte a ligá-la).');
        }
        $asset = $this->findAsset($agencyId, $type, $externalId);
        if (! $asset) {
            throw new HttpException(422, 'Este ativo não está nos portfólios que a agência vê. Atualize a lista e tente de novo.');
        }
        $existing = AgencyAssetLink::where('asset_type', $type)->where('external_id', $externalId)->first();
        if ($existing && (int) $existing->company_id !== $companyId) {
            $other = Company::find($existing->company_id);
            throw new HttpException(422, 'Este ativo já está ligado a ' . ($other?->trade_name ?: $other?->fiscal_name ?: 'outra empresa') . '. Cada ativo só pode estar numa empresa.');
        }

        return DB::transaction(function () use ($agencyId, $companyId, $type, $externalId, $actor, $replace, $asset, $connection, $existing) {
            $link = $existing ?? new AgencyAssetLink(['agency_company_id' => $agencyId, 'company_id' => $companyId, 'asset_type' => $type, 'external_id' => $externalId]);
            $link->fill(['name' => $asset['name'], 'page_id' => $asset['page_id'], 'business_id' => $asset['business_id'], 'linked_by_user_id' => $actor->id]);

            if ($type === AgencyAssetLink::AD_ACCOUNT) {
                $this->writeAdAccount($companyId, $externalId, $connection, $actor, $replace);
                $link->status = AgencyAssetLink::LINKED;
            } else {
                $token = $this->meta->pageToken((string) $connection->access_token, (string) $asset['page_id']);
                if (! $token['ok']) {
                    throw new HttpException(422, 'A Meta não respondeu ao pedir o acesso à Página. Tente novamente dentro de momentos.');
                }
                if (($token['access_token'] ?? '') === '') {
                    $link->status = AgencyAssetLink::NO_TASK;
                } else {
                    $this->writeSocial($companyId, $type, $externalId, $asset, (string) $token['access_token'], $connection, $actor, $replace);
                    $link->status = AgencyAssetLink::LINKED;
                }
            }
            // Ligar de novo limpa o motivo de um "sem acesso" anterior.
            $link->status_reason = null;
            $link->checked_at = now();
            $link->save();

            return $this->present($link, $this->clients($agencyId));
        });
    }

    /** Desliga o ativo da empresa: sai das tabelas de sempre só o que a agência lá escreveu. */
    public function unlink(int $agencyId, int $linkId): void
    {
        $link = AgencyAssetLink::where('agency_company_id', $agencyId)->findOrFail($linkId);
        DB::transaction(function () use ($link) {
            if ($link->asset_type === AgencyAssetLink::AD_ACCOUNT) {
                CompanyIntegration::where('company_id', $link->company_id)->where('platform', 'meta')->where('origin', AgencyAssetLink::ORIGIN)
                    ->where('account_id', $link->external_id)->update(['status' => 'revoked']);
            } else {
                $connection = SocialConnection::where('company_id', $link->company_id)->where('origin', AgencyAssetLink::ORIGIN)->first();
                if ($connection) {
                    $platform = $link->asset_type === AgencyAssetLink::PAGE ? SocialFollowerSnapshot::PLATFORM_FACEBOOK : SocialFollowerSnapshot::PLATFORM_INSTAGRAM;
                    $connection->accounts()->where('platform', $platform)->where('external_id', $link->external_id)->delete();
                }
            }
            $link->delete();
        });
    }

    /** O ativo nos portfólios que a agência vê (a leitura guardada do Parceiro 3). */
    private function findAsset(int $agencyId, string $type, string $externalId): ?array
    {
        $list = $this->portfolios->list($agencyId);
        foreach ($list['businesses'] ?? [] as $b) {
            foreach ($b['pages'] ?? [] as $p) {
                if ($type === AgencyAssetLink::PAGE && $p['id'] === $externalId) {
                    return ['name' => $p['name'], 'page_id' => $p['id'], 'business_id' => $b['id'], 'instagram' => $p['instagram']];
                }
                if ($type === AgencyAssetLink::INSTAGRAM && ($p['instagram']['id'] ?? null) === $externalId) {
                    return ['name' => $p['instagram']['username'] ? '@' . $p['instagram']['username'] : $p['name'], 'page_id' => $p['id'], 'business_id' => $b['id'],
                        'username' => $p['instagram']['username'], 'page_name' => $p['name']];
                }
            }
            foreach ($b['ad_accounts'] ?? [] as $a) {
                if ($type === AgencyAssetLink::AD_ACCOUNT && $a['account_id'] === $externalId) {
                    return ['name' => $a['name'], 'page_id' => null, 'business_id' => $b['id']];
                }
            }
        }

        return null;
    }

    private function writeSocial(int $companyId, string $type, string $externalId, array $asset, string $pageToken, AgencyMetaConnection $agency, User $actor, bool $replace): void
    {
        $connection = SocialConnection::where('company_id', $companyId)->first();
        if ($connection && $connection->origin !== AgencyAssetLink::ORIGIN && ! $replace) {
            throw new HttpException(409, 'Esta empresa já tem a sua própria ligação às redes. Pode passá-la para a ligação da agência ("Passar para a agência").');
        }
        $data = ['meta_user_id' => $agency->meta_user_id, 'access_token' => $agency->access_token, 'token_expires_at' => $agency->token_expires_at,
            'granted_scopes' => $agency->granted_scopes, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_by_user_id' => $actor->id,
            'origin' => AgencyAssetLink::ORIGIN, 'last_error_at' => null, 'last_error_kind' => null, 'last_error_message' => null];
        if ($connection) {
            if ($connection->origin !== AgencyAssetLink::ORIGIN) {
                // Passar para a agência: as contas da ligação antiga saem (as da agência entram a seguir).
                $connection->accounts()->delete();
                $data['connected_at'] = now();
            }
            $connection->forceFill($data)->save();
        } else {
            $connection = new SocialConnection(['company_id' => $companyId]);
            $connection->forceFill($data + ['connected_at' => now()])->save();
        }

        $platform = $type === AgencyAssetLink::PAGE ? SocialFollowerSnapshot::PLATFORM_FACEBOOK : SocialFollowerSnapshot::PLATFORM_INSTAGRAM;
        $hasPrimary = $connection->accounts()->where('platform', $platform)->where('is_primary', true)->where('external_id', '!=', $externalId)->exists();
        SocialConnectionAccount::updateOrCreate(
            ['social_connection_id' => $connection->id, 'platform' => $platform, 'external_id' => $externalId],
            ['company_id' => $companyId, 'page_id' => $asset['page_id'], 'name' => $type === AgencyAssetLink::PAGE ? $asset['name'] : ($asset['page_name'] ?? $asset['name']),
                'username' => $asset['username'] ?? null, 'page_access_token' => $pageToken, 'is_primary' => ! $hasPrimary],
        );
        ReadSocialFollowersJob::dispatch($companyId);
    }

    private function writeAdAccount(int $companyId, string $accountId, AgencyMetaConnection $agency, User $actor, bool $replace): void
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        if ($integration && $integration->origin !== AgencyAssetLink::ORIGIN && $integration->status !== 'revoked' && ! $replace) {
            throw new HttpException(409, 'Esta empresa já tem a sua própria ligação aos anúncios. Pode passá-la para a ligação da agência ("Passar para a agência").');
        }
        $previous = $integration?->account_id;
        $integration ??= new CompanyIntegration(['company_id' => $companyId, 'platform' => 'meta']);
        $integration->forceFill(['access_token' => $agency->access_token, 'account_id' => $accountId, 'token_expires_at' => $agency->token_expires_at,
            'meta_user_id' => $agency->meta_user_id, 'status' => 'active', 'error_message' => null, 'connected_by_user_id' => $actor->id,
            'origin' => AgencyAssetLink::ORIGIN])->save();
        app(MetaAccountInsightsService::class)->onAccountChanged($integration, $previous);
    }
}
