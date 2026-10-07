<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Models\SupportTicket;
use App\Models\SupportTicketTask;

/**
 * Marca sozinhas as tarefas dos tickets de arranque da empresa pela CHAVE da tarefa (nunca
 * pelo texto): por exemplo, social_access quando o cliente liga as redes pelo link. Com o
 * ticket de onde o link foi gerado, marca também as desse ticket, mesmo noutra empresa (por
 * exemplo, na XPLENDOR, num orçamento de prospeto).
 */
class OnboardingTaskMarker
{
    /** @return int tarefas marcadas */
    public function markDone(int $companyId, string $taskKey, ?int $ticketId = null): int
    {
        if (! array_key_exists($taskKey, SupportTicketTask::KEYS)) {
            return 0;
        }

        return SupportTicketTask::whereNull('done_at')->where('task_key', $taskKey)
            ->whereHas('ticket', fn ($q) => $q->where('type', SupportTicket::TYPE_ONBOARDING)
                ->where(fn ($w) => $w->where('company_id', $companyId)->when($ticketId, fn ($o) => $o->orWhere('id', $ticketId))))
            ->update(['done_at' => now(), 'updated_at' => now()]);
    }
}
