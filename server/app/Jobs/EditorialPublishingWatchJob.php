<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EditorialPost;
use App\Services\ContentReview\ContentReviewNotifier;
use App\Services\Editorial\EditorialPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A cada 15 minutos: as publicações Programadas cuja data e hora passaram sem serem
 * marcadas como publicadas geram UM aviso a quem produz (a equipa, ou a empresa no modo
 * "Produção própria"); as publicadas há 7 dias passam a Análise.
 */
class EditorialPublishingWatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(EditorialPublishingService $publishing, ContentReviewNotifier $notifier): array
    {
        $alerted = 0;
        $today = now(EditorialPost::TIMEZONE)->toDateString();
        EditorialPost::where('stage', EditorialPost::STAGE_SCHEDULED)->where('channel', '!=', 'site')
            ->whereNull('overdue_alerted_at')->whereDate('publish_date', '<=', $today)
            ->orderBy('id')->chunkById(200, function ($posts) use (&$alerted, $notifier) {
                foreach ($posts as $post) {
                    if (! $post->isOverdue()) {
                        continue;
                    }
                    // Marca primeiro: o aviso é uma só vez, mesmo que corram dois ao mesmo tempo.
                    if (! EditorialPost::whereKey($post->id)->whereNull('overdue_alerted_at')->update(['overdue_alerted_at' => now()])) {
                        continue;
                    }
                    $when = $post->dueAt()->setTimezone(EditorialPost::TIMEZONE)->format('d/m/Y' . ($post->publish_time ? ' H:i' : ''));
                    $notifier->notifyCompany((int) $post->company_id, 'urgent', "Publicação atrasada: {$post->title}",
                        "Estava programada para {$when} e ainda não foi marcada como publicada.", 'high',
                        '/editorial?vista=kanban&mes=' . $post->publish_date->format('Y-m'));
                    $alerted++;
                }
            });

        return ['overdue_alerts' => $alerted, 'to_analysis' => $publishing->autoAnalysis()];
    }
}
