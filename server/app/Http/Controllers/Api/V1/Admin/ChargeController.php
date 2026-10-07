<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ExpenseCharge;
use App\Services\Billing\ChargeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cobranças da XPLENDOR, lado do ROOT (Administração › Cobranças): todas as empresas,
 * criar com a fatura em PDF, marcar como paga, anular com motivo, confirmar ou recusar o
 * pagamento indicado e "Enviar agora". Vive no grupo /admin (ensure_super_admin).
 */
class ChargeController extends Controller
{
    public function __construct(private readonly ChargeService $charges) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    public function index(Request $request)
    {
        $this->ensureRoot();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(ExpenseCharge::STATUSES)],
            'company_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $today = ChargeService::today()->toDateString();
        $rows = ExpenseCharge::query()->with(['company:id,fiscal_name,trade_name,invoice_email,billing_reminders_enabled', 'expense'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['company_id'] ?? null, fn ($q, $c) => $q->where('company_id', $c))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', [ExpenseCharge::OPEN, ExpenseCharge::PAYMENT_INDICATED])->whereDate('due_date', '<', $today))
            // Primeiro o que pede ação: pagamento indicado, vencidas, em aberto; depois o resto.
            ->orderByRaw("CASE status WHEN 'payment_indicated' THEN 0 WHEN 'open' THEN 1 WHEN 'paid' THEN 2 ELSE 3 END")
            ->orderBy('due_date')->limit(500)->get();

        $open = ExpenseCharge::query()->with('expense:id,amount')->whereIn('status', [ExpenseCharge::OPEN, ExpenseCharge::PAYMENT_INDICATED])->get();

        return ApiResponse::success([
            'charges' => $rows->map(fn ($c) => ChargeService::presentForAdmin($c))->values(),
            'summary' => [
                'open_amount' => round((float) $open->sum(fn ($c) => $c->expense->amount), 2),
                'overdue_amount' => round((float) $open->filter(fn ($c) => $c->isOverdue())->sum(fn ($c) => $c->expense->amount), 2),
                'overdue_count' => $open->filter(fn ($c) => $c->isOverdue())->count(),
                'indicated_count' => $open->where('status', ExpenseCharge::PAYMENT_INDICATED)->count(),
            ],
        ], 'Cobranças.');
    }

    public function store(Request $request)
    {
        $this->ensureRoot();
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d'],
            'invoice' => ['required', 'file', 'mimetypes:application/pdf', 'max:' . ChargeService::INVOICE_MAX_KB],
        ], ['invoice.required' => 'Junte a fatura em PDF.', 'invoice.mimetypes' => 'A fatura tem de ser um PDF.']);

        $charge = $this->charges->create(Company::findOrFail($data['company_id']), $data, $request->file('invoice'), $request->user());

        return ApiResponse::success(ChargeService::presentForAdmin($charge), 'Cobrança criada.');
    }

    public function show(int $chargeId)
    {
        $this->ensureRoot();

        return ApiResponse::success(ChargeService::presentForAdmin(ExpenseCharge::findOrFail($chargeId)), 'Cobrança.');
    }

    public function invoice(int $chargeId): Response
    {
        $this->ensureRoot();
        $charge = ExpenseCharge::findOrFail($chargeId);

        return response($this->charges->invoice($charge), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="fatura-' . $charge->id . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function proof(int $chargeId): Response
    {
        $this->ensureRoot();
        $charge = ExpenseCharge::findOrFail($chargeId);

        return response($this->charges->proof($charge), 200, ['Content-Type' => $charge->proof_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="comprovativo-' . $charge->id . '"', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    public function markPaid(Request $request, int $chargeId)
    {
        $this->ensureRoot();

        return ApiResponse::success(ChargeService::presentForAdmin($this->charges->markPaid(ExpenseCharge::findOrFail($chargeId), $request->user())), 'Cobrança marcada como paga.');
    }

    public function cancel(Request $request, int $chargeId)
    {
        $this->ensureRoot();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Indique o motivo da anulação.']);

        return ApiResponse::success(ChargeService::presentForAdmin($this->charges->cancel(ExpenseCharge::findOrFail($chargeId), $data['reason'], $request->user())), 'Cobrança anulada.');
    }

    public function refuse(Request $request, int $chargeId)
    {
        $this->ensureRoot();
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Explique ao cliente porque não foi possível confirmar.']);

        return ApiResponse::success(ChargeService::presentForAdmin($this->charges->refuse(ExpenseCharge::findOrFail($chargeId), $data['note'], $request->user())), 'Pagamento recusado. Os lembretes retomam.');
    }

    public function send(int $chargeId)
    {
        $this->ensureRoot();
        $charge = ExpenseCharge::findOrFail($chargeId);
        $this->charges->sendNow($charge);

        return ApiResponse::success(ChargeService::presentForAdmin($charge->fresh()), 'Email enviado.');
    }
}
