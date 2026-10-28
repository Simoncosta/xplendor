<?php

declare(strict_types=1);

namespace App\Services\SupportTickets;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageVersion;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos de suporte: o root edita e apaga o que é dele (TicketAuthorship). A mensagem apagada
 * continua na thread com a marca; o texto anterior de uma edição fica guardado, só para o root.
 * Nunca se reenviam e-mails: as mensagens da equipa não enviam e-mail à cliente.
 */
class TicketEdits
{
    public function __construct(
        private readonly TicketAuthorship $authorship,
        private readonly SupportTicketService $tickets,
    ) {}

    public function editMessage(SupportTicket $ticket, SupportTicketMessage $message, User $actor, string $body): SupportTicketMessage
    {
        $this->assertOwnMessage($ticket, $message, $actor);
        if ($message->isDeleted()) {
            throw ValidationException::withMessages(['body' => ['Esta mensagem foi apagada e já não se pode editar.']]);
        }
        if ($body === $message->body) {
            return $message;
        }
        DB::transaction(function () use ($message, $actor, $body) {
            SupportTicketMessageVersion::create(['support_ticket_message_id' => $message->id, 'body' => $message->body, 'edited_by_user_id' => $actor->id]);
            $message->update(['body' => $body, 'edited_at' => now()]);
        });

        return $message->fresh();
    }

    public function deleteMessage(SupportTicket $ticket, SupportTicketMessage $message, User $actor): void
    {
        $this->assertOwnMessage($ticket, $message, $actor);
        if (! $message->isDeleted()) {
            $message->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id]);
        }
    }

    /** Retira o anexo (disco público: o ficheiro apaga-se já) e avisa na thread. */
    public function removeScreenshot(SupportTicket $ticket, User $actor): void
    {
        $this->authorship->assertTeam($actor);
        abort_unless($this->authorship->ownsTicket($actor, $ticket), 403, 'Só pode retirar o anexo de um pedido que criou.');
        if ($ticket->screenshot_path === null) {
            return;
        }
        $this->deletePublicFile($ticket->screenshot_path);
        $ticket->update(['screenshot_path' => null]);
        $this->tickets->addMessage($ticket, $actor->id, 'A equipa XPLENDOR retirou o anexo deste pedido.', true);
    }

    /** Apaga (soft delete) um ticket criado por engano: sem mensagens da cliente e sem trabalho aprovado. */
    public function deleteTicket(SupportTicket $ticket, User $actor): void
    {
        $this->authorship->assertTeam($actor);
        abort_unless($this->authorship->ownsTicket($actor, $ticket), 403, 'Só pode apagar um pedido que criou.');
        if (($reason = $this->authorship->deletionBlocker($ticket)) !== null) {
            throw ValidationException::withMessages(['ticket' => [$reason]]);
        }
        if ($ticket->screenshot_path !== null) {
            $this->deletePublicFile($ticket->screenshot_path); // o endereço público deixava-o aberto
            $ticket->screenshot_path = null;
        }
        $ticket->deleted_by_user_id = $actor->id;
        $ticket->save();
        $ticket->delete();
    }

    private function assertOwnMessage(SupportTicket $ticket, SupportTicketMessage $message, User $actor): void
    {
        $this->authorship->assertTeam($actor);
        abort_unless((int) $message->support_ticket_id === (int) $ticket->id, 404);
        abort_unless($this->authorship->ownsMessage($actor, $message), 403, 'Só pode editar ou apagar as mensagens que escreveu.');
    }

    private function deletePublicFile(string $publicPath): void
    {
        $path = ltrim(str_starts_with($publicPath, '/storage/') ? substr($publicPath, strlen('/storage/')) : $publicPath, '/');
        Storage::disk('public')->delete($path);
    }
}
