<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ExpenseCharge;
use App\Services\Billing\ChargeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cobranças da XPLENDOR dentro da plataforma, para os utilizadores da PRÓPRIA empresa (e o
 * root): ver, descarregar a fatura e indicar "Já paguei". Sem o módulo de Finanças (as
 * empresas sem ele também recebem cobranças). A agência gestora nunca as vê.
 */
class CompanyChargeController extends Controller
{
    public function __construct(private readonly ChargeService $charges) {}

    public function index(int $companyId)
    {
        $this->assertCompany($companyId);
        $rows = ExpenseCharge::with(['company', 'expense'])->where('company_id', $companyId)
            ->where('status', '!=', ExpenseCharge::CANCELLED)
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'payment_indicated' THEN 1 ELSE 2 END")->orderByDesc('due_date')->limit(100)->get();

        return ApiResponse::success([
            'charges' => $rows->map(fn ($c) => ChargeService::presentForClient($c))->values(),
            'open_count' => $rows->where('status', ExpenseCharge::OPEN)->count(),
        ], 'Cobranças da XPLENDOR.');
    }

    public function invoice(int $companyId, int $chargeId): Response
    {
        $charge = $this->find($companyId, $chargeId);

        return response($this->charges->invoice($charge), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="fatura-xplendor-' . $charge->id . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function paid(Request $request, int $companyId, int $chargeId)
    {
        $charge = $this->find($companyId, $chargeId);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimetypes:' . implode(',', ChargeService::PROOF_MIMES), 'max:' . ChargeService::PROOF_MAX_KB],
        ], ['proof.mimetypes' => 'O comprovativo tem de ser PDF ou imagem (JPG, PNG ou WEBP).', 'proof.max' => 'O comprovativo não pode ter mais de 10 MB.']);

        $charge = $this->charges->indicatePayment($charge, 'app', $request->user(), $data['note'] ?? null, $request->file('proof'));

        return ApiResponse::success(ChargeService::presentForClient($charge), 'Obrigado. A XPLENDOR vai confirmar o pagamento.');
    }

    private function find(int $companyId, int $chargeId): ExpenseCharge
    {
        $this->assertCompany($companyId);

        return ExpenseCharge::where('company_id', $companyId)->where('status', '!=', ExpenseCharge::CANCELLED)->findOrFail($chargeId);
    }

    /** Os utilizadores da própria empresa e o root; a agência gestora nunca. */
    private function assertCompany(int $companyId): void
    {
        abort_if(! $this->authorizeCompany($companyId) || $this->viaAgency($companyId), 403, 'As cobranças da XPLENDOR são só da própria empresa.');
    }
}
