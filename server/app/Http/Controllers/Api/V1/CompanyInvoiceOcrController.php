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
use App\Services\InvoiceOcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
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
            'status'  => ['nullable', 'in:processing,por_validar,validada,erro'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);

        $page = OcrInvoice::where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with('summary:id,ocr_invoice_id,total_cents')
            ->orderByDesc('created_at')
            ->paginate($perPage)->appends($request->query());

        // Mapeia para a UI (total em euros).
        $page->getCollection()->transform(fn (OcrInvoice $inv) => [
            'id'            => $inv->id,
            'supplier_name' => $inv->supplier_name,
            'supplier_nif'  => $inv->supplier_nif,
            'number'        => $inv->number,
            'issue_date'    => optional($inv->issue_date)->toDateString(),
            'status'        => $inv->status,
            'confidence'    => $inv->confidence,
            'total'         => $inv->summary ? $inv->summary->total_cents / 100 : null,
            'created_at'    => optional($inv->created_at)->toIso8601String(),
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

        if ($this->usedThisMonth($companyId) >= $this->monthlyCap()) {
            return ApiResponse::error('Limite mensal de leituras de faturas atingido (' . $this->monthlyCap() . '). Contacta o suporte para aumentar.', 429);
        }

        $file = $request->file('file');
        $path = $file->store("ocr-invoices/{$companyId}", $this->disk());

        $invoice = OcrInvoice::create([
            'company_id'    => $companyId,
            'image_path'    => $path,
            'image_mime'    => $file->getClientMimeType(),
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

        return ApiResponse::success([
            'invoice'   => $this->presentInvoice($invoice),
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
            'lines.*.item'            => ['nullable', 'string', 'max:255'],
            'lines.*.quantity'        => ['nullable', 'numeric'],
            'lines.*.unit'            => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price'      => ['nullable', 'numeric'],
            'lines.*.discount_pct'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.line_total'      => ['nullable', 'numeric'],
            'lines.*.vat_rate'        => ['nullable', 'integer', 'in:6,13,23'],
            'summary'                          => ['required', 'array'],
            'summary.goods_total'              => ['nullable', 'numeric'],
            'summary.commercial_discount'      => ['nullable', 'numeric'],
            'summary.taxable_base'             => ['nullable', 'numeric'],
            'summary.vat_total'                => ['nullable', 'numeric'],
            'summary.withholding'              => ['nullable', 'numeric'],
            'summary.financial_discount'       => ['nullable', 'numeric'],
            'summary.total'                    => ['nullable', 'numeric'],
            'summary.vat_breakdown'            => ['nullable', 'array'],
            'summary.vat_breakdown.*.rate'     => ['nullable', 'integer', 'in:6,13,23'],
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

            $invoice->lines()->delete();
            $pos = 0;
            foreach ($data['lines'] as $line) {
                OcrInvoiceLine::create([
                    'ocr_invoice_id'   => $invoice->id,
                    'company_id'       => $companyId,
                    'position'         => $pos++,
                    'item'             => $line['item'] ?? null,
                    'quantity'         => $line['quantity'] ?? null,
                    'unit'             => $line['unit'] ?? null,
                    'unit_price_cents' => $this->cents($line['unit_price'] ?? null),
                    'discount_pct'     => $line['discount_pct'] ?? null,
                    'line_total_cents' => $this->cents($line['line_total'] ?? null),
                    'vat_rate'         => $line['vat_rate'] ?? null,
                ]);
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

        return ApiResponse::success(['invoice' => $this->presentInvoice($invoice->fresh(['lines', 'summary']))], 'Fatura validada e guardada.');
    }

    // ------------------------------------------------------------ helpers

    private function presentInvoice(OcrInvoice $inv): array
    {
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
            'lines'             => $inv->lines->map(fn (OcrInvoiceLine $l) => [
                'item'         => $l->item,
                'quantity'     => $l->quantity,
                'unit'         => $l->unit,
                'unit_price'   => $l->unit_price_cents !== null ? $l->unit_price_cents / 100 : null,
                'discount_pct' => $l->discount_pct,
                'line_total'   => $l->line_total_cents !== null ? $l->line_total_cents / 100 : null,
                'vat_rate'     => $l->vat_rate,
            ])->values(),
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

    private function usedThisMonth(int $companyId): int
    {
        return OcrInvoice::where('company_id', $companyId)
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
