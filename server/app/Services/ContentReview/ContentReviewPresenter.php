<?php

declare(strict_types=1);

namespace App\Services\ContentReview;

use App\Models\Company;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkItem;
use App\Models\EditorialPost;
use App\Models\EditorialPostComment;
use App\Models\EditorialPostReview;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnectionAccount;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * O que a página do link de aprovação mostra (e o "Ver como o cliente" da equipa): as
 * publicações do lote na versão enviada, com o estado honesto de cada uma, as contas
 * ligadas da empresa (foto e nome reais, ou o logótipo e o nome da empresa) e os ficheiros
 * por URLs assinados que só servem para este lote e enquanto o link estiver válido.
 */
class ContentReviewPresenter
{
    public const ITEM_PENDING = 'pending';
    public const ITEM_APPROVED = 'approved';
    public const ITEM_CHANGES = 'changes_requested';
    public const ITEM_OUTDATED = 'outdated';

    private const STATE_MESSAGES = [
        ContentReviewLink::STATE_EXPIRED => 'Este link expirou. Pode consultar as decisões, mas já não aprovar. Peça um novo link à equipa.',
        ContentReviewLink::STATE_REVOKED => 'Este link foi desativado pela equipa. Pode consultar as decisões, mas já não aprovar.',
    ];

    /**
     * Estado de cada item: decidido (aprovado ou alterações pedidas) na versão do lote;
     * "atualizado pela equipa" se a publicação mudou depois do envio; senão, pendente.
     *
     * @param  Collection<int, ContentReviewLinkItem>  $items  com post carregado
     * @return array<int, array{state: string, decision: ?EditorialPostReview}>  por id do item
     */
    public static function itemStates(Collection $items): array
    {
        $decisions = EditorialPostReview::whereIn('version_id', $items->pluck('version_id'))->orderBy('id')->get()->keyBy('version_id');
        $out = [];
        foreach ($items as $item) {
            $decision = $decisions[$item->version_id] ?? null;
            $post = $item->post;
            $state = match (true) {
                $decision?->decision === EditorialPostReview::APPROVED => self::ITEM_APPROVED,
                $decision?->decision === EditorialPostReview::CHANGES_REQUESTED => self::ITEM_CHANGES,
                ! $post || $post->stage !== EditorialPost::STAGE_CLIENT_REVIEW || (int) $post->current_version_id !== (int) $item->version_id => self::ITEM_OUTDATED,
                default => self::ITEM_PENDING,
            };
            $out[$item->id] = ['state' => $state, 'decision' => $decision];
        }

        return $out;
    }

    /** @return array{pending: int, approved: int, changes_requested: int, outdated: int} */
    public static function counts(array $states): array
    {
        $counts = [self::ITEM_PENDING => 0, self::ITEM_APPROVED => 0, self::ITEM_CHANGES => 0, self::ITEM_OUTDATED => 0];
        foreach ($states as $s) {
            $counts[$s['state']]++;
        }

        return $counts;
    }

