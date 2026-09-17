<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
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
    public function __construct(protected SupportTicketService $service) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    /** Listagem TRANSVERSAL (todas as empresas) + filtros + ordenação por-tratar. */
    public function index(Request $request)
    {
        $this->ensureRoot();

        $query = SupportTicket::with(['company', 'user'])->withCount('messages');

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

    public function show(int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::with(['company', 'user', 'messages.user'])->find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Admin ticket fetched successfully.'
        );
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
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Ticket status updated successfully.'
        );
    }

    /** ADMIN — reclassifica o TIPO (ativa/desativa a camada de orçamento). */
    public function reclassify(Request $request, int $ticketId)
    {
        $this->ensureRoot();

        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(SupportTicket::TYPES)],
        ]);

        $ticket = $this->service->reclassifyType($ticket, $data['type']);
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Ticket reclassificado com sucesso.'
        );
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
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Message added successfully.'
        );
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
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Quote saved successfully.'
        );
    }

    /** ADMIN — marca pago (fora do software) e anexa o PDF da fatura. */
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

        $ticket = $this->service->markPaid($ticket, $request->file('invoice'));
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Ticket marked as paid successfully.'
        );
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
        $ticket->load(['company', 'user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Ticket marked as completed successfully.'
        );
    }
}
