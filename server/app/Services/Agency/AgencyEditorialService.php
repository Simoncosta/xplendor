<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkItem;
use App\Models\EditorialPost;
use App\Models\User;
use App\Services\Editorial\EditorialPublishingService;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\Tenancy\CompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Linha Editorial da agência: as vistas de VÁRIAS empresas de uma vez (a própria agência e
 * os clientes que a pessoa vê), com os dados do cliente em cada item. Entram só as empresas
 * com o módulo da Linha Editorial ativo; os clientes vêm das relações ativas e das
 * atribuições (CompanyAccess::visibleManaged), nunca de outra regra.
 */
class AgencyEditorialService
{
    public const MODULE = 'linha_editorial';

    public function __construct(
        private readonly CompanyAccess $access,
        private readonly EditorialPublishingService $publishing,
    ) {}

    /**
     * As empresas desta vista (a agência primeiro, depois os clientes por nome).
     *
     * @param  int[]|null  $only  filtro por cliente (ids); fora do âmbito são ignorados
     * @return Collection<int, Company>
     */
    public function companies(Company $agency, User $user, ?array $only = null, bool $editorialOnly = true): Collection
    {
        $ids = array_merge([$agency->id], $this->access->visibleManaged($agency->id, $user));
        if ($only) {
            $ids = array_values(array_intersect($ids, array_map('intval', $only)));
        }
        if ($editorialOnly) {
            $ids = CompanyModule::whereIn('company_id', $ids)->where('module_key', self::MODULE)->pluck('company_id')->map(fn ($id) => (int) $id)->all();
        }

        return Company::whereIn('id', $ids)->get(['id', 'fiscal_name', 'trade_name', 'logo_path', 'agency_enabled_at'])
            ->sortBy(fn (Company $c) => ($c->id === $agency->id ? '0' : '1') . mb_strtolower(self::name($c)))->values();
    }

    public static function name(Company $c): string
    {
        return (string) ($c->trade_name ?: $c->fiscal_name);
    }

    /** O cliente de cada item. */
    public static function present(Company $c, Company $agency): array
    {
        return ['id' => $c->id, 'name' => self::name($c), 'logo_path' => $c->logo_path, 'is_agency' => $c->id === $agency->id];
    }

