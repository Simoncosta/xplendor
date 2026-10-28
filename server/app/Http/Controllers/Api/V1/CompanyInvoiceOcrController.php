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
use App\Services\GeneralExpenseService;
use App\Services\InvoiceOcrService;
use App\Services\OcrInvoiceDeleteService;
use App\Services\OcrInvoiceIntake;
use App\Services\OcrInvoiceLaunchService;
use App\Services\OcrInvoiceRereadService;
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

        $intake = app(OcrInvoiceIntake::class);

        return ApiResponse::success(['invoices' => $page, 'monthly_cap' => $intake->monthlyCap(), 'used_this_month' => $intake->usedThisMonth($companyId)], 'Faturas carregadas.');
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

        // F2c: o mesmo ficheiro já carregado (e não apagado) → recusa ANTES da IA e do teto. E-mail 7: a
        // mesma entrada serve o anexo importado da caixa de correio (OcrInvoiceIntake).
        $file = $request->file('file');
        $result = app(OcrInvoiceIntake::class)->accept($companyId, (string) file_get_contents($file->getRealPath()), $file->getClientMimeType(), ['origin' => 'upload'], $file->getClientOriginalName());

        return self::intakeResponse($result);
    }

    /** A resposta da entrada de uma fatura (carregamento manual e anexo da caixa de correio). */
    public static function intakeResponse(array $result)
    {
        if ($result['status'] === 'refused') {
            return ApiResponse::error($result['error'], $result['code']); // o antivírus (vírus, em baixo ou por configurar)
        }
        if ($result['status'] === 'duplicate') {
            $same = $result['invoice'];

            return ApiResponse::error("Já carregada: abrir a fatura #{$same->id}.", 409, ['code' => 'ficheiro_duplicado', 'existing_id' => $same->id, 'existing_number' => $same->number]);
        }
        if ($result['status'] === 'cap') {
            return ApiResponse::error('Limite mensal de leituras de faturas atingido (' . $result['cap'] . '). Contacta o suporte para aumentar.', 429);
        }
        $invoice = $result['invoice'];

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
     * "Reler do documento" (async, mesmo polling): volta a 'processing' e mete o job na fila.
     * keep_manual = true volta a aplicar as alterações feitas à mão à nova leitura; false
     * substitui-as. Não relê uma fatura que está a ser lida nem uma já lançada pela XPLENDOR.
     */
    public function reprocess(Request $request, int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $invoice = OcrInvoice::where('company_id', $companyId)->with('lines')->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if ($invoice->status === 'processing') {
            return ApiResponse::error('A fatura já está a ser lida.', 409);
        }
        $d = app(OcrInvoiceLaunchService::class)->draftDoc($invoice);
        if ($d && $d['docstatus_id'] !== OcrInvoiceLaunchService::VOIDED) {
            return ApiResponse::error("A fatura já foi lançada no PingWin ({$d['document']}): para a reler, anule primeiro o documento.", 422);
        }

        $changes = app(OcrInvoiceRereadService::class)->prepare($invoice, $request->boolean('keep_manual'));
        $invoice->update(['status' => 'processing', 'error_message' => null]);
        ProcessInvoiceOcrJob::dispatch($companyId, $invoice->id);
        $kept = $request->boolean('keep_manual') && $changes['changes'] !== [];

        return ApiResponse::success(['id' => $invoice->id, 'status' => 'processing', 'kept_changes' => $kept ? count($changes['changes']) : 0],
            $kept ? 'A ler a fatura de novo, mantendo as alterações manuais… a página atualiza sozinha.' : 'A ler a fatura de novo… a página atualiza sozinha.', 202);
    }

    /** O que foi mudado à mão desde a leitura (para "Reler do documento" perguntar). */
    public function manualChanges(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->with('lines')->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }

        return ApiResponse::success(app(OcrInvoiceRereadService::class)->manualChanges($invoice), 'Alterações manuais.');
    }

    /**
     * "Revalidar": grava as linhas de agora (se vierem, sem mudar o estado da fatura) e volta a
     * correr as validações: descontos → artigos, conferência com o QR e IVA por taxa. Sem IA.
     */
    public function revalidate(Request $request, int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if (! in_array($invoice->status, ['por_validar', 'validada'], true)) {
            return ApiResponse::error('Esta fatura não pode ser revalidada agora.', 422);
        }
        if ($request->has('lines')) {
            $this->saveEdits($invoice, $this->validateEdits($request), $companyId, false);
        } else {
            app(OcrInvoiceRereadService::class)->revalidate($invoice);
        }

        return ApiResponse::success(['invoice' => $this->presentInvoice($invoice->fresh(['lines', 'summary', 'discounts']))], 'Validações feitas de novo.');
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

        $this->saveEdits($invoice, $this->validateEdits($request), $companyId, true);

        // F3: ao validar, volta a ligar ao PingWin (espelho; se o fornecedor não estiver lá, o
        // worker pesquisa-o por NIF).
        $this->relink($invoice->fresh());
        // F2b: as linhas editadas voltam a ligar-se aos artigos (as manuais ficam).
        app(OcrLineArticleService::class)->linkInvoice($invoice->fresh());

        return ApiResponse::success(['invoice' => $this->presentInvoice($invoice->fresh(['lines', 'summary', 'discounts']))], 'Fatura validada e guardada.');
    }

    private function validateEdits(Request $request): array
    {
        return $request->validate([
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
            'lines.*.line_total'      => ['nullable', 'numeric'], // o valor antes do desconto de promoção
            'lines.*.promo_code'      => ['nullable', 'string', 'max:40'],
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
    }

    /**
     * Grava as correções (linhas + sumário + fornecedor; valores em EUROS → CÊNTIMOS) e revalida
     * (descontos → artigos, conferência com o QR). $validate marca a fatura 'validada'.
     */
    private function saveEdits(OcrInvoice $invoice, array $data, int $companyId, bool $validate): void
    {
        // Fornecedor escolhido tem de ser da própria empresa (tenancy).
        $supplierId = null;
        if (! empty($data['supplier_id'])) {
            $supplierId = PingwinSupplier::where('company_id', $companyId)->where('id', $data['supplier_id'])->value('id');
        }

        DB::transaction(function () use ($invoice, $companyId, $data, $supplierId, $validate) {
            $invoice->update([
                'supplier_id'   => $supplierId,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_nif'  => $data['supplier_nif'] ?? null,
                'number'        => $data['number'] ?? null,
                'issue_date'    => $data['issue_date'] ?? null,
            ] + ($validate ? ['status' => 'validada'] : []));  // synced_to_pingwin permanece false — Fase B.

            // F2b: as linhas que vêm com id mantêm-se (e a ligação ao artigo); as que não vêm saem.
            $keep = collect($data['lines'])->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
            $invoice->lines()->whereNotIn('id', $keep)->delete();
            $pos = 0;
            foreach ($data['lines'] as $line) {
                $existing = ! empty($line['id']) ? $invoice->lines()->whereKey((int) $line['id'])->first() : null;
                $gross = $this->cents($line['line_total'] ?? null);
                $values = [
                    'position'         => $pos++,
                    'supplier_code'    => $line['supplier_code'] ?? null,
                    'item'             => $line['item'] ?? null,
                    'quantity'         => $line['quantity'] ?? null,
                    'unit'             => $line['unit'] ?? null,
                    'unit_price'       => isset($line['unit_price']) && is_numeric($line['unit_price']) ? number_format((float) $line['unit_price'], 6, '.', '') : null,
                    'discount_pct'     => $line['discount_pct'] ?? null,
                    // O valor da linha é o bruto; o líquido sai da revalidação (bruto − desconto).
                    'gross_total_cents' => $gross,
                    'line_total_cents' => $gross - (int) ($existing?->discount_cents ?? 0),
                    'vat_rate'         => $line['vat_rate'] ?? null,
                ];
                if (array_key_exists('promo_code', $line)) {
                    $values['promo_code'] = ($line['promo_code'] ?? null) !== null && trim((string) $line['promo_code']) !== '' ? trim((string) $line['promo_code']) : null;
                }
                // Taxa mudada à mão: o código de IVA lido deixa de valer para esta linha.
                if ($existing && (int) $existing->vat_rate !== (int) ($line['vat_rate'] ?? -1)) {
                    $values['vat_code'] = null;
                }
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

        app(OcrInvoiceRereadService::class)->revalidate($invoice->fresh());
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

    // ------------------------------------------------------------ Fatia 5: conferência com as guias

    /** A conferência fatura × guias (só leitura): cada guia referida contra os documentos do mesmo fornecedor no PingWin. */
    public function guides(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $invoice = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $invoice) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        $data = app(\App\Services\Restaurant\GuideReconciliationService::class)->for($invoice);

        return ApiResponse::success(['reconciliation' => $data], $data ? 'Conferência com as guias.' : 'A fatura não refere guias.');
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
        $lineCodes = $lineLinks->codesFor($inv, $inv->lines); // F2d: principal + alternativos
        // Fornecedor de despesas gerais: as linhas a 0,00 € não contam e não precisam de ligação.
        $expenses = app(GeneralExpenseService::class);
        $expense = $expenses->forInvoice($inv);
        // ⚠️ A Xplendor calcula a soma das linhas (mais fiável que o sumário da IA).
        $linesTotal = $inv->lines->sum(fn (OcrInvoiceLine $l) => (int) ($l->line_total_cents ?? 0)) / 100;

        return [
            'id'                => $inv->id,
            // E-mail 7: o selo "Veio do e-mail" (com o endereço da caixa); as carregadas à mão não mostram nada.
            'origin'            => $inv->origin,
            'origin_mailbox_email' => $inv->origin === 'email' ? $inv->origin_mailbox_email : null,
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
            'prices_include_vat' => (bool) $inv->prices_include_vat,
            'handwritten_notes' => $inv->handwritten_notes,
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
                // O valor da linha (bruto, o da fatura), o desconto de promoção em € e o líquido.
                'line_total'   => $l->line_total_cents !== null ? $l->grossCents() / 100 : null,
                'discount'     => (int) $l->discount_cents / 100,
                'discount_code' => $l->discount_code,
                'discount_label' => $l->discount_label,
                'net_total'    => $l->line_total_cents !== null ? $l->line_total_cents / 100 : null,
                'iec'          => $l->iec_cents !== null ? $l->iec_cents / 100 : null, // já incluído no valor da linha
                'vat_included' => $l->vat_included_cents !== null ? $l->vat_included_cents / 100 : null, // o valor com IVA, tal como lido
                'promo_code'   => $l->promo_code,
                'vat_code'     => $l->vat_code,
                'vat_rate'     => $l->vat_rate,
                'expense_ignored' => $expense !== null && ! GeneralExpenseService::hasValue($l),
            ] + $lineLinks->presentLine($l, $lineArticles, $lineCodes))->values(),
            // Os descontos de promoção lidos e a quem ficaram (sem par: "Desconto por atribuir").
            'discounts'         => $inv->discounts->map(fn ($d) => [
                'id' => $d->id, 'code' => $d->code, 'label' => $d->label, 'amount' => $d->amount_cents / 100,
                'vat_code' => $d->vat_code, 'vat_rate' => $d->vat_rate, 'status' => $d->status, 'warning' => $d->warning,
                'allocations' => collect($d->allocations ?? [])->map(fn ($a) => ['line_id' => $a['line_id'] ?? null, 'amount' => ((int) ($a['cents'] ?? 0)) / 100])->values(),
            ])->values(),
            'vat_table'         => collect($inv->vat_table ?? [])->map(fn ($r) => ['code' => $r['code'], 'rate' => $r['rate'], 'base' => $r['base_cents'] / 100, 'vat' => $r['vat_cents'] / 100])->values(),
            'vat_by_rate'       => app(OcrInvoiceRereadService::class)->vatByRate($inv),
            'ignored_lines'     => array_values((array) ($inv->ignored_lines ?? [])),
            'manual_changes_known' => is_array($inv->read_snapshot),
            'reread_unmatched'  => array_values((array) ($inv->reread_keep['unmatched'] ?? [])),
            'closed_at'         => $inv->closed_at?->toIso8601String(),
            'closed_by_name'    => $inv->closed_by ? \App\Models\User::whereKey($inv->closed_by)->value('name') : null,
            'next_to_launch_id' => $this->nextToLaunch($inv),
            // F2c: motivo para não se poder apagar (null = pode)
            'delete_block'      => app(OcrInvoiceDeleteService::class)->blockReason($inv),
            // F2b: linhas ligadas a artigos; "pronta para lançar" = todas ligadas.
            'articles_summary'  => OcrLineArticleService::summary($expense ? GeneralExpenseService::withValue($inv->lines) : $inv->lines),
            // Despesas gerais: "Despesa geral: N linhas, 1 por taxa" e as taxas sem artigo (null sem a marca).
            'general_expense'   => $expense ? $expenses->summary($inv, $expense) : null,
            // O fornecedor (por NIF) a que o root pode marcar as despesas gerais (atalho para os Fornecedores).
            'expense_supplier_id' => $lineLinks->supplier($inv)?->id,
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

    /** A próxima fatura por lançar (a seguinte na lista, que vai da mais recente para a mais antiga). */
    private function nextToLaunch(OcrInvoice $inv): ?int
    {
        $q = fn () => OcrInvoice::where('company_id', $inv->company_id)->whereKeyNot($inv->id)
            ->whereIn('status', ['por_validar', 'validada'])
            ->where(fn ($w) => $w->whereNull('link_status')->orWhereNotIn('link_status', [
                OcrPingwinLinkService::LAUNCHED, OcrPingwinLinkService::LAUNCHED_GUIDES, OcrPingwinLinkService::DUPLICATE]));

        return $q()->where('id', '<', $inv->id)->orderByDesc('id')->value('id') ?? $q()->orderByDesc('id')->value('id');
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
