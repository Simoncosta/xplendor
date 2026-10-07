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
 * Avisos da Linha Editorial para quem produz (links de aprovação, F3c; publicação e
 * análise, F3d): no sino (logo) e no resumo por email
 * (a cada 15 minutos). No modo "Produção pela equipa", quem produz é a AGÊNCIA gestora
 * (sino da agência, emails das pessoas atribuídas ao cliente e do email de avisos da agência)
 * ou, numa empresa sem agência, a equipa XPLENDOR (sino da empresa da equipa e
 * CONTENT_REVIEW_TEAM_EMAILS, ou os roots). No modo "Produção própria", a própria empresa
 * (sino da empresa, email de quem enviou o link).
 */
class ContentReviewNotifier
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @param 'urgent'|'warning'|'opportunity' $type */
    public function notify(ContentReviewLink $link, string $type, string $title, string $message, string $severity = 'medium'): void
    {
        $this->notifyCompany((int) $link->company_id, $type, $title, $message, $severity, "/editorial?aprovacoes={$link->id}", $link->id, "aprovacoes={$link->id}");
    }

    /**
     * Aviso sobre uma empresa para quem produz (também usado pela publicação e análise,
     * F3d): sino logo e resumo por email a cada 15 minutos. $path só vale no modo
     * "Produção própria" (no da equipa, o sino é o da empresa da equipa).
     *
     * @param 'urgent'|'warning'|'opportunity' $type
     */
    public function notifyCompany(int $companyId, string $type, string $title, string $message, string $severity = 'medium', ?string $path = null, ?int $linkId = null, ?string $agencyQuery = null): void
    {
        try {
            $company = Company::find($companyId);
            $team = $company && EditorialWorkflowService::productionMode($company->id) === EditorialWorkflowService::MODE_TEAM;
            $management = $team ? $company->activeManagement()->first() : null;
            $name = $company ? (string) ($company->trade_name ?: $company->fiscal_name) : '';
            if ($agencyQuery === null && $path !== null && str_starts_with($path, '/editorial?')) {
                $agencyQuery = substr($path, strlen('/editorial?'));
            }
            if ($management) {
                // Empresa gerida: o aviso vai para a agência, com a ligação que abre o cliente na vista da agência.
                $this->alerts->createSystemAlert((int) $management->agency_company_id, $type, "{$name}: {$title}", $message, $severity,
                    "/editorial?cliente={$companyId}" . ($agencyQuery ? "&{$agencyQuery}" : ''));
            } elseif ($alertCompanyId = $team ? self::teamCompanyId() : $companyId) {
                $this->alerts->createSystemAlert($alertCompanyId, $type, $team ? "{$name}: {$title}" : $title, $message, $severity, $team ? null : $path);
            }
            ContentReviewNotification::create([
                'company_id' => $companyId, 'content_review_link_id' => $linkId, 'severity' => $severity,
                'title' => mb_substr($title, 0, 190), 'message' => mb_substr($message, 0, 1000), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Linha Editorial] Falha ao criar o aviso', ['company_id' => $companyId, 'link_id' => $linkId, 'error' => $e->getMessage()]);
        }
    }

    /** Emails que recebem o resumo de um aviso. */
    public static function recipients(int $companyId, ?ContentReviewLink $link): array
    {
        if (EditorialWorkflowService::productionMode($companyId) === EditorialWorkflowService::MODE_TEAM) {
            $management = \App\Models\CompanyManagement::active()->where('managed_company_id', $companyId)->first();

            // Gerida: a agência dela. Sem agência: a equipa XPLENDOR (CONTENT_REVIEW_TEAM_EMAILS ou os roots).
            return $management ? \App\Services\Agency\AgencyNotifier::recipientsFor($management) : self::teamEmails();
        }
        $sender = $link?->sent_by_user_id ? User::find($link->sent_by_user_id) : null;
        if ($sender && $sender->role !== 'root' && $sender->email) {
            return [$sender->email];
        }

        return User::where('company_id', $companyId)->where('role', 'admin')->whereNotNull('email')->pluck('email')->all();
    }

    /** Recurso só para as empresas SEM agência no modo "Produção pela equipa". */
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
