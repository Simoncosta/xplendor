<?php

declare(strict_types=1);

namespace App\Services\ContentReview;

use App\Models\Company;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewNotification;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Support\Facades\Log;

/**
 * Avisos dos links de aprovação para quem produz: no sino (logo) e no resumo por email
 * (a cada 15 minutos). Quem produz é a equipa XPLENDOR no modo "Produção pela equipa"
 * (sino da empresa da equipa, emails da equipa) e a própria empresa no modo "Produção
 * própria" (sino da empresa, email de quem enviou o link).
 */
class ContentReviewNotifier
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @param 'urgent'|'warning'|'opportunity' $type */
    public function notify(ContentReviewLink $link, string $type, string $title, string $message, string $severity = 'medium'): void
    {
        try {
            $company = Company::find($link->company_id);
            $team = $company && EditorialWorkflowService::productionMode($company->id) === EditorialWorkflowService::MODE_TEAM;
            $alertCompanyId = $team ? self::teamCompanyId() : $link->company_id;
            $name = $company ? (string) ($company->trade_name ?: $company->fiscal_name) : '';
            if ($alertCompanyId) {
                $this->alerts->createSystemAlert($alertCompanyId, $type, $team ? "{$name}: {$title}" : $title, $message, $severity,
                    $team ? null : "/editorial?aprovacoes={$link->id}");
            }
            ContentReviewNotification::create([
                'company_id' => $link->company_id, 'content_review_link_id' => $link->id, 'severity' => $severity,
                'title' => mb_substr($title, 0, 190), 'message' => mb_substr($message, 0, 1000), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Aprovação de conteúdos] Falha ao criar o aviso', ['link_id' => $link->id, 'error' => $e->getMessage()]);
        }
    }

    /** Emails que recebem o resumo de um aviso. */
    public static function recipients(int $companyId, ?ContentReviewLink $link): array
    {
        if (EditorialWorkflowService::productionMode($companyId) === EditorialWorkflowService::MODE_TEAM) {
            return self::teamEmails();
        }
        $sender = $link?->sent_by_user_id ? User::find($link->sent_by_user_id) : null;
        if ($sender && $sender->role !== 'root' && $sender->email) {
            return [$sender->email];
        }

        return User::where('company_id', $companyId)->where('role', 'admin')->whereNotNull('email')->pluck('email')->all();
    }

    public static function teamEmails(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('content_review.team_emails'))));

        return $configured ?: User::where('role', 'root')->whereNotNull('email')->pluck('email')->all();
    }

    /** Empresa da equipa XPLENDOR (a mesma regra dos avisos dos orçamentos e do disco). */
    public static function teamCompanyId(): ?int
    {
        $configured = (int) config('quotes.team_company_id');
        if ($configured > 0 && Company::whereKey($configured)->exists()) {
            return $configured;
        }
        $root = User::where('role', 'root')->whereNotNull('company_id')->orderBy('id')->value('company_id');

        return $root ? (int) $root : null;
    }
}
