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
            'open'        => ['nullable', 'boolean'],  // "Em aberto": só os documentos em Aberto (8001)
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
            'open'        => $request->boolean('open'),
        ])->paginate((int) ($data['perPage'] ?? 25))->appends($request->query());

        // F3: fatura carregada (OCR) ligada a cada documento — coluna "OCR".
        $ocrLinks = OcrInvoicePingwinLink::where('company_id', $companyId)
            ->whereIn('docheader_id', $page->getCollection()->pluck('docheader_id')->all())
            ->pluck('ocr_invoice_id', 'docheader_id')->all();

        // "Fechar no PingWin": o que impede fechar cada documento em Aberto (pelo espelho).
        $openDocs = $page->getCollection()->where('docstatus_id', \App\Services\Finance\SupplierDocumentCloseService::OPEN);
        $closeChecks = $openDocs->isNotEmpty() ? app(\App\Services\Finance\SupplierDocumentCloseService::class)->checks($companyId, $openDocs) : [];

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
            'close_problems'        => array_key_exists($d->docheader_id, $closeChecks) ? $closeChecks[$d->docheader_id] : null,
        ]);

        $this->docs->expireStale($companyId);
        $last = $this->docs->latestRun($companyId);

        return ApiResponse::success([
            'documents' => $page,
            'can_close' => $this->can($companyId, 'restauracao.criar'),
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

    /**
     * As linhas de um documento de fornecedor (do espelho), para o "Corrigir" da Auditoria (preço
     * fora do normal, data fora da série, fatura sem linhas). Só lê; nunca chama o PingWin.
     */
    public function lines(int $companyId, string $docheaderId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = \App\Models\PingwinSupplierDocument::where('company_id', $companyId)->where('docheader_id', $docheaderId)->first();
        if (! $d) {
            return ApiResponse::error('Documento não encontrado.', 404);
        }
        $lines = \App\Models\PingwinSupplierDocumentLine::where('company_id', $companyId)->where('docheader_id', $docheaderId)
            ->orderBy('line_number')->get();

        return ApiResponse::success([
            'document' => [
                'id' => $d->id, 'docheader_id' => $d->docheader_id, 'document' => $d->document, 'doctype' => $d->doctype,
                'doc_date' => $d->doc_date ? substr((string) $d->doc_date, 0, 10) : null, 'entity_name' => $d->entity_name,
                'tax_number' => $d->tax_number, 'total' => $d->total_cents / 100, 'docstatus_description' => $d->docstatus_description,
                'docreference_number' => $d->docreference_number, 'store_name' => $d->store_name,
                'lines_status' => $d->lines_status, 'lines_synced_at' => $d->lines_synced_at?->toIso8601String(),
            ],
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'line_number' => $l->line_number, 'product_pingwin_id' => $l->product_pingwin_id,
                'code' => $l->product_code, 'description' => $l->description, 'qnt' => (float) $l->qnt,
                'unit' => $l->unit_desc, 'price' => (float) $l->price, 'discount' => (float) ($l->discount1 ?? 0),
                'total' => $l->total_cents / 100, 'tax' => $l->tax_value_cents / 100, 'tax_description' => $l->tax_description,
            ])->all(),
        ], 'Linhas do documento.');
    }
}
