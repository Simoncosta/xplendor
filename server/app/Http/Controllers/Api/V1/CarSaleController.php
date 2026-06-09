<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarSaleRequest;
use App\Http\Requests\UpdateCarSaleRequest;
use App\Services\CarSaleService;
use Illuminate\Support\Facades\Auth;

class CarSaleController extends Controller
{
    public function __construct(
        protected CarSaleService $carSaleService
    ) {}

    public function store(StoreCarSaleRequest $request, int $companyId, int $car)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $sale = $this->carSaleService->closeSale($companyId, $car, $request->validated());

        return ApiResponse::success($sale, 'Venda registada com sucesso.');
    }

    /**
     * Update OR create dos dados PII do comprador num car_sale existente.
     * Pré-condição: car pertence à empresa do utilizador autenticado E está sold.
     */
    public function updateSale(UpdateCarSaleRequest $request, int $companyId, int $car)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        try {
            $sale = $this->carSaleService->updateSale($companyId, $car, $request->validated());
        } catch (\DomainException $e) {
            // Mensagem pt-PT clara em vez de 409 cru — viatura não está sold.
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success($sale, 'Dados do comprador actualizados.');
    }
}
