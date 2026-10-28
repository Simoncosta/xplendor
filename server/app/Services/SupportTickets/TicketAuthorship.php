<?php

declare(strict_types=1);

namespace App\Services\SupportTickets;

use App\Models\ImpersonationSession;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos de suporte: quem pode editar e apagar, e o que é de quem. Só o root, fora da sessão como
 * cliente, e só no que é dele. O que a cliente escreveu nunca se mexe.
 *
 * - Mensagem: da equipa (is_staff) escrita por ele, ou escrita por ele na sessão como cliente.
 * - Ticket: criado por ele (na conta dele ou na sessão como cliente); nos antigos, a auditoria
 *   "created" (que regista a pessoa real, também na sessão como cliente).
 * - Fatura: anexada por ele (invoice_by_user_id); nas antigas, a auditoria da gravação.
 */
class TicketAuthorship
{
    /** Só o root fora da sessão como cliente (as rotas do /admin já o exigem; aqui confirma-se). */
    public function assertTeam(User $actor): void
    {
        abort_unless($actor->isRoot() && ImpersonationSession::activeFor($actor) === null, 403, 'Só a equipa XPLENDOR pode fazer isto.');
    }

    public function ownsMessage(User $actor, SupportTicketMessage $message): bool
    {
        if ((int) $message->impersonator_user_id === (int) $actor->id) {
            return true;
        }

        return $message->is_staff && $message->impersonator_user_id === null && (int) $message->user_id === (int) $actor->id;
    }

    public function ownsTicket(User $actor, SupportTicket $ticket): bool
    {
        if ((int) $ticket->impersonator_user_id === (int) $actor->id || (int) $ticket->user_id === (int) $actor->id) {
            return true;
        }

        return $ticket->impersonator_user_id === null && DB::table('audits')
            ->where('auditable_type', SupportTicket::class)->where('auditable_id', $ticket->id)
            ->where('event', 'created')->where('user_id', $actor->id)->exists();
    }

    public function ownsInvoice(User $actor, SupportTicket $ticket): bool
    {
        if ($ticket->invoice_path === null) {
            return false;
        }
        if ($ticket->invoice_by_user_id !== null) {
            return (int) $ticket->invoice_by_user_id === (int) $actor->id;
        }
        // Faturas antigas: quem gravou o invoice_path atual, pela auditoria.
        $audits = DB::table('audits')->where('auditable_type', SupportTicket::class)->where('auditable_id', $ticket->id)
            ->orderByDesc('id')->get(['user_id', 'new_values']);
        foreach ($audits as $a) {
            $new = json_decode((string) $a->new_values, true) ?: [];
            if (($new['invoice_path'] ?? null) === $ticket->invoice_path) {
                return (int) $a->user_id === (int) $actor->id;
            }
        }

        return false;
    }

    /** O que este root pode fazer neste ticket (para o ecrã do /admin; o serviço volta a verificar). */
    public function abilities(User $actor, SupportTicket $ticket): array
    {
        $ownsTicket = $this->ownsTicket($actor, $ticket);

        return [
            'delete'            => $ownsTicket && $this->deletionBlocker($ticket) === null,
            'delete_blocked_by' => $ownsTicket ? $this->deletionBlocker($ticket) : null,
            'remove_screenshot' => $ownsTicket && $ticket->screenshot_path !== null,
            'change_invoice'    => $ticket->invoice_path === null || $this->ownsInvoice($actor, $ticket),
        ];
    }

    /** Por que razão o ticket não se pode apagar (null = pode). */
    public function deletionBlocker(SupportTicket $ticket): ?string
    {
        if (in_array($ticket->quote_status, SupportTicket::QUOTE_LOCKED_STATUSES, true) || $ticket->paid_at !== null || $ticket->invoice_path !== null) {
            return 'Este pedido tem um orçamento aprovado, pago ou concluído, por isso não se pode apagar.';
        }
        if ($ticket->messages()->where('is_staff', false)->exists()) {
            return 'Este pedido tem mensagens da cliente, por isso não se pode apagar.';
        }

        return null;
    }
}
