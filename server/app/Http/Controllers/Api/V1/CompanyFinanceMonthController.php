<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinanceClassification;
use App\Models\PingwinSupplierDocument;
use App\Services\Finance\MonthFinanceService;
use App\Services\Finance\SupplierDocumentCloseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (SÓ LEITURA no PingWin). A
 * classificação manual dos "Por classificar" fica na XPLENDOR. O "Fechar no PingWin" é o da lista
 * de documentos (CompanySupplierDocumentCloseController): este ecrã só o chama.
 */
class CompanyFinanceMonthController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'store' => ['nullable', 'integer'],
        ]);
        $month = $data['month'] ?? now('Europe/Lisbon')->subMonthNoOverflow()->format('Y-m');
        $res = (new MonthFinanceService($companyId))->month($month, isset($data['store']) ? (int) $data['store'] : null);

        // Os documentos em Aberto: o que impede fechar cada um (pelo espelho).
        $openIds = array_column(array_filter($res['documents']['rows'], fn ($r) => $r['open']), 'docheader_id');
        $checks = $openIds ? app(SupplierDocumentCloseService::class)->checks($companyId, PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', $openIds)->get()) : [];
        foreach ($res['documents']['rows'] as &$r) {
            $r['close_problems'] = $r['open'] ? ($checks[$r['docheader_id']] ?? []) : null;
        }
        unset($r);
        $res['can'] = ['classify' => $this->can($companyId, 'financas.editar'), 'close' => $this->can($companyId, 'restauracao.criar')];

        return ApiResponse::success($res, 'Mês carregado.');
    }

    /** Classificar um "Por classificar": este documento, ou sempre para este fornecedor. */
    public function classify(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'scope' => ['required', Rule::in([FinanceClassification::DOCUMENT, FinanceClassification::SUPPLIER])],
            'docheader_id' => ['required', 'string', 'max:32'],
            'group' => ['required', Rule::in(array_keys((array) config('finance_month.groups')))],
        ], ['group.in' => 'Grupo desconhecido.']);
        $doc = PingwinSupplierDocument::where('company_id', $companyId)->where('docheader_id', $data['docheader_id'])->first();
        if (! $doc) {
            return ApiResponse::error('Documento não encontrado.', 404);
        }
        $supplier = $data['scope'] === FinanceClassification::SUPPLIER;
        if ($supplier && ! $doc->entity_pingwin_id) {
            return ApiResponse::error('O documento não tem fornecedor.', 422);
        }
        $key = $supplier ? 'sup:' . $doc->entity_pingwin_id : 'doc:' . $doc->docheader_id;
        $c = FinanceClassification::updateOrCreate(['company_id' => $companyId, 'key' => $key], [
            'scope' => $data['scope'], 'docheader_id' => $supplier ? null : $doc->docheader_id, 'entity_pingwin_id' => $doc->entity_pingwin_id,
            'entity_name' => $doc->entity_name, 'group' => $data['group'], 'created_by' => Auth::id(),
        ]);

        return ApiResponse::success(['classification' => $c->only(['id', 'scope', 'key', 'group', 'entity_name'])],
            $supplier ? "Classificado: sempre assim para {$doc->entity_name}." : 'Documento classificado.');
    }

    public function unclassify(int $companyId, int $classificationId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $c = FinanceClassification::where('company_id', $companyId)->find($classificationId);
        if (! $c) {
            return ApiResponse::error('Classificação não encontrada.', 404);
        }
        $c->delete();

        return ApiResponse::success(null, 'Classificação retirada.');
    }
}
