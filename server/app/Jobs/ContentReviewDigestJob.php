<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ContentReviewDigestMail;
use App\Models\Company;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewNotification;
use App\Services\ContentReview\ContentReviewNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Resumo por email dos avisos dos links de aprovação, a cada 15 minutos e só quando há
 * novidades: um email por destinatário com tudo o que aconteceu desde o último resumo.
 * Os avisos já estão no sino; o email é para não ter de o vigiar.
 */
class ContentReviewDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): array
    {
        $pending = ContentReviewNotification::whereNull('emailed_at')->orderBy('id')->limit(500)->get();
        if ($pending->isEmpty()) {
            return ['emails' => 0, 'notifications' => 0];
        }
        $companies = Company::whereIn('id', $pending->pluck('company_id')->unique())->get()->keyBy('id');
        $links = ContentReviewLink::whereIn('id', $pending->pluck('content_review_link_id')->filter()->unique())->get()->keyBy('id');

        $byEmail = [];
        foreach ($pending as $n) {
            $company = $companies[$n->company_id] ?? null;
            $line = [
                'company' => $company ? (string) ($company->trade_name ?: $company->fiscal_name) : '',
                'title' => $n->title, 'message' => $n->message, 'severity' => $n->severity,
            ];
            foreach (ContentReviewNotifier::recipients((int) $n->company_id, $links[$n->content_review_link_id] ?? null) as $email) {
                $byEmail[mb_strtolower($email)][] = $line;
            }
        }

        $sent = 0;
        foreach ($byEmail as $email => $lines) {
            try {
                Mail::to($email)->send(new ContentReviewDigestMail($lines));
                $sent++;
            } catch (\Throwable $e) {
                Log::error('[Aprovação de conteúdos] Falha no resumo por email', ['error' => $e->getMessage()]);
            }
        }
        ContentReviewNotification::whereIn('id', $pending->pluck('id'))->update(['emailed_at' => now()]);

        return ['emails' => $sent, 'notifications' => $pending->count()];
    }
}
