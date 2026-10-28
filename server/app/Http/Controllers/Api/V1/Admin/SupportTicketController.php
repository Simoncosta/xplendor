<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\TicketTypeChangeNeedsConfirmation;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\ImpersonationSession;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\SupportTicketService;
use App\Services\SupportTickets\TicketEdits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * DMS — Tickets de suporte, LADO ADMIN (super-admin / root). TRANSVERSAL: vê
 * tickets de TODAS as empresas. Vive no grupo /api/v1/admin, atrás do
 * EnsureSuperAdmin — o ÚNICO sítio com acesso "ver todos".
 *
 * O admin-repo NÃO filtra por company (explícito, não global scope — convenção
 * do spike). Os endpoints de stand (/companies/{id}/support-tickets) ficam
 * intactos e continuam scoped.
 *
 * Defesa em profundidade: reconfirma role 'root' mesmo atrás do middleware.
 */
class SupportTicketController extends Controller
{
    /** Tudo o que o ecrã do root mostra num pedido (autores, edições, fatura e pagamento). */
    private const RELATIONS = ['company', 'user', 'impersonator', 'invoiceBy', 'paidBy', 'deletedBy', 'messages.user', 'messages.impersonator',
        'messages.versions.editedBy', 'messages.deletedBy', 'typeChanges.changedBy', 'tasks.doneBy'];

    public function __construct(protected SupportTicketService $service, protected TicketEdits $edits) {}