    public function payload(ContentReviewLink $link, bool $preview = false): array
    {
        $link->loadMissing(['items.post', 'items.version', 'company']);
        $items = $link->items;
        $states = self::itemStates($items);
        $state = $link->state();
        $open = $state === ContentReviewLink::STATE_OPEN;
        $company = $link->company;

        $comments = EditorialPostComment::whereIn('editorial_post_id', $items->pluck('editorial_post_id'))
            ->where('visibility', EditorialPostComment::SHARED)->orderBy('id')->get()->groupBy('editorial_post_id');
        $versionNumbers = EditorialPostVersion::whereIn('id', $comments->flatten()->pluck('version_id')->filter())->pluck('number', 'id');

        return [
            'preview' => $preview,
            'state' => $state,
            'state_message' => self::STATE_MESSAGES[$state] ?? null,
            'can_act' => $open && ! $preview,
            'title' => $link->title,
            'recipient_name' => $link->recipient_name,
            'expires_at' => $link->expires_at->toIso8601String(),
            'company' => self::companyIdentity($company),
            'accounts' => $this->accounts($link, $open || $preview),
            'counts' => self::counts($states),
            'items' => $items->map(function (ContentReviewLinkItem $item) use ($states, $link, $open, $preview, $comments, $versionNumbers) {
                $v = $item->version;
                $s = $states[$item->id];
                $d = $s['decision'];

                return [
                    'id' => $item->id,
                    'post_id' => $item->editorial_post_id,
                    'title' => (string) $item->post?->title,
                    'channel' => $item->post?->channel,
                    'media_format' => $v?->media_format ?? $item->post?->media_format,
                    'publish_date' => $item->post?->publish_date?->toDateString(),
                    'version_number' => $v?->number,
                    'caption' => (string) ($v?->caption ?? ''),
                    'hashtags' => $v?->hashtags ?? [],
                    'cta' => $v?->cta,
                    'first_comment' => $v?->first_comment,
                    // Ficheiros só com o link válido (expirado ou revogado: só consulta, sem ficheiros).
                    'media' => ($open || $preview) ? $this->media($v, $link, $preview) : ['items' => [], 'cover' => null],
                    'media_available' => $open || $preview,
                    'state' => $s['state'],
                    'decision' => $d ? [
                        'decision' => $d->decision, 'reviewer_name' => $d->reviewer_name, 'message' => $d->message,
                        'via' => $d->via, 'created_at' => optional($d->created_at)->toIso8601String(),
                    ] : null,
                    'can_act' => $open && ! $preview && $s['state'] === self::ITEM_PENDING,
                    'comments' => ($comments[$item->editorial_post_id] ?? collect())->map(fn (EditorialPostComment $c) => [
                        'id' => $c->id, 'author' => $c->author_name, 'body' => $c->body, 'from_link' => $c->review_link_id !== null,
                        'version_number' => $c->version_id ? ($versionNumbers[$c->version_id] ?? null) : null,
                        'created_at' => optional($c->created_at)->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    public static function companyIdentity(?Company $company): array
    {
        $logo = $company?->logo_path;

        return [
            'name' => $company ? (string) ($company->trade_name ?: $company->fiscal_name) : '',
            'logo_url' => $logo ? (str_starts_with($logo, 'http') ? $logo : rtrim((string) config('app.url'), '/') . '/' . ltrim($logo, '/')) : null,
        ];
    }

    /** Contas ligadas da empresa (a principal de cada rede): nome, nome de utilizador e foto. */
    private function accounts(ContentReviewLink $link, bool $withPictures): array
    {
        $out = ['instagram' => null, 'facebook' => null];
        $accounts = SocialConnectionAccount::where('company_id', $link->company_id)->orderByDesc('is_primary')->orderBy('id')->get();
        foreach (['instagram', 'facebook'] as $platform) {
            $a = $accounts->firstWhere('platform', $platform);
            if (! $a) {
                continue;
            }
            $out[$platform] = [
                'name' => $a->name,
                'username' => $a->username,
                'avatar_url' => $withPictures && $a->profile_picture_path
                    ? URL::temporarySignedRoute('social.avatar', $this->expiry($link), ['account' => $a->id], absolute: false)
                    : null,
            ];
        }

        return $out;
    }

    private function media(?EditorialPostVersion $version, ContentReviewLink $link, bool $preview): array
    {
        if (! $version) {
            return ['items' => [], 'cover' => null];
        }
        if ($preview) {
            // A equipa vê com os URLs normais da app (não conta como abertura nem depende da validade).
            return EditorialWorkflowService::presentMedia($version);
        }
        $expiry = $this->expiry($link);
        $sign = fn (MediaAsset $a, string $variant) => URL::temporarySignedRoute('review.media', $expiry,
            ['link' => $link->id, 'asset' => $a->id, 'variant' => $variant], absolute: false);
        $media = EditorialWorkflowService::presentMedia($version);
        $assets = MediaAsset::whereIn('id', array_merge(array_column($media['items'], 'id'), $media['cover'] ? [$media['cover']['id']] : []))->get()->keyBy('id');

        return [
            'items' => array_values(array_filter(array_map(fn ($m) => isset($assets[$m['id']]) ? $assets[$m['id']]->present($sign) : null, $media['items']))),
            'cover' => $media['cover'] && isset($assets[$media['cover']['id']]) ? $assets[$media['cover']['id']]->present($sign) : null,
        ];
    }

    /** URLs de curta duração, nunca além da validade do link. */
    private function expiry(ContentReviewLink $link): \DateTimeInterface
    {
        $short = now()->addMinutes((int) config('content_review.media_url_minutes', 30));

        return $link->expires_at->lt($short) ? $link->expires_at : $short;
    }

    /** O ficheiro pertence a uma das versões enviadas neste lote? */
    public static function assetInLink(ContentReviewLink $link, int $assetId): bool
    {
        return \Illuminate\Support\Facades\DB::table('editorial_post_version_media')
            ->whereIn('version_id', ContentReviewLinkItem::where('content_review_link_id', $link->id)->pluck('version_id'))
            ->where('media_asset_id', $assetId)->exists();
    }
}
