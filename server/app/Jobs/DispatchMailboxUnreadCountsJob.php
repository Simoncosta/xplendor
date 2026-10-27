<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail 3 · De 5 em 5 minutos (routes/console.php, dentro do SCHEDULE_EXTERNAL_JOBS): um
 * CountMailboxUnreadJob por caixa já testada no envio. As caixas com o IMAP em erro tentam de novo
 * só de hora a hora, para não insistir com um servidor que recusa.
 */
class DispatchMailboxUnreadCountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        Mailbox::query()
            ->where('status', Mailbox::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('imap_status')->orWhere('imap_status', '!=', Mailbox::STATUS_ERROR)
                ->orWhere('updated_at', '<', now()->subHour()))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => CountMailboxUnreadJob::dispatch($id));
    }
}