    /** As publicações do mês (calendário e Kanban), de todas as empresas da vista. */
    public function posts(Collection $companies, Company $agency, User $user, string $month): array
    {
        $start = CarbonImmutable::parse("{$month}-01");
        $byId = $companies->keyBy('id');
        $producer = $companies->mapWithKeys(fn (Company $c) => [$c->id => EditorialWorkflowService::isProducer($user, $c->id)]);

        return EditorialPost::whereIn('company_id', $byId->keys())
            ->whereBetween('publish_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with(['currentVersion:id,number,status', 'networks'])
            ->orderBy('publish_date')->orderBy('id')->get()
            ->map(fn (EditorialPost $p) => [
                'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel,
                'publish_date' => $p->publish_date->toDateString(), 'publish_time' => $p->publish_time, 'month_key' => $p->publish_date->format('Y-m'),
                'stage' => $p->stage, 'format' => $p->format, 'media_format' => $p->media_format, 'pillar' => $p->pillar,
                'overdue' => $p->isOverdue(),
                'networks' => $p->networks->map(fn ($n) => ['network' => $n->network, 'media_format' => $n->media_format, 'state' => $n->state()])->values()->all(),
                'version' => $p->currentVersion ? ['number' => $p->currentVersion->number, 'status' => $p->currentVersion->status] : null,
                'changes_requested' => $p->changes_requested_at !== null,
                'can_produce' => (bool) $producer[$p->company_id],
                'company' => self::present($byId[$p->company_id], $agency),
            ])->all();
    }

    /** Para publicar hoje e atrasadas (como o "Para publicar hoje" de uma empresa). */
    public function today(Collection $companies, Company $agency, User $user): array
    {
        $today = CarbonImmutable::now(EditorialPost::TIMEZONE)->toDateString();
        $byId = $companies->keyBy('id');
        $posts = $this->scheduledUntil($byId->keys()->all(), $today);
        $row = fn (EditorialPost $p) => EditorialPublishingService::summary($p) + [
            'can_mark' => EditorialWorkflowService::isProducer($user, (int) $p->company_id),
            'company' => self::present($byId[$p->company_id], $agency),
        ];

        return [
            'date' => $today,
            'today' => $posts->filter(fn ($p) => $p->publish_date->toDateString() === $today)->map($row)->values()->all(),
            'overdue' => $posts->filter(fn ($p) => $p->isOverdue())->map($row)->values()->all(),
        ];
    }

    /** À espera de aprovação: a etapa de aprovação, com os links por decidir de cada publicação. */
    public function awaiting(Collection $companies, Company $agency): array
    {
        $byId = $companies->keyBy('id');
        $posts = EditorialPost::whereIn('company_id', $byId->keys())->where('stage', EditorialPost::STAGE_CLIENT_REVIEW)
            ->with('networks')->orderBy('publish_date')->orderBy('id')->get();
        $links = $this->pendingLinks($posts);

        return $posts->map(fn (EditorialPost $p) => EditorialPublishingService::summary($p) + [
            'stage' => $p->stage,
            'links' => $links[$p->id] ?? [],
            'company' => self::present($byId[$p->company_id], $agency),
        ])->values()->all();
    }

    /** Resultados do mês (uma linha por publicação e por rede publicada), com o cliente. */
    public function results(Collection $companies, Company $agency, string $month): array
    {
        return $companies->flatMap(fn (Company $c) => array_map(
            fn (array $row) => $row + ['company' => self::present($c, $agency)],
            $this->publishing->results($c->id, $month),
        ))->values()->all();
    }

    /** Painel da agência: por cliente, para publicar hoje, atrasadas, à espera de aprovação, em produção. */
    public function panel(Collection $companies, Company $agency): array
    {
        $ids = $companies->pluck('id')->all();
        $today = CarbonImmutable::now(EditorialPost::TIMEZONE)->toDateString();
        $scheduled = $this->scheduledUntil($ids, $today)->groupBy('company_id');
        $stages = EditorialPost::whereIn('company_id', $ids)->whereIn('stage', [EditorialPost::STAGE_CLIENT_REVIEW, EditorialPost::STAGE_PRODUCTION])
            ->selectRaw('company_id, stage, count(*) as n')->groupBy('company_id', 'stage')->get()
            ->groupBy('company_id')->map(fn ($rows) => $rows->pluck('n', 'stage'));

        return $companies->map(function (Company $c) use ($scheduled, $stages, $today, $agency) {
            $mine = $scheduled[$c->id] ?? collect();

            return [
                'company' => self::present($c, $agency),
                'today' => $mine->filter(fn ($p) => $p->publish_date->toDateString() === $today)->count(),
                'overdue' => $mine->filter(fn ($p) => $p->isOverdue())->count(),
                'awaiting' => (int) ($stages[$c->id][EditorialPost::STAGE_CLIENT_REVIEW] ?? 0),
                'production' => (int) ($stages[$c->id][EditorialPost::STAGE_PRODUCTION] ?? 0),
            ];
        })->values()->all();
    }

    /** @param int[] $ids */
    private function scheduledUntil(array $ids, string $today): Collection
    {
        return EditorialPost::whereIn('company_id', $ids)->where('stage', EditorialPost::STAGE_SCHEDULED)
            ->where('channel', '!=', EditorialPost::CHANNEL_SITE)->whereDate('publish_date', '<=', $today)->with('networks')
            ->get()->sortBy(fn (EditorialPost $p) => EditorialPublishingService::sortKey($p))->values();
    }

    /** Os links válidos onde a versão atual de cada publicação espera decisão. @return array<int, array> */
    private function pendingLinks(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        $versionOf = $posts->pluck('current_version_id', 'id');

        return ContentReviewLinkItem::whereIn('editorial_post_id', $posts->pluck('id'))
            ->whereHas('link', fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now()))
            ->with('link:id,title,expires_at')->get()
            ->filter(fn ($item) => (int) $item->version_id === (int) $versionOf[$item->editorial_post_id])
            ->groupBy('editorial_post_id')
            ->map(fn ($items) => $items->map(fn ($i) => ['id' => $i->link->id, 'title' => $i->link->title, 'expires_at' => $i->link->expires_at->toIso8601String()])->values()->all())
            ->all();
    }
}
