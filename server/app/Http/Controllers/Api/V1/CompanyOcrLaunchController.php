<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LaunchBlocked;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinDocumentWrite;
use App\Services\OcrInvoiceLaunchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". Tenancy PRIMEIRO. Guardas (para a UI), lançar em
 * rascunho, fechar, anular e a unidade de uma linha. As escritas são assíncronas (job no worker);
 * a UI acompanha pelo GET (polling).
 */
class CompanyOcrLaunchController extends Controller
{
    public function __construct(private readonly OcrInvoiceLaunchService $launch) {}

    public function show(int $companyId, int $invoiceId)
    {
        [$inv, $err] = $this->invoice($companyId, $invoiceId);

        return $err ?? ApiResponse::success($this->launch->preview($inv), 'Lançamento no PingWin.');
    }

    public function store(Request $request, int $companyId, int $invoiceId)
    {
        [$inv, $err] = $this->invoice($companyId, $invoiceId);
        if ($err) {
            return $err;
        }
        // O frontend envia multipart (axios por omissão): "true"/"false" em texto → $request->boolean().
        try {
            $w = $this->launch->requestLaunch($inv, Auth::id(), $request->boolean('accept_check_diff'));
        } catch (LaunchBlocked $e) {
            return ApiResponse::error($e->getMessage(), 422, ['code' => 'guardas', 'failed' => $e->failed]);
        }

        return ApiResponse::success(['write' => $this->launch->presentWrite($w)] + $this->launch->preview($inv->fresh()),
            'A lançar no PingWin (rascunho)… aguarda o resultado.', 202);
    }

    public function close(int $companyId, int $invoiceId)
    {
        return $this->status($companyId, $invoiceId, PingwinDocumentWrite::CLOSE, 'A fechar o documento no PingWin…');
    }

    public function void(int $companyId, int $invoiceId)
    {
        return $this->status($companyId, $invoiceId, PingwinDocumentWrite::VOID, 'A anular o rascunho no PingWin…');
    }

    public function setLineUnit(Request $request, int $companyId, int $invoiceId, int $lineId)
    {
        [$inv, $err] = $this->invoice($companyId, $invoiceId);
        if ($err) {
            return $err;
        }
        $line = OcrInvoiceLine::where('company_id', $companyId)->where('ocr_invoice_id', $inv->id)->find($lineId);
        if (! $line) {
            return ApiResponse::error('Linha não encontrada.', 404);
        }
        $data = $request->validate(['unit_id' => ['required', 'string', 'max:30'], 'quantity' => ['nullable', 'numeric']]);
        try {
            $this->launch->setLineUnit($inv, $line, $data['unit_id'], isset($data['quantity']) ? (float) $data['quantity'] : null);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success($this->launch->preview($inv->fresh()), 'Unidade da linha guardada.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function status(int $companyId, int $invoiceId, string $action, string $message)
    {
        [$inv, $err] = $this->invoice($companyId, $invoiceId);
        if ($err) {
            return $err;
        }
        try {
            $w = $this->launch->requestStatus($inv, $action, Auth::id());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['write' => $this->launch->presentWrite($w)] + $this->launch->preview($inv->fresh()), $message, 202);
    }

    /** @return array{0: ?OcrInvoice, 1: mixed} */
    private function invoice(int $companyId, int $invoiceId): array
    {
        if (! $this->authorizeCompany($companyId)) {
            return [null, ApiResponse::error('Acesso negado: utilizador inválido.', 403)];
        }
        $inv = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $inv) {
            return [null, ApiResponse::error('Fatura não encontrada.', 404)];
        }
        if (! in_array($inv->status, ['por_validar', 'validada'], true)) {
            return [null, ApiResponse::error('Esta fatura não pode ser lançada no PingWin.', 422)];
        }

        return [$inv, null];
    }
}