    private function respond(SupportTicket $ticket, string $message)
    {
        $ticket = SupportTicket::withTrashed()->with(self::RELATIONS)->find($ticket->id);

        return ApiResponse::success((new SupportTicketResource($ticket))->resolve(), $message);
    }

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->isRoot(), 403);
    }

    /** Listagem TRANSVERSAL (todas as empresas) + filtros + ordenação por-tratar. */
    public function index(Request $request)
    {
        $this->ensureRoot();

        $query = SupportTicket::with(['company', 'user'])->withCount('messages');
        // Filtro "Apagados": só os tickets apagados (soft delete); sem ele, nunca aparecem.
        if ($request->boolean('deleted')) {
            $query->onlyTrashed()->with('deletedBy');
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', (int) $companyId);
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        // Por-tratar primeiro (open, in_review), depois resolved/closed; recentes no topo.
        $query->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_review' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByDesc('id');

        return ApiResponse::success(
            SupportTicketResource::collection($query->get())->resolve(),
            'Admin tickets fetched successfully.'
        );
    }

    /** Contagens para os cartões do topo (embrião do dashboard admin). */
    public function summary()
    {
        $this->ensureRoot();

        $byStatus = SupportTicket::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $open = (int) ($byStatus['open'] ?? 0);
        $inReview = (int) ($byStatus['in_review'] ?? 0);

        return ApiResponse::success([
            'open'      => $open,
            'in_review' => $inReview,
            'pending'   => $open + $inReview,   // "por tratar"
            'resolved'  => (int) ($byStatus['resolved'] ?? 0),
            'closed'    => (int) ($byStatus['closed'] ?? 0),
            'total'     => (int) $byStatus->sum(),
        ], 'Admin ticket summary fetched successfully.');
    }

    /**
     * ADMIN — PIPELINE de orçamentos-em-tickets (site_change): "quanto tenho em cima
     * da mesa" por quote_status (contagem + valor + horas). Filtro company_id opcional
     * (ver por cliente). Reaproveita o SupportTicketService::quotePipeline.
     */
    public function quotePipeline(Request $request)
    {
        $this->ensureRoot();
        $companyId = $request->input('company_id');

        return ApiResponse::success(
            $this->service->quotePipeline($companyId ? (int) $companyId : null),
            'Ticket quote pipeline fetched successfully.'
        );
    }

    public function show(int $ticketId)
    {
        $this->ensureRoot();

        // O root também abre os apagados (só leitura; o filtro "Apagados" leva-o até eles).
        $ticket = SupportTicket::withTrashed()->with(self::RELATIONS)->find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Admin ticket fetched successfully.'
        );
    }

    // PATCH /admin/tickets/{ticket}/tasks/{task}   { done: bool }  (equipa XPLENDOR)
    public function updateTask(Request $request, int $ticketId, int $taskId)
    {
        $this->ensureRoot();
        $data = $request->validate(['done' => ['required', 'boolean']]);
        $task = \App\Models\SupportTicketTask::where('support_ticket_id', $ticketId)->find($taskId);
        if (! $task) {
            return ApiResponse::error('Tarefa não encontrada.', 404);
        }
        $task->update($data['done']
            ? ['done_at' => $task->done_at ?? now(), 'done_by_user_id' => $task->done_by_user_id ?? $request->user()->id]
            : ['done_at' => null, 'done_by_user_id' => null]);
        $ticket = SupportTicket::with(['company', 'user', 'messages.user', 'typeChanges.changedBy', 'tasks.doneBy'])->find($ticketId);

        return ApiResponse::success((new SupportTicketResource($ticket))->resolve(), $data['done'] ? 'Tarefa concluída.' : 'Tarefa reaberta.');
    }

    public function updateStatus(Request $request, int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(SupportTicket::STATUSES)],
        ]);

        $ticket = $this->service->updateStatus($ticket, $data['status']);
        return $this->respond($ticket, 'Ticket status updated successfully.');
    }

    /**
     * EQUIPA XPLENDOR muda o TIPO. Só root fora de impersonation (em impersonation o
     * token é do cliente; a rota também tem block_when_impersonating).
     * Body: { type, confirm_reset?: bool }. Orçado ou rejeitado sem confirm_reset → 409
     * com o orçamento a anular; aprovado, pago ou concluído → 422 com a razão.
     */
    public function reclassify(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        abort_if(ImpersonationSession::activeFor($request->user()), 403, 'Indisponível durante a sessão como cliente.');

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'type'          => ['required', Rule::in(SupportTicket::TYPES)],
            'confirm_reset' => ['sometimes', 'boolean'],
        ]);

        try {
            $ticket = $this->service->reclassifyType($ticket, $data['type'], $request->user(), (bool) ($data['confirm_reset'] ?? false));
        } catch (TicketTypeChangeNeedsConfirmation $e) {
            return ApiResponse::error($e->getMessage(), 409, $e->payload());
        }
        return $this->respond($ticket, 'Tipo do ticket alterado.');
    }

    public function storeMessage(Request $request, int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // is_staff=true → resposta do super-admin (não dispara email ao Simon).
        $this->service->addMessage($ticket, (int) Auth::id(), $data['body'], true);
        return $this->respond($ticket, 'Message added successfully.');
    }

    // ── Camada de orçamento (só site_change, só o super-admin) ───────────────

    /** ADMIN — regista/atualiza o orçamento (horas → valor). */
    public function setQuote(Request $request, int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'estimated_hours' => ['required', 'numeric', 'min:0.25', 'max:1000'],
        ]);

        $ticket = $this->service->setQuote($ticket, (float) $data['estimated_hours']);
        return $this->respond($ticket, 'Quote saved successfully.');
    }

    /** ADMIN — marca pago (fora do software); a fatura em PDF é opcional. */
    public function markPaid(Request $request, int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        // Fatura opcional, mas se vier tem de ser PDF (não-confiável).
        if ($request->hasFile('invoice')) {
            $request->validate([
                'invoice' => ['file', 'mimes:pdf', 'max:8192'],
            ]);
        }

        $ticket = $this->service->markPaid($ticket, $request->file('invoice'), $request->user());

        return $this->respond($ticket, 'Pagamento marcado.');
    }

    /** ADMIN — anula o pagamento marcado por engano (fica na auditoria). */
    public function unmarkPaid(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);

        return $this->respond($this->service->unmarkPaid($ticket, $request->user()), 'Pagamento anulado.');
    }

    /** ADMIN — anexa ou troca a fatura em PDF (multipart), sem mexer no pagamento. */
    public function storeInvoice(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);
        $request->validate(['invoice' => ['required', 'file', 'mimes:pdf', 'max:8192']]);

        return $this->respond($this->service->attachInvoice($ticket, $request->file('invoice'), $request->user()), 'Fatura anexada.');
    }

    /** ADMIN — retira a fatura (só a que anexou). */
    public function destroyInvoice(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);

        return $this->respond($this->service->removeInvoice($ticket, $request->user()), 'Fatura retirada.');
    }

    /** ADMIN — edita uma mensagem que escreveu (o texto anterior fica guardado). */
    public function updateMessage(Request $request, int $ticketId, int $messageId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $this->edits->editMessage($ticket, $this->message($ticket, $messageId), $request->user(), $data['body']);

        return $this->respond($ticket, 'Mensagem editada.');
    }

    /** ADMIN — apaga uma mensagem que escreveu (fica a marca na thread). */
    public function destroyMessage(Request $request, int $ticketId, int $messageId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);
        $this->edits->deleteMessage($ticket, $this->message($ticket, $messageId), $request->user());

        return $this->respond($ticket, 'Mensagem apagada.');
    }

    /** ADMIN — retira o anexo de um pedido que criou. */
    public function destroyScreenshot(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);
        $this->edits->removeScreenshot($ticket, $request->user());

        return $this->respond($ticket, 'Anexo retirado.');
    }

    /** ADMIN — apaga (soft delete) um pedido que criou por engano. */
    public function destroy(Request $request, int $ticketId)
    {
        $this->ensureRoot();
        $ticket = SupportTicket::findOrFail($ticketId);
        $this->edits->deleteTicket($ticket, $request->user());

        return $this->respond($ticket, 'Pedido apagado.');
    }

    private function message(SupportTicket $ticket, int $messageId): SupportTicketMessage
    {
        return SupportTicketMessage::where('support_ticket_id', $ticket->id)->findOrFail($messageId);
    }

    /** ADMIN — marca concluído quando o trabalho termina. */
    public function markCompleted(int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $ticket = $this->service->markCompleted($ticket);

        return $this->respond($ticket, 'Ticket marked as completed successfully.');
    }
}
