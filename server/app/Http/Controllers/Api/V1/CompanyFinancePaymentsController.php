<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinancePayment;
use App\Services\Finance\FinancePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Finanças: "Registar pagamento" e "Regularizar" (só na XPLENDOR, enquanto não há nota de
 * liquidação no PingWin), desfazer e o histórico. "Lançar a nota de liquidação no PingWin" fica para
 * depois (falta o HAR): nada se escreve no PingWin.
 */
class CompanyFinancePaymentsController extends Controller
{
    public function __construct(private readonly FinancePaymentService $payments) {}

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'docheader_ids' => ['required', 'array', 'min:1', 'max:200'], 'docheader_ids.*' => ['string', 'max:32'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . now('Europe/Lisbon')->toDateString()],
            'method' => ['required', Rule::in(array_keys(FinancePayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
        ], ['paid_on.before_or_equal' => 'A data do pagamento não pode ser futura.', 'method.in' => 'Escolha a forma de pagamento.']);
        $rows = $this->payments->register($companyId, $data['docheader_ids'], $data['paid_on'], $data['method'], $data['reference'] ?? null, Auth::id());

        return ApiResponse::success(['payments' => $rows->map->only(['id', 'docheader_id', 'amount_cents', 'paid_on', 'method'])->values()],
            $rows->count() === 1 ? 'Pagamento registado na XPLENDOR.' : sprintf('%d pagamentos registados na XPLENDOR.', $rows->count()), 201);
    }

    public function destroy(int $companyId, int $paymentId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $p = FinancePayment::where('company_id', $companyId)->find($paymentId);
        if (! $p) {
            return ApiResponse::error('Pagamento não encontrado.', 404);
        }
        $this->payments->undo($p, Auth::id());

        return ApiResponse::success(null, 'Pagamento desfeito: o documento volta ao que o PingWin diz.');
    }

    public function history(int $companyId, string $docheaderId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['history' => $this->payments->history($companyId, $docheaderId)], 'Histórico de pagamentos.');
    }

    public function payables(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['suppliers' => $this->payments->payables($companyId),
            'methods' => collect(FinancePayment::METHODS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()], 'Por pagar.');
    }
}
