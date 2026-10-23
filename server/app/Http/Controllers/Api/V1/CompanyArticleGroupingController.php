<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ArticleGrouping;
use App\Models\PingwinCatalogItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Cadastros › Artigos: "Artigo de agrupamento" (ao lançar, as linhas deste artigo juntam-se
 * numa só, com quantidade 1 e o total somado). Definição da XPLENDOR, por empresa e por artigo; não
 * mexe no PingWin. Tenancy primeiro.
 */
class CompanyArticleGroupingController extends Controller
{
    public function show(int $companyId, int $catalogItemId)
    {
        [$item, $err] = $this->item($companyId, $catalogItemId);

        return $err ?? ApiResponse::success($this->present($companyId, $item), 'Artigo de agrupamento.');
    }

    public function update(Request $request, int $companyId, int $catalogItemId)
    {
        [$item, $err] = $this->item($companyId, $catalogItemId);
        if ($err) {
            return $err;
        }
        $data = $request->validate(['grouping' => ['required', 'boolean']]);
        ArticleGrouping::updateOrCreate(['company_id' => $companyId, 'product_pingwin_id' => (string) $item->pingwin_id],
            ['is_grouping' => (bool) $data['grouping'], 'changed_by' => Auth::id(), 'changed_at' => now()]);

        return ApiResponse::success($this->present($companyId, $item), $data['grouping']
            ? 'Artigo de agrupamento: ao lançar, as linhas deste artigo juntam-se numa só.'
            : 'O artigo deixou de ser de agrupamento.');
    }

    private function present(int $companyId, PingwinCatalogItem $item): array
    {
        $g = ArticleGrouping::where('company_id', $companyId)->where('product_pingwin_id', (string) $item->pingwin_id)->first();

        return ['grouping' => (bool) $g?->is_grouping, 'changed_at' => $g?->changed_at?->toIso8601String(),
            'changed_by_name' => $g?->changed_by ? User::whereKey($g->changed_by)->value('name') : null];
    }

    /** @return array{0: ?PingwinCatalogItem, 1: mixed} */
    private function item(int $companyId, int $catalogItemId): array
    {
        if (! $this->authorizeCompany($companyId)) {
            return [null, ApiResponse::error('Acesso negado: utilizador inválido.', 403)];
        }
        $item = PingwinCatalogItem::where('company_id', $companyId)->find($catalogItemId);

        return $item ? [$item, null] : [null, ApiResponse::error('Artigo não encontrado.', 404)];
    }
}
