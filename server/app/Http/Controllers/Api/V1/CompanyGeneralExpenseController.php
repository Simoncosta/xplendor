<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\GeneralExpenseSupplier;
use App\Models\OcrInvoice;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Services\GeneralExpenseService;
use App\Services\OcrLineArticleService;
use App\Services\OcrPingwinLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Faturas de despesas gerais (fornecedores de serviços, como os CTT): a definição por empresa e
 * fornecedor, "Despesas gerais (sem stock)", com um artigo por taxa de IVA escolhido entre os que já
 * existem no catálogo (não cria artigos no PingWin). Só o root marca (plataforma.configurar, na ACL);
 * a fatura de um fornecedor marcado tem o "Aplicar a esta fatura". Tenancy PRIMEIRO.
 */
class CompanyGeneralExpenseController extends Controller
{
    public function __construct(private readonly GeneralExpenseService $expenses) {}

    // GET /companies/{id}/integrations/pingwin/suppliers/{supplierId}/general-expense
    public function show(int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $supplier = PingwinSupplier::where('company_id', $companyId)->find($supplierId);
        if (! $supplier) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        return ApiResponse::success($this->present($companyId, $supplier), 'Despesas gerais do fornecedor.');
    }

    // PUT /companies/{id}/integrations/pingwin/suppliers/{supplierId}/general-expense   {enabled, articles: {"23": id|null, …}}
    public function update(Request $request, int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $supplier = PingwinSupplier::where('company_id', $companyId)->find($supplierId);
        if (! $supplier) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }
        $rules = ['enabled' => ['required', 'boolean'], 'articles' => ['nullable', 'array']];
        foreach (GeneralExpenseService::RATES as $rate) {
            $rules["articles.{$rate}"] = ['nullable', 'integer'];
        }
        $data = $request->validate($rules);

        if (! $data['enabled']) {
            GeneralExpenseSupplier::where('company_id', $companyId)->where('supplier_id', $supplier->id)->delete();
            Log::info('[OCR] despesas gerais: fornecedor desmarcado', ['company_id' => $companyId, 'supplier_id' => $supplier->id, 'user_id' => Auth::id()]);

            return ApiResponse::success($this->present($companyId, $supplier), 'O fornecedor deixou de ser de despesas gerais.');
        }

        // Só artigos que já existem no catálogo desta empresa e estão ativos.
        $articles = [];
        foreach (GeneralExpenseService::RATES as $rate) {
            $id = $data['articles'][(string) $rate] ?? $data['articles'][$rate] ?? null;
            if ($id !== null) {
                $articles[(string) $rate] = (int) $id;
            }
        }
        if ($articles === []) {
            return ApiResponse::error('Escolha o artigo de pelo menos uma taxa de IVA.', 422, ['articles' => ['Escolha o artigo de pelo menos uma taxa de IVA.']]);
        }
        $usable = OcrLineArticleService::usable(PingwinCatalogItem::where('company_id', $companyId)->whereIn('id', array_values($articles)))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $bad = array_keys(array_filter($articles, fn ($id) => ! in_array($id, $usable, true)));
        if ($bad !== []) {
            $errors = [];
            foreach ($bad as $rate) {
                $errors["articles.{$rate}"] = ["O artigo da taxa de {$rate} % não existe no catálogo desta empresa ou não está ativo."];
            }

            return ApiResponse::error('Há artigos que não existem no catálogo desta empresa ou não estão ativos.', 422, $errors);
        }

        GeneralExpenseSupplier::updateOrCreate(['company_id' => $companyId, 'supplier_id' => $supplier->id],
            ['articles' => $articles, 'changed_by' => Auth::id()]);
        Log::info('[OCR] despesas gerais: fornecedor marcado', ['company_id' => $companyId, 'supplier_id' => $supplier->id, 'rates' => array_keys($articles), 'user_id' => Auth::id()]);

        return ApiResponse::success($this->present($companyId, $supplier), 'Fornecedor marcado como despesas gerais (sem stock).');
    }

    // POST /companies/{id}/ocr/invoices/{invoiceId}/general-expense/apply   ("Aplicar a esta fatura")
    public function apply(int $companyId, int $invoiceId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $inv = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $inv) {
            return ApiResponse::error('Fatura não encontrada.', 404);
        }
        if (! in_array($inv->status, ['por_validar', 'validada'], true) || $inv->link_status !== OcrPingwinLinkService::NOT_LAUNCHED) {
            return ApiResponse::error('Só se aplica a faturas já lidas e ainda por lançar no PingWin.', 422);
        }
        $setting = $this->expenses->forInvoice($inv);
        if (! $setting) {
            return ApiResponse::error('O fornecedor desta fatura não está marcado como despesas gerais.', 422);
        }
        $res = $this->expenses->apply($inv, $setting, true);

        return ApiResponse::success(['result' => $res['count'], 'general_expense' => $res['summary']],
            sprintf('Despesa geral aplicada: %d linhas ligadas.', $res['count'][GeneralExpenseService::METHOD]));
    }

    private function present(int $companyId, PingwinSupplier $supplier): array
    {
        $setting = GeneralExpenseSupplier::where('company_id', $companyId)->where('supplier_id', $supplier->id)->first();
        $usable = $setting ? $this->expenses->usableArticles($setting) : [];
        $articles = [];
        foreach (GeneralExpenseService::RATES as $rate) {
            $id = $setting?->articleFor($rate);
            $articles[(string) $rate] = $id === null ? null : ($usable[$id] ?? ['id' => $id, 'code' => null, 'description' => null, 'inactive' => true]);
        }

        return ['supplier_id' => $supplier->id, 'enabled' => $setting !== null, 'articles' => $articles, 'rates' => GeneralExpenseService::RATES,
            'changed_at' => $setting?->updated_at?->toIso8601String()];
    }
}
