<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Models\Company;
use App\Models\CompanyConnectionEvent;
use App\Models\CompanySetupLink;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\CollaboratorService;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * O lado da equipa do link de configuração: gerar (revoga o anterior: um link ativo por
 * empresa), renovar (mais 14 dias, o mesmo token), revogar e ver o estado de cada passo.
 * Quem pode: o admin da própria empresa, o root e o admin da agência gestora, nunca em
 * impersonation (as mesmas regras de quem liga integrações).
 */
class SetupLinkService
{
    public function assertCanManage(?User $actor, int $companyId): void
    {
        if (! $actor || ! CollaboratorService::canConfigureIntegrations($actor, $companyId)) {
            throw new HttpException(403, 'Só o administrador da empresa, um administrador da agência gestora ou a equipa XPLENDOR pode gerir o link de configuração.');
        }
    }

    /** O link mais recente da empresa (aberto, expirado ou revogado), ou null. */
    public function latest(int $companyId): ?CompanySetupLink
    {
        return CompanySetupLink::where('company_id', $companyId)->orderByDesc('id')->first();
    }

    /**
     * @param string[] $steps
     * @param int|null $ticketId o ticket de arranque de onde o link foi gerado (as tarefas dele
     *   marcam-se pela chave, mesmo noutra empresa): só a equipa XPLENDOR, ou um ticket da própria empresa.
     */
    public function create(int $companyId, User $actor, array $steps, ?int $ticketId = null): CompanySetupLink
    {
        $this->assertCanManage($actor, $companyId);
        if ($ticketId !== null) {
            $ticket = SupportTicket::find($ticketId);
            if (! $ticket || $ticket->type !== SupportTicket::TYPE_ONBOARDING || ($actor->role !== 'root' && (int) $ticket->company_id !== $companyId)) {
                throw new HttpException(422, 'Ticket de arranque inválido.');
            }
        }
        $steps = array_values(array_intersect(array_keys(CompanySetupLink::STEPS), $steps));
        if ($steps === []) {
            throw new HttpException(422, 'Escolha pelo menos um passo.');
        }

        return DB::transaction(function () use ($companyId, $actor, $steps, $ticketId) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail(); // um link ativo por empresa
            CompanySetupLink::where('company_id', $companyId)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => CompanySetupLink::REVOKED_REPLACED, 'revoked_by_user_id' => $actor->id, 'updated_at' => now()]);

            $token = CompanySetupLink::newToken();

            return CompanySetupLink::create([
                'company_id' => $companyId,
                'created_by_user_id' => $actor->id,
                'company_management_id' => app(CompanyAccess::class)->management($companyId)?->id,
                'support_ticket_id' => $ticketId,
                'token_hash' => $token['token_hash'],
                'token_encrypted' => $token['token_encrypted'],
                'steps' => array_fill_keys($steps, CompanySetupLink::blankStep()),
                'expires_at' => now()->addDays(CompanySetupLink::VALIDITY_DAYS),
            ]);
        });
    }

    /** Renova o mesmo link (o mesmo endereço) por mais 14 dias a contar de agora. */
    public function extend(int $companyId, int $linkId, User $actor): CompanySetupLink
    {
        $this->assertCanManage($actor, $companyId);
        $link = $this->find($companyId, $linkId);
        if ($link->revoked_at) {
            throw new HttpException(409, 'Este link foi revogado. Gere um link novo.');
        }
        $link->update(['expires_at' => now()->addDays(CompanySetupLink::VALIDITY_DAYS)]);

        return $link->fresh();
    }

    public function revoke(int $companyId, int $linkId, User $actor): CompanySetupLink
    {
        $this->assertCanManage($actor, $companyId);
        $link = $this->find($companyId, $linkId);
        if (! $link->revoked_at) {
            $link->update(['revoked_at' => now(), 'revoked_reason' => CompanySetupLink::REVOKED_MANUAL, 'revoked_by_user_id' => $actor->id]);
        }

        return $link->fresh();
    }

    public function find(int $companyId, int $linkId): CompanySetupLink
    {
        $link = CompanySetupLink::where('company_id', $companyId)->find($linkId);
        if (! $link) {
            throw new HttpException(404, 'Link não encontrado.');
        }

        return $link;
    }

    /** Para a equipa: o endereço (para copiar de novo), a validade, as aberturas e cada passo. */
    public function present(?CompanySetupLink $link): ?array
    {
        if (! $link) {
            return null;
        }
        $state = $link->state();

        return [
            'id' => $link->id,
            'state' => $state,
            'url' => $state === CompanySetupLink::STATE_REVOKED ? null : $link->url(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'revoked_reason' => $link->revoked_reason,
            'completed_at' => $link->completed_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'created_by' => $link->creator?->name,
            'open_count' => (int) $link->open_count,
            'support_ticket_id' => $link->support_ticket_id,
            'last_opened_at' => $link->last_opened_at?->toIso8601String(),
            'steps' => array_map(fn ($k) => self::presentStep($link, $k), $link->stepKeys()),
        ];
    }

    public static function presentStep(CompanySetupLink $link, string $key): array
    {
        $s = $link->step($key);

        return [
            'key' => $key,
            'label' => CompanySetupLink::STEPS[$key],
            'status' => $s['status'] ?? CompanySetupLink::PENDING,
            'done_at' => $s['done_at'] ?? null,
            'error' => $s['error'] ?? null,
            'detail' => $s['detail'] ?? null,
        ];
    }

    /** Histórico das ligações da empresa (o mais recente primeiro). */
    public function history(int $companyId): array
    {
        return CompanyConnectionEvent::with('user:id,name')->where('company_id', $companyId)->orderByDesc('id')->limit(100)->get()
            ->map(fn (CompanyConnectionEvent $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'action' => $e->action, 'detail' => $e->detail,
                'origin' => $e->setup_link_id ? 'setup_link' : ($e->user_id ? 'user' : 'system'),
                'setup_link_id' => $e->setup_link_id, 'user' => $e->user?->name,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all();
    }
}
