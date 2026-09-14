<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Orçamentos avulsos, LADO ADMIN (super-admin / root). TRANSVERSAL:
 * não filtra por company. Vive no grupo /api/v1/admin, atrás do EnsureSuperAdmin
 * — não há endpoint de stand para orçamentos (os stands nunca veem isto).
 *
 * Defesa em profundidade: reconfirma role 'root' mesmo atrás do middleware
 * (mesmo padrão do AdminSupportTicketController).
 */
class QuoteController extends Controller
{
    public function __construct(protected QuoteService $service) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    /** Listagem transversal + filtro opcional por estado. Recentes no topo. */
    public function index(Request $request)
    {
        $this->ensureRoot();

        $query = Quote::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('id');

        return ApiResponse::success(
            QuoteResource::collection($query->get())->resolve(),
            'Quotes fetched successfully.'
        );
    }

    /** Contagens/valores para o topo da consola (embrião de estatísticas). */
    public function summary()
    {
        $this->ensureRoot();

        $byStatus = Quote::selectRaw('status, COUNT(*) as c, COALESCE(SUM(amount),0) as total')
            ->groupBy('status')->get()->keyBy('status');

        return ApiResponse::success([
            'pending'        => (int) ($byStatus['pending']->c ?? 0),
            'approved'       => (int) ($byStatus['approved']->c ?? 0),
            'rejected'       => (int) ($byStatus['rejected']->c ?? 0),
            'total'          => (int) Quote::count(),
            'approved_value' => (float) ($byStatus['approved']->total ?? 0),
        ], 'Quotes summary fetched successfully.');
    }

    public function store(QuoteRequest $request)
    {
        $this->ensureRoot();

        $quote = $this->service->store($request->validated());

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote created successfully.'
        );
    }

    public function show(int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote fetched successfully.'
        );
    }

    public function update(QuoteRequest $request, int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $quote = $this->service->update($quote->id, $request->validated());

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote updated successfully.'
        );
    }

    /** Mudar apenas o estado (aprovado/rejeitado quando o cliente responde). */
    public function updateStatus(Request $request, int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(Quote::STATUSES)],
        ]);

        $quote = $this->service->update($quote->id, ['status' => $data['status']]);

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote status updated successfully.'
        );
    }

    public function destroy(int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $this->service->destroy($quote->id);

        return ApiResponse::success(null, 'Quote deleted successfully.');
    }
}
