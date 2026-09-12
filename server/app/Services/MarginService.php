<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Expense;

/**
 * DMS Fase 2A — margem por viatura (fonte ÚNICA da fórmula).
 *
 * FÓRMULA (margem SIMPLES, SEM IVA):
 *   margem = sale_price − purchase_price − Σ(despesas da viatura)
 *
 * Regras (decisão do Simon):
 *  - Só viaturas VENDIDAS têm margem.
 *  - Despesas: TODAS as com car_id daquela viatura (pagas E em aberto — o custo
 *    é real independentemente do pagamento). EXCLUI arquivadas (coerente com o
 *    total mostrado no bloco de despesas da ficha) e EXCLUI despesas sem car_id
 *    (essas são gerais do stand, não entram na margem de um carro).
 *  - NULOS honestos: sem sale_price OU sem purchase_price → NÃO calculável
 *    (não assumir 0, que daria margem falsa). `margin = null` + `reason`.
 *
 * O RÓTULO (lucro vs margem bruta) é decidido no FRONTEND consoante
 * `companies.uses_vat` — aqui só se calcula o número.
 */
class MarginService
{
    /**
     * @return array{
     *   found: bool, calculable: bool, reason: ?string,
     *   sale_price: ?float, purchase_price: ?float,
     *   expenses_total: float, expenses_count: int, margin: ?float
     * }
     */
    public function forCar(int $companyId, int $carId): array
    {
        $car = Car::where('company_id', $companyId)->find($carId);

        if (! $car) {
            return $this->shape(found: false);
        }

        $sale = CarSale::where('car_id', $carId)->first();

        $expensesTotal = (float) Expense::where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('archived', false)
            ->sum('amount');

        $expensesCount = Expense::where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('archived', false)
            ->count();

        $salePrice = $sale && $sale->sale_price !== null ? (float) $sale->sale_price : null;
        $purchasePrice = $car->purchase_price !== null ? (float) $car->purchase_price : null;

        // Motivo de não-calculável (honestidade do rótulo).
        $reason = null;
        if (! $sale) {
            $reason = 'not_sold';
        } elseif ($salePrice === null) {
            $reason = 'no_sale_price';
        } elseif ($purchasePrice === null) {
            $reason = 'no_purchase_price';
        }

        $calculable = $reason === null;
        $margin = $calculable ? ($salePrice - $purchasePrice - $expensesTotal) : null;

        return $this->shape(
            found: true,
            calculable: $calculable,
            reason: $reason,
            salePrice: $salePrice,
            purchasePrice: $purchasePrice,
            expensesTotal: $expensesTotal,
            expensesCount: $expensesCount,
            margin: $margin,
        );
    }

    private function shape(
        bool $found,
        bool $calculable = false,
        ?string $reason = null,
        ?float $salePrice = null,
        ?float $purchasePrice = null,
        float $expensesTotal = 0.0,
        int $expensesCount = 0,
        ?float $margin = null,
    ): array {
        return [
            'found'          => $found,
            'calculable'     => $calculable,
            'reason'         => $reason,
            'sale_price'     => $salePrice,
            'purchase_price' => $purchasePrice,
            'expenses_total' => $expensesTotal,
            'expenses_count' => $expensesCount,
            'margin'         => $margin,
        ];
    }
}
