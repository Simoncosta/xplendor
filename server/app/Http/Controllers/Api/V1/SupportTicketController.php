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
}
