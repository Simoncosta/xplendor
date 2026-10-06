<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ContentReviewRequestMail;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkOpen;
use App\Services\ContentReview\ContentReviewNotifier;
use App\Services\ContentReview\ContentReviewPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Lembretes dos links de aprovação, todos os dias às 09:00 (Lisboa), só nos links válidos
 * com publicações pendentes:
 *  · ao cliente (se houver email), pendente há 2 dias desde o envio (configurável), uma vez;
 *  · a quem produz, pendente há 4 dias, uma vez;
 *  · urgente a quem produz, por publicação, quando faltam menos de 48 horas para a data
 *    sem aprovação, uma vez (volta a poder avisar se a publicação for reenviada).
 * Também apaga as aberturas com mais de 12 meses (fica o contador do link).
 */
class ContentReviewRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(ContentReviewNotifier $notifier): array
    {
        $done = ['client' => 0, 'team' => 0, 'urgent' => 0, 'opens_pruned' => 0];
        $now = CarbonImmutable::now();
        $clientDays = (int) config('content_review.client_reminder_days', 2);
        $teamDays = (int) config('content_review.team_reminder_days', 4);
        $urgentHours = (int) config('content_review.urgent_hours', 48);

        ContentReviewLink::whereNull('revoked_at')->where('expires_at', '>', $now)->with(['items.post', 'company'])
            ->chunkById(100, function ($links) use (&$done, $now, $clientDays, $teamDays, $urgentHours, $notifier) {
                foreach ($links as $link) {
                    try {
                        $states = ContentReviewPresenter::itemStates($link->items);
                        $pending = $link->items->filter(fn ($i) => $states[$i->id]['state'] === ContentReviewPresenter::ITEM_PENDING);
                        if ($pending->isEmpty()) {
                            continue;
                        }
                        $sentAt = CarbonImmutable::parse($link->last_sent_at ?? $link->created_at);
                        $n = $pending->count();
                        $plural = $n === 1 ? 'publicação pendente' : 'publicações pendentes';

                        if ($link->recipient_email && ! $link->client_reminder_sent_at && $sentAt->lte($now->subDays($clientDays))) {
                            Mail::to($link->recipient_email)->queue(new ContentReviewRequestMail(
                                $link->recipient_name, ContentReviewPresenter::companyIdentity($link->company)['name'], $link->title, $n,
                                $link->url(), CarbonImmutable::parse($link->expires_at)->setTimezone('Europe/Lisbon')->format('d/m/Y'), true,
                            ));
                            $link->forceFill(['client_reminder_sent_at' => $now])->save();
                            $done['client']++;
                        }

                        if (! $link->team_reminder_sent_at && $sentAt->lte($now->subDays($teamDays))) {
                            $notifier->notify($link, 'warning', "Aprovação pendente há {$teamDays} dias: {$link->title}",
                                "{$n} {$plural} desde " . $sentAt->setTimezone('Europe/Lisbon')->format('d/m') . '. Vale a pena contactar o cliente.');
                            $link->forceFill(['team_reminder_sent_at' => $now])->save();
                            $done['team']++;
                        }

                        foreach ($pending as $item) {
                            $date = $item->post->publish_date;
                            if ($item->urgent_alert_sent_at || ! $date) {
                                continue;
                            }
                            $publishAt = CarbonImmutable::parse($date->toDateString() . ' 00:00:00', 'Europe/Lisbon');
                            if ($publishAt->lt($now->addHours($urgentHours))) {
                                $notifier->notify($link, 'urgent', "Sem aprovação e a menos de 48 horas: {$item->post->title}",
                                    'Data de publicação ' . $publishAt->format('d/m/Y') . ". Continua à espera do cliente no link \"{$link->title}\".", 'high');
                                $item->forceFill(['urgent_alert_sent_at' => $now])->save();
                                $done['urgent']++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::error('[Aprovação de conteúdos] Falha nos lembretes', ['link_id' => $link->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        $done['opens_pruned'] = ContentReviewLinkOpen::where('opened_at', '<', $now->subMonths((int) config('content_review.opens_retention_months', 12)))->delete();

        return $done;
    }
}
