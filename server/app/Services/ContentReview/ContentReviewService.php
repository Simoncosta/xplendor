<?php

declare(strict_types=1);

namespace App\Services\ContentReview;

use App\Mail\ContentReviewRequestMail;
use App\Models\Company;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkItem;
use App\Models\EditorialPost;
use App\Models\EditorialPostReview;
use App\Models\EditorialPostVersion;
use App\Models\User;
use App\Services\Editorial\EditorialWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Links de aprovação por lote, do lado de quem produz: escolher as publicações em
 * Aprovação, enviar (email ao destinatário, se indicado, e a mensagem para o WhatsApp),
 * reenviar no MESMO link com os itens atualizados, prolongar e revogar.
 */
class ContentReviewService
{
    public const MAX_ITEMS = 40;

    public function __construct(private readonly ContentReviewPresenter $presenter) {}

    /** Publicações que podem ir num lote: em Aprovação, com a versão enviada. */
    public function candidates(int $companyId): array
    {
        $posts = EditorialPost::where('company_id', $companyId)->where('stage', EditorialPost::STAGE_CLIENT_REVIEW)
            ->where('channel', '!=', 'site')->with('currentVersion')->orderBy('publish_date')->orderBy('id')->get()
            ->filter(fn (EditorialPost $p) => $p->currentVersion?->status === EditorialPostVersion::SENT);
        $inLinks = ContentReviewLinkItem::whereIn('editorial_post_id', $posts->pluck('id'))
            ->whereHas('link', fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now()))
            ->with('link:id,title')->get()->groupBy('editorial_post_id');

