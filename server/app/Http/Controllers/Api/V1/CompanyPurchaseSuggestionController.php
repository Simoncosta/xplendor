<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\PurchaseSuggestionService;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Restauração › Compras sugeridas (SÓ LEITURA: nada se encomenda no PingWin). Por
 * fornecedor, a próxima entrega provável e as linhas sugeridas, com o erro medido das últimas 4
 * semanas. Os preços e os totais só com financas.ver.
 */
class CompanyPurchaseSuggestionController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = (new PurchaseSuggestionService($companyId))->suggest();

        return ApiResponse::success(CompassFinancials::forUser($data, $request->user(), $companyId), 'Compras sugeridas.');
    }
}
