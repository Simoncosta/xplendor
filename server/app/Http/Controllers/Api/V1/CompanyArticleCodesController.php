<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use App\Services\ArticleVatReportService;
use App\Services\SupplierArticleMapService;
use App\Services\SupplierCodesService;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F2d (só leitura): os códigos dos fornecedores de um artigo (principal no PingWin +
 * alternativos na XPLENDOR) para a ficha do artigo, e o relatório "IVA a rever" (IVA faturado
 * pelos fornecedores ≠ IVA do artigo). Tenancy primeiro.
 */
class CompanyArticleCodesController extends Controller
{
    public function supplierCodes(int $companyId, int $catalogItemId, SupplierCodesService $codes)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $item = PingwinCatalogItem::where('company_id', $companyId)->find($catalogItemId);
        if (! $item) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        // Fornecedores (NIF) que têm códigos para este artigo: mapa, linhas de fornecedor do PingWin e compras (F4).
        $suppliers = PingwinSupplier::where('company_id', $companyId)->whereNotNull('tax_number')->get(['pingwin_id', 'tax_number', 'name'])
            ->mapWithKeys(fn ($s) => [SupplierArticleMapService::nif($s->tax_number) => $s])->filter(fn ($s, $nif) => $nif !== '');
        $byPw = $suppliers->mapWithKeys(fn ($s, $nif) => [(string) $s->pingwin_id => $nif]);
        $names = [];
        foreach (SupplierArticleMap::where('company_id', $companyId)->where('article_id', $item->id)->pluck('supplier_nif') as $nif) {
            $names[$nif] = null;
        }
        foreach (PingwinSupplierPrice::where('company_id', $companyId)->where('is_active', true)->where('catalog_item_id', $item->id)
            ->whereNotNull('sup_product_code')->where('sup_product_code', '!=', '')->get(['supplier_pingwin_id', 'supplier_name']) as $p) {
            if ($nif = $byPw[(string) $p->supplier_pingwin_id] ?? null) {
                $names[$nif] = $p->supplier_name;
            }
        }
        DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->where('l.article_id', $item->id)->whereNotNull('l.supplier_code')->where('l.supplier_code', '!=', '')
            ->distinct()->get(['d.tax_number', 'd.entity_name'])
            ->each(function ($r) use (&$names) {
                $nif = SupplierArticleMapService::nif($r->tax_number);
                if ($nif !== '') {
                    $names[$nif] ??= $r->entity_name;
                }
            });

        $out = [];
        foreach ($names as $nif => $name) {
            $list = $codes->forArticles($companyId, (string) $nif, [$item->id])[$item->id] ?? [];
            if ($list !== []) {
                $out[] = ['nif' => (string) $nif, 'supplier' => $name ?? $suppliers[$nif]->name ?? null, 'codes' => $list];
            }
        }
        usort($out, fn ($a, $b) => [count($b['codes']), $a['supplier'] ?? ''] <=> [count($a['codes']), $b['supplier'] ?? '']);

        return ApiResponse::success(['suppliers' => $out], 'Códigos dos fornecedores.');
    }

    public function vatDivergences(int $companyId, ArticleVatReportService $report)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($report->divergences($companyId), 'IVA a rever.');
    }
}
