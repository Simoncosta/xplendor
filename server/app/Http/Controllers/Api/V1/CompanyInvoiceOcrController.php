<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinSupplier;
use App\Jobs\LinkOcrInvoiceJob;
use App\Services\InvoiceOcrService;
use App\Services\OcrInvoiceDeleteService;
use App\Services\OcrLineArticleService;
use App\Services\OcrPingwinLinkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — OCR de faturas de fornecedor (Fase A): carregar → IA lê → validar →
 * guardar NA XPLENDOR. ⚠️ NÃO escreve no PingWin (Fase B). Gate módulo pingwin +
 * tenancy. Valores da API em EUROS (a BD guarda cêntimos).
 */
class CompanyInvoiceOcrController extends Controller
{
    public function __construct(private readonly InvoiceOcrService $ocr) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        return $this->authorizeCompany($companyId);
    }

    private function disk(): string
    {
        return (string) config('services.openai.ocr_disk', 'local');
    }

    /** Lista as faturas OCR da empresa (paginação Laravel + estado + total). */
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'page'    => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status'  => ['nullable', 'in:processing,por_validar,validada,erro,nao_desta_empresa'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);
        $deleted = $request->boolean('deleted'); // F2c: "Mostrar apagadas" (só as apagadas)
        $deleter = app(OcrInvoiceDeleteService::class);

        $page = ($deleted ? OcrInvoice::onlyTrashed() : OcrInvoice::query())->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with('summary:id,ocr_invoice_id,total_cents')
            ->orderByDesc('created_at')
            ->paginate($perPage)->appends($request->query());

        // F3: Liquidado e Loja vêm do(s) documento(s) do PingWin ligado(s).
        $linkInfo = app(OcrPingwinLinkService::class)->listInfo($companyId, $page->getCollection()->pluck('id')->all());

        // Mapeia para a UI (total em euros).
        $page->getCollection()->transform(fn (OcrInvoice $inv) => [
            'id'            => $inv->id,
            'supplier_name' => $inv->supplier_name,
            'supplier_nif'  => $inv->supplier_nif,
            'number'        => $inv->number,
            'issue_date'    => optional($inv->issue_date)->toDateString(),
            'status'        => $inv->status,
            'confidence'    => $inv->confidence,
            'source'        => $inv->source,
            'check_status'  => $inv->check_status,
            'doc_type'      => $inv->doc_type,
            'link_status'   => $inv->link_status,
            'paid'          => $linkInfo[$inv->id]['paid'] ?? null,
            'store'         => $linkInfo[$inv->id]['store'] ?? null,
            'pingwin_doc_status' => $linkInfo[$inv->id]['xplendor_status'] ?? null, // FB-1: 8001 rascunho / 8002 lançada
            'total'         => $inv->summary ? $inv->summary->total_cents / 100 : null,
            'created_at'    => optional($inv->created_at)->toIso8601String(),
            // F2c: apagar / repor
            'deleted_at'    => optional($inv->deleted_at)->toIso8601String(),
            'delete_block'  => $inv->trashed() ? null : $deleter->blockReason($inv),
            'restore_block' => $inv->trashed() ? $deleter->restoreBlockReason($inv) : null,
        ]);

        return ApiResponse::success(['invoices' => $page, 'monthly_cap' => $this->monthlyCap(), 'used_this_month' => $this->usedThisMonth($companyId)], 'Faturas carregadas.');
    }

    /**
     * Carrega uma fatura (imagem/PDF): guarda o ficheiro, cria o registo
     * ('processing') e mete a leitura pela IA na FILA (worker). Teto mensal por
     * empresa (controlo de custo). NÃO grava dados até a IA ler + o utilizador validar.
     */
    public function upload(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288'], // 12 MB
        ]);

        // F2c: o mesmo ficheiro já carregado (e não apagado) → recusa ANTES da IA e do teto.
        $sha256 = hash_file('sha256', $request->file('file')->getRealPath());
        if ($same = app(OcrInvoiceDeleteService::class)->sameFile($companyId, $sha256)) {
            return ApiResponse::error("Já carregada: abrir a fatura #{$same->id}.", 409, ['code' => 'ficheiro_duplicado', 'existing_id' => $same->id, 'existing_number' => $same->number]);
        }

        if ($this->usedThisMonth($companyId) >= $this->monthlyCap()) {
            return ApiResponse::error('Limite mensal de leituras de faturas atingido (' . $this->monthlyCap() . '). Contacta o suporte para aumentar.', 429);
        }

        $file = $request->file('file');
        $path = $file->store("ocr-invoices/{$companyId}", $this->disk());

        $invoice = OcrInvoice::create([
            'company_id'    => $companyId,
            'image_path'    => $path,
            'image_size_bytes' => (int) $file->getSize(),
            'image_mime'    => $file->getClientMimeType(),
            'file_sha256'   => $sha256,
            'status'        => 'processing',
            'synced_to_pingwin' => false,
        ]);

        ProcessInvoiceOcrJob::dispatch($companyId, $invoice->id);

        return ApiResponse::success(['id' => $invoice->id, 'status' => $invoice->status], 'A ler a fatura… serás notificado quando terminar.');
    }

    /** Uma fatura com linhas + sumário (euros) + fornecedores (para o react-select). */
    public function show(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $invoice = OcrInvoice::where('company_id', $companyId)->with(['lines', 'summary'])->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        // F2b: artigo criado pela linha já confirmado → liga a linha.
        if ($invoice->lines->whereNotNull('article_write_id')->isNotEmpty()) {
            app(OcrLineArticleService::class)->resolvePendingCreations($invoice);
            $invoice->load('lines');
        }

        return ApiResponse::success([
            'invoice'   => $this->presentInvoice($invoice),
            'pingwin'   => app(OcrPingwinLinkService::class)->present($invoice),
            'suppliers' => PingwinSupplier::where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'tax_number']),
        ], 'Fatura carregada.');
    }

    /** Serve a imagem original (disco privado) — tenancy garantida. */
    public function image(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice || ! Storage::disk($this->disk())->exists($invoice->image_path)) {
            return ApiResponse::error('Imagem não encontrada.', 404);
        }

        return response(Storage::disk($this->disk())->get($invoice->image_path), 200)
            ->header('Content-Type', $invoice->image_mime ?: 'application/octet-stream');
    }

    /**
     * REPROCESSA uma fatura (async, mesmo polling): volta a 'processing' e mete o job na fila.
     * Não reprocessa uma fatura já validada (perdia-se a validação do utilizador) nem uma
     * que já está a ser lida.
     */
    public function reprocess(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if ($invoice->status === 'processing') {
            return ApiResponse::error('A fatura já está a ser lida.', 409);
        }
        if ($invoice->status === 'validada') {
            return ApiResponse::error('A fatura já foi validada — não é reprocessada.', 422);
        }

        $invoice->update(['status' => 'processing', 'error_message' => null]);
        ProcessInvoiceOcrJob::dispatch($companyId, $invoice->id);

        return ApiResponse::success(['id' => $invoice->id, 'status' => 'processing'], 'A ler a fatura de novo… a página atualiza sozinha.', 202);
    }

    /**
     * VALIDA e guarda as correções do utilizador (linhas + sumário + fornecedor).
     * Valores chegam em EUROS → guardamos em CÊNTIMOS. Marca 'validada'. NÃO escreve
     * no PingWin (synced_to_pingwin fica false).
     */
    public function update(Request $request, int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }

        $data = $request->validate([
            'supplier_id'   => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_nif'  => ['nullable', 'string', 'max:20'],
            'number'        => ['nullable', 'string', 'max:120'],
            'issue_date'    => ['nullable', 'date_format:Y-m-d'],
            'lines'                   => ['present', 'array'],
            'lines.*.id'              => ['nullable', 'integer'],
            'lines.*.supplier_code'   => ['nullable', 'string', 'max:60'],
            'lines.*.item'            => ['nullable', 'string', 'max:255'],
            'lines.*.quantity'        => ['nullable', 'numeric'],
            'lines.*.unit'            => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price'      => ['nullable', 'numeric'],
            'lines.*.discount_pct'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.line_total'      => ['nullable', 'numeric'],
            'lines.*.vat_rate'        => ['nullable', 'integer', Rule::in(InvoiceOcrService::validVatRates())],
            'summary'                          => ['required', 'array'],
            'summary.goods_total'              => ['nullable', 'numeric'],
            'summary.commercial_discount'      => ['nullable', 'numeric'],
            'summary.taxable_base'             => ['nullable', 'numeric'],
            'summary.vat_total'                => ['nullable', 'numeric'],
            'summary.withholding'              => ['nullable', 'numeric'],
            'summary.financial_discount'       => ['nullable', 'numeric'],
            'summary.total'                    => ['nullable', 'numeric'],
            'summary.vat_breakdown'            => ['nullable', 'array'],
            'summary.vat_breakdown.*.rate'     => ['nullable', 'integer', Rule::in(InvoiceOcrService::validVatRates())],
            'summary.vat_breakdown.*.base'     => ['nullable', 'numeric'],
            'summary.vat_breakdown.*.vat'      => ['nullable', 'numeric'],
        ]);

        // Fornecedor escolhido tem de ser da própria empresa (tenancy).
        $supplierId = null;
        if (! empty($data['supplier_id'])) {
            $supplierId = PingwinSupplier::where('company_id', $companyId)->where('id', $data['supplier_id'])->value('id');
        }

        DB::transaction(function () use ($invoice, $companyId, $data, $supplierId) {
            $invoice->update([
                'supplier_id'   => $supplierId,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_nif'  => $data['supplier_nif'] ?? null,
                'number'        => $data['number'] ?? null,
                'issue_date'    => $data['issue_date'] ?? null,
                'status'        => 'validada',
                // synced_to_pingwin permanece false — Fase B.
            ]);

            // F2b: as linhas que vêm com id mantêm-se (e a ligação ao artigo); as que não vêm saem.
            $keep = collect($data['lines'])->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
            $invoice->lines()->whereNotIn('id', $keep)->delete();
            $pos = 0;
            foreach ($data['lines'] as $line) {
                $values = [
                    'position'         => $pos++,
                    'supplier_code'    => $line['supplier_code'] ?? null,
                    'item'             => $line['item'] ?? null,
                    'quantity'         => $line['quantity'] ?? null,
                    'unit'             => $line['unit'] ?? null,
                    'unit_price'       => isset($line['unit_price']) && is_numeric($line['unit_price']) ? number_format((float) $line['unit_price'], 6, '.', '') : null,
                    'discount_pct'     => $line['discount_pct'] ?? null,
                    'line_total_cents' => $this->cents($line['line_total'] ?? null),
                    'vat_rate'         => $line['vat_rate'] ?? null,
                ];
                $existing = ! empty($line['id']) ? $invoice->lines()->whereKey((int) $line['id'])->first() : null;
                $existing
                    ? $existing->update($values)
                    : OcrInvoiceLine::create($values + ['ocr_invoice_id' => $invoice->id, 'company_id' => $companyId]);
            }

            $s = $data['summary'];
            $breakdown = [];
            foreach (($s['vat_breakdown'] ?? []) as $b) {
                if (! isset($b['rate'])) {
                    continue;
                }
                $breakdown[] = ['rate' => (int) $b['rate'], 'base_cents' => (int) $this->cents($b['base'] ?? 0), 'vat_cents' => (int) $this->cents($b['vat'] ?? 0)];
            }

            OcrInvoiceSummary::updateOrCreate(
                ['ocr_invoice_id' => $invoice->id],
                [
                    'company_id'                => $companyId,
                    'goods_total_cents'         => (int) $this->cents($s['goods_total'] ?? 0),
                    'commercial_discount_cents' => (int) $this->cents($s['commercial_discount'] ?? 0),
                    'taxable_base_cents'        => (int) $this->cents($s['taxable_base'] ?? 0),
                    'vat_total_cents'           => (int) $this->cents($s['vat_total'] ?? 0),
                    'withholding_cents'         => (int) $this->cents($s['withholding'] ?? 0),
                    'financial_discount_cents'  => (int) $this->cents($s['financial_discount'] ?? 0),
                    'total_cents'               => (int) $this->cents($s['total'] ?? 0),
                    'vat_breakdown'             => $breakdown,
                ]
            );
        });

        // F3: ao validar, volta a ligar ao PingWin (espelho; se o fornecedor não estiver lá, o
        // worker pesquisa-o por NIF).
        $this->relink($invoice->fresh());
        // F2b: as linhas editadas voltam a ligar-se aos artigos (as manuais ficam).
        app(OcrLineArticleService::class)->linkInvoice($invoice->fresh());

        return ApiResponse::success(['invoice' => $this->presentInvoice($invoice->fresh(['lines', 'summary']))], 'Fatura validada e guardada.');
    }

    // ------------------------------------------------------------ F2c: apagar / repor

    /** Apaga (soft) uma fatura. 422 com o motivo se não se pode (ex.: lançada pela XPLENDOR). */
    public function destroy(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        try {
            app(OcrInvoiceDeleteService::class)->delete($invoice, Auth::id());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422, ['code' => 'nao_apagavel']);
        }

        return ApiResponse::success(['id' => $invoiceId], 'Fatura apagada. Pode repô-la durante ' . OcrInvoiceDeleteService::PURGE_AFTER_DAYS . ' dias.');
    }

    /** Apaga várias: as que não se podem apagar ficam de fora, com o motivo. */
    public function bulkDestroy(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $res = app(OcrInvoiceDeleteService::class)->deleteMany($companyId, $data['ids'], Auth::id());

        return ApiResponse::success($res, count($res['deleted']) . ' fatura(s) apagada(s).' . ($res['skipped'] ? ' ' . count($res['skipped']) . ' ficaram de fora.' : ''));
    }

    /** Repõe uma fatura apagada (enquanto o ficheiro existir). */
    public function restore(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::onlyTrashed()->where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura apagada não encontrada.', 404);
        }
        try {
            app(OcrInvoiceDeleteService::class)->restore($invoice);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422, ['code' => 'nao_reponivel']);
        }

        return ApiResponse::success(['id' => $invoiceId], 'Fatura reposta.');
    }

    // ------------------------------------------------------------ F3: ligação ao PingWin

    /** "Procurar no PingWin": corre a ligação já (espelhos) e, sem fornecedor, pesquisa-o no worker. */
    public function pingwinSearch(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if (! in_array($invoice->status, ['por_validar', 'validada'], true)) {
            return ApiResponse::error('Esta fatura não pode ser ligada ao PingWin.', 422);
        }
        $this->relink($invoice);

        return ApiResponse::success(['pingwin' => app(OcrPingwinLinkService::class)->present($invoice->fresh())], 'Ligação ao PingWin verificada.');
    }

    /** Confirmar a ligação atual, ou ligar aos documentos escolhidos (candidato, "Escolher outro", guias). */
    public function pingwinConfirm(Request $request, int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if (! in_array($invoice->status, ['por_validar', 'validada'], true)) {
            return ApiResponse::error('Esta fatura não pode ser ligada ao PingWin.', 422);
        }
        $data = $request->validate([
            'docheader_ids'   => ['nullable', 'array', 'max:200'],
            'docheader_ids.*' => ['string', 'regex:/^\d{1,30}$/'],
            'method'          => ['nullable', 'in:numero,total_data,guias,manual'],
        ]);

        try {
            app(OcrPingwinLinkService::class)->confirm($invoice, $data['docheader_ids'] ?? [], $data['method'] ?? null, Auth::id());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['pingwin' => app(OcrPingwinLinkService::class)->present($invoice->fresh())], 'Ligação ao PingWin confirmada.');
    }

    /** Desligar: os documentos deixam de estar ligados (e não voltam sozinhos). */
    public function pingwinUnlink(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        app(OcrPingwinLinkService::class)->unlink($invoice);

        return ApiResponse::success(['pingwin' => app(OcrPingwinLinkService::class)->present($invoice->fresh())], 'Ligação ao PingWin desfeita.');
    }

    /** Liga já com os espelhos; sem fornecedor no espelho, o worker pesquisa-o por NIF (polling). */
    private function relink(OcrInvoice $invoice): void
    {
        $links = app(OcrPingwinLinkService::class);
        $links->link($invoice, false);
        if ($invoice->link_status === OcrPingwinLinkService::MISSING_SUPPLIER && OcrPingwinLinkService::digits($invoice->supplier_nif) !== '') {
            $invoice->update(['link_search_pending' => true]);
            LinkOcrInvoiceJob::dispatch($invoice->id);
        }
    }

    // ------------------------------------------------------------ helpers

    private function presentInvoice(OcrInvoice $inv): array
    {
        $lineLinks = app(OcrLineArticleService::class);
        $lineArticles = OcrLineArticleService::articlesFor($inv->lines);
        // ⚠️ A Xplendor calcula a soma das linhas (mais fiável que o sumário da IA).
        $linesTotal = $inv->lines->sum(fn (OcrInvoiceLine $l) => (int) ($l->line_total_cents ?? 0)) / 100;

        return [
            'id'                => $inv->id,
            'lines_total'       => $linesTotal, // soma calculada das linhas (referência)
            'status'            => $inv->status,
            'confidence'        => $inv->confidence,
            'model'             => $inv->model,
            'prompt_version'    => $inv->prompt_version,
            'synced_to_pingwin' => (bool) $inv->synced_to_pingwin,
            'error_message'     => $inv->error_message,
            'supplier_id'       => $inv->supplier_id,
            'supplier_name'     => $inv->supplier_name,
            'supplier_nif'      => $inv->supplier_nif,
            'number'            => $inv->number,
            'issue_date'        => optional($inv->issue_date)->toDateString(),
            // F2a: origem (QR / texto / imagem), conferência pelo QR e custo.
            'buyer_nif'         => $inv->buyer_nif,
            'atcud'             => $inv->atcud,
            'doc_type'          => $inv->doc_type,
            'qr_ok'             => $inv->qr_ok,
            'source'            => $inv->source,
            'lines_source'      => $inv->lines_source,
            'pages'             => $inv->pages,
            'attempts'          => $inv->attempts,
            'tokens_in'         => $inv->tokens_in,
            'tokens_out'        => $inv->tokens_out,
            'cost_usd'          => $inv->cost_usd,
            'duration_ms'       => $inv->duration_ms,
            'check_status'      => $inv->check_status,
            'check_diff'        => collect($inv->check_diff ?? [])->map(fn ($r) => [
                'rate'  => $r['rate'] ?? null,
                'qr'    => ($r['qr_cents'] ?? 0) / 100,
                'lines' => ($r['lines_cents'] ?? 0) / 100,
                'diff'  => ($r['diff_cents'] ?? 0) / 100,
                'ok'    => (bool) ($r['ok'] ?? false),
            ])->values(),
            'lines'             => $inv->lines->sortBy('position')->map(fn (OcrInvoiceLine $l) => [
                'id'           => $l->id,
                'supplier_code' => $l->supplier_code,
                'item'         => $l->item,
                'quantity'     => $l->quantity !== null ? (float) $l->quantity : null,
                'unit'         => $l->unit,
                'unit_price'   => $l->unit_price !== null ? (float) $l->unit_price : null, // 6 casas
                'discount_pct' => $l->discount_pct,
                'line_total'   => $l->line_total_cents !== null ? $l->line_total_cents / 100 : null,
                'vat_rate'     => $l->vat_rate,
            ] + $lineLinks->presentLine($l, $lineArticles))->values(),
            // F2c: motivo para não se poder apagar (null = pode)
            'delete_block'      => app(OcrInvoiceDeleteService::class)->blockReason($inv),
            // F2b: linhas ligadas a artigos; "pronta para lançar" = todas ligadas.
            'articles_summary'  => OcrLineArticleService::summary($inv->lines),
            'summary'           => $inv->summary ? [
                'goods_total'         => $inv->summary->goods_total_cents / 100,
                'commercial_discount' => $inv->summary->commercial_discount_cents / 100,
                'taxable_base'        => $inv->summary->taxable_base_cents / 100,
                'vat_total'           => $inv->summary->vat_total_cents / 100,
                'withholding'         => $inv->summary->withholding_cents / 100,
                'financial_discount'  => $inv->summary->financial_discount_cents / 100,
                'total'               => $inv->summary->total_cents / 100,
                'vat_breakdown'       => collect($inv->summary->vat_breakdown ?? [])->map(fn ($b) => [
                    'rate' => $b['rate'] ?? null,
                    'base' => isset($b['base_cents']) ? $b['base_cents'] / 100 : null,
                    'vat'  => isset($b['vat_cents']) ? $b['vat_cents'] / 100 : null,
                ])->values(),
            ] : null,
        ];
    }

    private function monthlyCap(): int
    {
        return (int) config('services.openai.ocr_monthly_cap', 200);
    }

    /** Leituras do mês (as apagadas CONTAM: apagar não devolve a leitura ao teto). */
    private function usedThisMonth(int $companyId): int
    {
        return OcrInvoice::withTrashed()->where('company_id', $companyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** € (número) → cêntimos inteiros (0 se null/não numérico). */
    private function cents($v): int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return 0;
        }

        return (int) round(((float) $v) * 100);
    }
}
