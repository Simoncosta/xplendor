<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupportTicketRequest;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DMS — Tickets de suporte, LADO DO STAND. Scoped por company (partilhado entre
 * utilizadores da empresa). Guard tenant de 2 camadas (padrão do projeto):
 *  1. o utilizador pertence à empresa da rota (ou é root);
 *  2. o ticket pertence mesmo a essa empresa (findScoped).
 *
 * NÃO há modo "ver todos" aqui — o transversal é exclusivo do grupo /admin.
 * O stand NÃO muda o estado (só o super-admin, no lado admin).
 */
class SupportTicketController extends Controller
{
    public function __construct(protected SupportTicketService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?SupportTicket
    {
        return SupportTicket::where('company_id', $companyId)->find($id);
    }

    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $tickets = SupportTicket::with('user')
            ->withCount('messages')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            SupportTicketResource::collection($tickets)->resolve(),
            'Support tickets fetched successfully.'
        );
    }

    public function store(SupportTicketRequest $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Screenshot só para bug — não-confiável (validar mesmo autenticado).
        if ($request->input('type') === 'bug' && $request->hasFile('screenshot')) {
            $request->validate([
                'screenshot' => ['image', 'mimes:jpeg,png,webp', 'max:8192'],
            ]);
        }

        $ticket = $this->service->createTicket(
            $companyId,
            (int) Auth::id(),
            $request->validated(),
            $request->file('screenshot')
        );
        $ticket->load('user');

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Support ticket created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $ticket = $this->findScoped($companyId, $id);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $ticket->load(['user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Support ticket fetched successfully.'
        );
    }

    public function storeMessage(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $ticket = $this->findScoped($companyId, $id);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = Auth::user();
        $this->service->addMessage($ticket, (int) $user->id, $data['body'], $user->role === 'root');

        $ticket->load(['user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Message added successfully.'
        );
    }

    /**
     * STAND — ORÇAMENTOS do próprio stand: os tickets 'site_change' com camada de
     * orçamento (quote_status != null) + um resumo/pipeline por estado (contagem,
     * valor Σ quoted_amount, horas Σ estimated_hours — SEM IVA). Tenancy: só os da
     * empresa da rota. É a tela de autonomia do cliente (selecionar+somar+aprovar).
     */
    public function quotes(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $base = SupportTicket::where('company_id', $companyId)
            ->where('type', 'site_change')
            ->whereNotNull('quote_status');

        $tickets = (clone $base)->with('user')->orderByDesc('id')->get();

        return ApiResponse::success([
            'tickets' => SupportTicketResource::collection($tickets)->resolve(),
            'summary' => $this->service->quotePipeline($companyId),
        ], 'Company ticket quotes fetched successfully.');
    }

    /**
     * STAND — APROVA um PACOTE de orçamentos selecionados de uma vez. Só afeta os
     * elegíveis (quote_status = 'quoted') da própria empresa; os não-elegíveis são
     * IGNORADOS e reportados (não re-aprova 'approved'/'paid'/…). Reutiliza a lógica
     * de aprovação por ticket (approveQuote → 'approved' + notifica). Tenancy garantida.
     */
    public function approveQuotePackage(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // SÓ tickets da empresa (tenancy), site_change. IDs de fora ficam de fora.
        $tickets = SupportTicket::where('company_id', $companyId)
            ->where('type', 'site_change')
            ->whereIn('id', $data['ids'])
            ->get();

        $approved = [];
        $skipped = [];
        foreach ($tickets as $ticket) {
            if ($ticket->quote_status !== 'quoted') {
                // Não re-aprova o que já não está "orçado" (approved/paid/completed/rejected/awaiting).
                $skipped[] = ['id' => $ticket->id, 'reason' => 'Não está por aprovar (estado: ' . ($ticket->quote_status ?? '—') . ').'];
                continue;
            }
            try {
                $this->service->approveQuote($ticket); // reutiliza o fluxo existente (valida + notifica)
                $approved[] = $ticket->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $ticket->id, 'reason' => $e->getMessage()];
            }
        }

        // IDs pedidos que não pertencem à empresa/tipo (segurança) → reportar.
        $found = $tickets->pluck('id')->all();
        foreach ($data['ids'] as $id) {
            if (! in_array($id, $found, true)) {
                $skipped[] = ['id' => $id, 'reason' => 'Orçamento não encontrado.'];
            }
        }

        return ApiResponse::success([
            'approved' => $approved,
            'skipped'  => $skipped,
            'summary'  => $this->service->quotePipeline($companyId), // pipeline atualizado
        ], count($approved) . ' orçamento(s) aprovado(s).');
    }

    /**
     * STAND — decide o orçamento de um ticket "site_change": aprovar ou rejeitar.
     * É a ÚNICA transição de estado que o stand faz (nunca orça/paga/conclui —
     * isso é exclusivo do super-admin). O service valida a pré-condição
     * (só a partir de 'quoted') e devolve 422 se fora de ordem.
     */
    public function quoteDecision(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $ticket = $this->findScoped($companyId, $id);
        if (! $ticket) {
            return ApiResponse::error('Ticket não encontrado.', 404);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $ticket = $data['decision'] === 'approve'
            ? $this->service->approveQuote($ticket)
            : $this->service->rejectQuote($ticket);

        $ticket->load(['user', 'messages.user']);

        return ApiResponse::success(
            (new SupportTicketResource($ticket))->resolve(),
            'Quote decision saved successfully.'
        );
    }
}
