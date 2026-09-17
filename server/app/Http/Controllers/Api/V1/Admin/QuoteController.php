<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Company;
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

    /** Empresas ativas para o campo creatable (selecionar empresa cadastrada). */
    public function companies()
    {
        $this->ensureRoot();

        $companies = Company::query()->active()->orderBy('fiscal_name')
            ->get(['id', 'fiscal_name', 'trade_name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->trade_name ?: $c->fiscal_name])
            ->all();

        return ApiResponse::success($companies, 'Companies fetched successfully.');
    }

    /** Listagem transversal + filtro opcional por estado. Recentes no topo. */
    public function index(Request $request)
    {
        $this->ensureRoot();

        $query = Quote::query()->with('company:id,fiscal_name,trade_name');

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

        // create() resolve o nome a partir da empresa (se ligado) e notifica-a.
        $quote = $this->service->create($request->validated());
        $quote->load('company:id,fiscal_name,trade_name');

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote created successfully.'
        );
    }

    public function show(int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::with('company:id,fiscal_name,trade_name')->find($quoteId);
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

    /**
     * Mudar o estado (para orçamentos de NOME LIVRE — sem painel de empresa, o
     * Simon marca tudo à mão). Nos orçamentos ligados a uma empresa, aprovar/
     * rejeitar é decisão DELA (endpoint de stand) — o Simon não aprova por ela.
     */
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

        if ($quote->isLinkedToCompany() && in_array($data['status'], ['approved', 'rejected'], true)) {
            return ApiResponse::error('Este orçamento está ligado a uma empresa — a aprovação/rejeição é feita por ela no painel dela.', 422);
        }

        $quote = $this->service->update($quote->id, ['status' => $data['status']]);

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote status updated successfully.'
        );
    }

    /** ADMIN — marca pago (fora do software). Só a partir de 'approved'. */
    public function markPaid(int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $quote = $this->service->markPaid($quote);
        $quote->load('company:id,fiscal_name,trade_name');

        return ApiResponse::success((new QuoteResource($quote))->resolve(), 'Quote marked as paid.');
    }

    /** ADMIN — marca concluído. Só a partir de 'paid'. */
    public function markCompleted(int $quoteId)
    {
        $this->ensureRoot();

        $quote = Quote::find($quoteId);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $quote = $this->service->markCompleted($quote);
        $quote->load('company:id,fiscal_name,trade_name');

        return ApiResponse::success((new QuoteResource($quote))->resolve(), 'Quote marked as completed.');
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
