<?php

declare(strict_types=1);

namespace App\Services\ContentReview;

use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkItem;
use App\Models\ContentReviewLinkOpen;
use App\Models\EditorialPostReview;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\PublicLinks\PublicLinkOpens;
use App\Support\BotUserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Página do link de aprovação (sem conta): mostrar o lote, registar aberturas (serviço
 * partilhado com o orçamento) e as decisões do cliente: aprovar, pedir alterações
 * (mensagem obrigatória), comentar e "Aprovar tudo" (numa só transação). Uma decisão por
 * versão; nada se decide com o link expirado ou revogado, nem num item atualizado pela
 * equipa depois do envio.
 */
class ContentReviewPublicService
{
    public function __construct(
        private readonly ContentReviewPresenter $presenter,
        private readonly EditorialWorkflowService $workflow,
        private readonly PublicLinkOpens $opens,
        private readonly ContentReviewNotifier $notifier,
    ) {}

    /** O link do token, ou 404 (mal formado e inexistente não se distinguem). */
    public function resolve(string $token): ContentReviewLink
    {
        $link = ContentReviewLink::findByToken($token);
        if (! $link) {
            throw new HttpException(404, 'Link não encontrado.');
        }

        return $link;
    }

    public function payload(ContentReviewLink $link): array
    {
        return $this->presenter->payload($link->fresh());
    }

    public function recordOpen(ContentReviewLink $link, Request $request): array
    {
        $open = $this->opens->record($request, 'review|' . $link->id, $link, ContentReviewLinkOpen::class,
            ['content_review_link_id' => $link->id], [], (int) config('content_review.open_alert_every_hours', 6));

        if ($open['alert']) {
            $device = $open['device'] === 'mobile' ? 'num telemóvel' : 'num computador';
            $who = $link->recipient_name ?: 'O cliente';
            $this->notifier->notify($link, 'opportunity',
                $open['first'] ? "Link de aprovação aberto: {$link->title}" : "Link de aprovação aberto de novo: {$link->title}",
                "{$who} abriu o link {$device}. Aberto {$open['open_count']} " . ($open['open_count'] === 1 ? 'vez' : 'vezes') . '.', 'low');
        }

        return ['counted' => $open['counted'], 'reason' => $open['reason']];
    }

    public function approve(ContentReviewLink $link, int $itemId, Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => 'Indique o seu nome.']);
        $item = $this->item($link, $itemId);
        DB::transaction(function () use ($link, $item, $data, $request) {
            $this->lockOpen($link);
            $this->workflow->decideViaLink($item->post, (int) $item->version_id, $link->id, EditorialPostReview::APPROVED, $data['name'], null, BotUserAgent::device($request->userAgent()));
        });
        $this->notifier->notify($link, 'opportunity', "Publicação aprovada: {$item->post->title}", trim($data['name']) . " aprovou no link \"{$link->title}\".", 'low');

        return $this->payload($link);
    }

    public function requestChanges(ContentReviewLink $link, int $itemId, Request $request): array
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'min:3', 'max:3000']],
            ['name.required' => 'Indique o seu nome.', 'message.required' => 'Escreva o que deve ser alterado.', 'message.min' => 'Escreva o que deve ser alterado.']
        );
        $item = $this->item($link, $itemId);
        DB::transaction(function () use ($link, $item, $data, $request) {
            $this->lockOpen($link);
            $this->workflow->decideViaLink($item->post, (int) $item->version_id, $link->id, EditorialPostReview::CHANGES_REQUESTED, $data['name'], $data['message'], BotUserAgent::device($request->userAgent()));
        });
        $this->notifier->notify($link, 'warning', "Alterações pedidas: {$item->post->title}",
            trim($data['name']) . ' pediu alterações: ' . mb_substr(trim($data['message']), 0, 400), 'high');

        return $this->payload($link);
    }

    public function comment(ContentReviewLink $link, int $itemId, Request $request): array
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:3000']],
            ['name.required' => 'Indique o seu nome.', 'body.required' => 'Escreva o comentário.']
        );
        $item = $this->item($link, $itemId);
        DB::transaction(function () use ($link, $item, $data) {
            $this->lockOpen($link);
            $this->workflow->commentViaLink($item->post, (int) $item->version_id, $link->id, $data['name'], $data['body']);
        });
        $this->notifier->notify($link, 'warning', "Comentário: {$item->post->title}", trim($data['name']) . ': ' . mb_substr(trim($data['body']), 0, 400));

        return $this->payload($link);
    }

    /** "Aprovar tudo": as pendentes, todas ou nenhuma (uma transação). */
    public function approveAll(ContentReviewLink $link, Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => 'Indique o seu nome.']);
        $device = BotUserAgent::device($request->userAgent());
        $titles = DB::transaction(function () use ($link, $data, $device) {
            $this->lockOpen($link);
            $items = ContentReviewLinkItem::where('content_review_link_id', $link->id)->with('post')->orderBy('position')->get();
            $states = ContentReviewPresenter::itemStates($items);
            $pending = $items->filter(fn ($i) => $states[$i->id]['state'] === ContentReviewPresenter::ITEM_PENDING);
            if ($pending->isEmpty()) {
                throw new HttpException(409, 'Não há publicações pendentes neste link.');
            }
            foreach ($pending as $item) {
                $this->workflow->decideViaLink($item->post, (int) $item->version_id, $link->id, EditorialPostReview::APPROVED, $data['name'], null, $device);
            }

            return $pending->map(fn ($i) => $i->post->title)->values()->all();
        });
        $n = count($titles);
        $this->notifier->notify($link, 'opportunity', "Lote aprovado: {$link->title}",
            trim($data['name']) . " aprovou {$n} " . ($n === 1 ? 'publicação' : 'publicações') . ': ' . mb_substr(implode('; ', $titles), 0, 500) . '.', 'low');

        return $this->payload($link);
    }

    private function item(ContentReviewLink $link, int $itemId): ContentReviewLinkItem
    {
        $item = ContentReviewLinkItem::where('content_review_link_id', $link->id)->with('post')->find($itemId);
        if (! $item || ! $item->post) {
            throw new HttpException(404, 'Publicação não encontrada neste link.');
        }

        return $item;
    }

    /** Bloqueia o link e confirma que está válido (senão, 409 com a mensagem do estado). */
    private function lockOpen(ContentReviewLink $link): void
    {
        $fresh = ContentReviewLink::lockForUpdate()->findOrFail($link->id);
        if (! $fresh->isOpen()) {
            throw new HttpException(409, $fresh->state() === ContentReviewLink::STATE_REVOKED
                ? 'Este link foi desativado pela equipa.' : 'Este link expirou. Peça um novo link à equipa.');
        }
    }
}
