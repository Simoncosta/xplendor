<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EditorialPost;
use App\Services\ContentReview\ContentReviewNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Às 08:30 (Lisboa): por empresa, a lista das publicações Programadas para hoje, para
 * quem produz (a equipa; a própria empresa no modo "Produção própria").
 */
class EditorialTodayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(ContentReviewNotifier $notifier): array
    {
        $today = now(EditorialPost::TIMEZONE);
        $posts = EditorialPost::where('stage', EditorialPost::STAGE_SCHEDULED)->where('channel', '!=', 'site')
            ->whereDate('publish_date', $today->toDateString())
            ->get()->sortBy(fn (EditorialPost $p) => \App\Services\Editorial\EditorialPublishingService::sortKey($p))->groupBy('company_id');

        foreach ($posts as $companyId => $list) {
            $n = $list->count();
            $lines = $list->map(fn (EditorialPost $p) => ($p->publish_time ? "{$p->publish_time} " : '') . "{$p->title} (" . ucfirst($p->channel) . ')')->implode('; ');
            $notifier->notifyCompany((int) $companyId, 'warning', "Para publicar hoje: {$n} " . ($n === 1 ? 'publicação' : 'publicações'),
                mb_substr($lines, 0, 900) . '.', 'medium', '/editorial?vista=kanban&mes=' . $today->format('Y-m'));
        }

        return ['companies' => $posts->count(), 'posts' => $posts->flatten()->count()];
    }
}
