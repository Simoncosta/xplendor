<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SupplierDocumentsSyncInProgress;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinDocumentSyncRun;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinSupplierDocument;
use App\Services\SupplierDocumentsService;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Documentos de fornecedor do PingWin (F1, SÓ LEITURA), para a página de Faturas:
 * lista com filtros, disparo de uma sync (período) e estado do run. Valores em EUROS na API
 * (a BD guarda cêntimos). Tenancy pela empresa da rota.
 */
class CompanySupplierDocumentsController extends Controller
{
    public function __construct(private readonly SupplierDocumentsService $docs) {}

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'from'        => ['nullable', 'date_format:Y-m-d'],
            'to'          => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // CSV ("1209,1205" — o api.get do frontend serializa arrays assim) ou array.
            'types'       => ['nullable'],
            'supplier'    => ['nullable', 'string', 'max:30'],
            'missing_ref' => ['nullable', 'boolean'],
            'page'        => ['nullable', 'integer', 'min:1'],
            'perPage'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $types = $data['types'] ?? null;
        $types = is_array($types) ? $types : (is_string($types) && $types !== '' ? explode(',', $types) : []);
        $types = array_values(array_filter(array_map('trim', $types), fn ($t) => preg_match('/^\d{1,30}$/', $t)));

        $page = $this->docs->query($companyId, [
            'from'        => $data['from'] ?? null,
            'to'          => $data['to'] ?? null,
            'types'       => $types,
            'supplier'    => $data['supplier'] ?? null,
            'missing_ref' => $request->boolean('missing_ref'),
        ])->paginate((int) ($data['perPage'] ?? 25))->appends($request->query());

        // F3: fatura carregada (OCR) ligada a cada documento — coluna "OCR".
        $ocrLinks = OcrInvoicePingwinLink::where('company_id', $companyId)
            ->whereIn('docheader_id', $page->getCollection()->pluck('docheader_id')->all())
            ->pluck('ocr_invoice_id', 'docheader_id')->all();

        $page->getCollection()->transform(fn (PingwinSupplierDocument $d) => [
            'id'                    => $d->id,
            'docheader_id'          => $d->docheader_id,
            'doc_date'              => $d->doc_date?->toDateString(),
            'doc_time'              => $d->doc_time,
            'document'              => $d->document,
            'docconfig_id'          => $d->docconfig_id,
            'doctype'               => $d->doctype,
            'entity_pingwin_id'     => $d->entity_pingwin_id,
            'entity_name'           => $d->entity_name,
            'tax_number'            => $d->tax_number,
            'docreference_number'   => $d->docreference_number,
            'total'                 => $d->total_cents / 100,
            'paid'                  => $d->paid,
            'docstatus_id'          => $d->docstatus_id,
            'docstatus_description' => $d->docstatus_description,
            'employee_name'         => $d->employee_name,
            'store_name'            => $d->store_name,
            'ocr_invoice_id'        => isset($ocrLinks[$d->docheader_id]) ? (int) $ocrLinks[$d->docheader_id] : null,
        ]);

        $this->docs->expireStale($companyId);
        $last = $this->docs->latestRun($companyId);

        return ApiResponse::success([
            'documents' => $page,
            'facets'    => $this->docs->facets($companyId),
            'last_run'  => $last?->toApi(),
        ], 'Documentos de fornecedor carregados.');
    }

    public function sync(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:' . now('Europe/Lisbon')->addDay()->toDateString()],
        ]);

        try {
            $run = $this->docs->startRun($companyId, $data['from'], $data['to'], PingwinDocumentSyncRun::TRIGGER_MANUAL);
        } catch (SupplierDocumentsSyncInProgress $e) {
            return ApiResponse::error($e->getMessage(), 409, ['run' => $e->run->toApi()]);
        }

        return ApiResponse::success(['run' => $run->toApi()], 'Sincronização pedida.', 202);
    }

    public function run(int $companyId, int $runId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $this->docs->expireStale($companyId);
        $run = PingwinDocumentSyncRun::where('company_id', $companyId)->find($runId);
        if (! $run) {
            return ApiResponse::error('Sincronização não encontrada.', 404);
        }

        return ApiResponse::success(['run' => $run->toApi()], 'Estado da sincronização.');
    }
}
