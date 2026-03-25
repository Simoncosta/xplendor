<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarSaleRequest;
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
}