        return $posts->map(function (EditorialPost $p) use ($inLinks) {
            $media = EditorialWorkflowService::presentMedia($p->currentVersion);
            $first = $media['cover'] ?? ($media['items'][0] ?? null);

            return [
                'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel, 'publish_date' => $p->publish_date->toDateString(),
                'media_format' => $p->currentVersion?->media_format ?? $p->media_format, 'version_number' => $p->currentVersion?->number,
                'thumb_url' => $first['thumb_url'] ?? null,
                'in_links' => ($inLinks[$p->id] ?? collect())->map(fn ($i) => ['id' => $i->link->id, 'title' => $i->link->title])->unique('id')->values()->all(),
            ];
        })->values()->all();
    }

    public function create(Company $company, User $user, array $data): ContentReviewLink
    {
        EditorialWorkflowService::assertProducer($user, $company->id);
        $data = $this->validate($data);
        $posts = $this->eligiblePosts($company->id, $data['post_ids']);

        $link = DB::transaction(function () use ($company, $user, $data, $posts) {
            $link = ContentReviewLink::create(ContentReviewLink::newToken() + [
                'company_id' => $company->id, 'title' => $data['title'],
                'recipient_name' => $data['recipient_name'], 'recipient_email' => $data['recipient_email'],
                'sent_by_user_id' => $user->id, 'impersonator_user_id' => EditorialWorkflowService::impersonatorId($user),
                'last_sent_at' => now(), 'expires_at' => now()->addDays((int) config('content_review.validity_days', 14)),
            ]);
            foreach ($posts->values() as $i => $post) {
                ContentReviewLinkItem::create(['content_review_link_id' => $link->id, 'editorial_post_id' => $post->id, 'version_id' => $post->current_version_id, 'position' => $i]);
                app(EditorialWorkflowService::class)->event($post, $user, 'review_link', null, null, $post->current_version_id, "Enviada no link de aprovação \"{$link->title}\".");
            }

            return $link;
        });

        $this->mailClient($link);

        return $link->fresh();
    }

    /**
     * Reenviar no MESMO link: as publicações escolhidas passam para a versão atual; as
     * que já têm decisão ficam (para consulta); as outras, se não escolhidas, saem.
     */
    public function resend(ContentReviewLink $link, User $user, array $data): ContentReviewLink
    {
        EditorialWorkflowService::assertProducer($user, (int) $link->company_id);
        if ($link->revoked_at) {
            throw new HttpException(409, 'Este link foi revogado. Crie um novo.');
        }
        $data = $this->validate($data + ['title' => $link->title]);
        $posts = $this->eligiblePosts((int) $link->company_id, $data['post_ids']);

        DB::transaction(function () use ($link, $user, $data, $posts) {
            $link = ContentReviewLink::lockForUpdate()->findOrFail($link->id);
            $items = ContentReviewLinkItem::where('content_review_link_id', $link->id)->get()->keyBy('editorial_post_id');
            $decided = EditorialPostReview::whereIn('version_id', $items->pluck('version_id'))->pluck('version_id')->flip();
            foreach ($items as $postId => $item) {
                if (! $posts->has($postId) && ! isset($decided[$item->version_id])) {
                    $item->delete();
                }
            }
            $position = (int) ($items->max('position') ?? -1);
            foreach ($posts as $post) {
                $item = $items[$post->id] ?? null;
                if ($item && $item->exists) {
                    // Versão nova (ou a mesma, se nada mudou); a decisão antiga fica na versão antiga.
                    $item->update(['version_id' => $post->current_version_id, 'urgent_alert_sent_at' => null]);
                } else {
                    ContentReviewLinkItem::create(['content_review_link_id' => $link->id, 'editorial_post_id' => $post->id, 'version_id' => $post->current_version_id, 'position' => ++$position]);
                }
                app(EditorialWorkflowService::class)->event($post, $user, 'review_link', null, null, $post->current_version_id, "Reenviada no link de aprovação \"{$link->title}\".");
            }
            $minExpiry = now()->addDays((int) config('content_review.validity_days', 14));
            $link->fill([
                'title' => $data['title'], 'recipient_name' => $data['recipient_name'], 'recipient_email' => $data['recipient_email'],
                'last_sent_at' => now(), 'expires_at' => $link->expires_at->gt($minExpiry) ? $link->expires_at : $minExpiry,
            ])->forceFill(['client_reminder_sent_at' => null, 'team_reminder_sent_at' => null])->save();
        });

        $link = $link->fresh();
        $this->mailClient($link);

        return $link;
    }

    /** Mais 14 dias a contar de hoje. */
    public function extend(ContentReviewLink $link, User $user): ContentReviewLink
    {
        EditorialWorkflowService::assertProducer($user, (int) $link->company_id);
        if ($link->revoked_at) {
            throw new HttpException(409, 'Este link foi revogado. Crie um novo.');
        }
        $link->update(['expires_at' => now()->addDays((int) config('content_review.validity_days', 14))]);

        return $link->fresh();
    }

    public function revoke(ContentReviewLink $link, User $user): ContentReviewLink
    {
        EditorialWorkflowService::assertProducer($user, (int) $link->company_id);
        if (! $link->revoked_at) {
            $link->update(['revoked_at' => now()]);
        }

        return $link->fresh();
    }

    public function list(int $companyId, bool $canProduce): array
    {
        return ContentReviewLink::where('company_id', $companyId)->with('items.post')->orderByDesc('id')->limit(50)->get()
            ->map(fn (ContentReviewLink $l) => $this->present($l, $canProduce))->all();
    }

    /** Para a lista da equipa. O URL (com o token) só para quem produz. */
    public function present(ContentReviewLink $link, bool $withUrl = true): array
    {
        $link->loadMissing('items.post');
        $states = ContentReviewPresenter::itemStates($link->items);

        return [
            'id' => $link->id, 'title' => $link->title, 'state' => $link->state(),
            'recipient_name' => $link->recipient_name, 'recipient_email' => $link->recipient_email,
            'url' => $withUrl ? $link->url() : null,
            'share_message' => $withUrl ? self::shareMessage($link) : null,
            'created_at' => optional($link->created_at)->toIso8601String(),
            'last_sent_at' => optional($link->last_sent_at)->toIso8601String(),
            'expires_at' => $link->expires_at->toIso8601String(),
            'revoked_at' => optional($link->revoked_at)->toIso8601String(),
            'opens' => ['count' => (int) $link->open_count, 'first_at' => optional($link->first_opened_at)->toIso8601String(), 'last_at' => optional($link->last_opened_at)->toIso8601String()],
            'counts' => ContentReviewPresenter::counts($states),
            'items' => $link->items->map(fn (ContentReviewLinkItem $i) => [
                'id' => $i->id, 'post_id' => $i->editorial_post_id, 'title' => (string) $i->post?->title,
                'channel' => $i->post?->channel, 'publish_date' => $i->post?->publish_date?->toDateString(),
                'state' => $states[$i->id]['state'],
            ])->values()->all(),
        ];
    }

    /** Mensagem para partilhar (WhatsApp): o título, o pedido e o link. */
    public static function shareMessage(ContentReviewLink $link): string
    {
        $greeting = $link->recipient_name ? "Olá, {$link->recipient_name}." : 'Olá.';

        return "{$greeting} Tem publicações para aprovar ({$link->title}). Pode ver como ficam nas redes e aprovar ou pedir alterações aqui: {$link->url()}";
    }

    private function mailClient(ContentReviewLink $link): void
    {
        if (! $link->recipient_email) {
            return;
        }
        try {
            $link->loadMissing('items.post', 'company');
            $pending = ContentReviewPresenter::counts(ContentReviewPresenter::itemStates($link->items))[ContentReviewPresenter::ITEM_PENDING];
            Mail::to($link->recipient_email)->queue(new ContentReviewRequestMail(
                $link->recipient_name, ContentReviewPresenter::companyIdentity($link->company)['name'], $link->title,
                max(1, $pending), $link->url(), CarbonImmutable::parse($link->expires_at)->setTimezone('Europe/Lisbon')->format('d/m/Y'),
            ));
        } catch (\Throwable $e) {
            Log::error('[Aprovação de conteúdos] Falha ao enviar o email do link', ['link_id' => $link->id, 'error' => $e->getMessage()]);
        }
    }

    private function validate(array $data): array
    {
        $v = validator($data, [
            'title' => ['required', 'string', 'max:120'],
            'post_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'post_ids.*' => ['integer', 'distinct'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_email' => ['nullable', 'email', 'max:190'],
        ], [
            'title.required' => 'Dê um nome ao lote (por exemplo, "Novembro, semana 1").',
            'post_ids.required' => 'Escolha pelo menos uma publicação.',
            'post_ids.min' => 'Escolha pelo menos uma publicação.',
            'recipient_email.email' => 'Indique um email válido.',
        ])->validate();

        return [
            'title' => trim($data['title']), 'post_ids' => array_map('intval', $data['post_ids']),
            'recipient_name' => isset($data['recipient_name']) && trim((string) $data['recipient_name']) !== '' ? trim($data['recipient_name']) : null,
            'recipient_email' => isset($data['recipient_email']) && trim((string) $data['recipient_email']) !== '' ? mb_strtolower(trim($data['recipient_email'])) : null,
        ];
    }

    /** As publicações escolhidas, todas da empresa, em Aprovação e com a versão enviada (ordenadas por data). */
    private function eligiblePosts(int $companyId, array $ids): \Illuminate\Support\Collection
    {
        $posts = EditorialPost::where('company_id', $companyId)->whereIn('id', $ids)->with('currentVersion')->get();
        $bad = $posts->filter(fn (EditorialPost $p) => $p->channel === 'site' || $p->stage !== EditorialPost::STAGE_CLIENT_REVIEW
            || $p->currentVersion?->status !== EditorialPostVersion::SENT);
        if ($posts->count() !== count($ids) || $bad->isNotEmpty()) {
            throw ValidationException::withMessages(['post_ids' => [$bad->isNotEmpty()
                ? 'Só podem ir no lote publicações em Aprovação: "' . $bad->first()->title . '" não está.'
                : 'Há publicações que não pertencem a esta empresa.']]);
        }

        return $posts->sortBy(fn ($p) => $p->publish_date->toDateString() . sprintf('%010d', $p->id))->keyBy('id');
    }
}
