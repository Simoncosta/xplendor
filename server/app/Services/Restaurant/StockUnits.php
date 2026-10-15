<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\ProductUnitConversion;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Fatia 7b: as unidades em que se conta ou regista um artigo. Só duas fontes: a unidade de
 * stock do artigo (fator 1) e as conversões confirmadas na XPLENDOR (product_unit_conversions, Fatia
 * 1b). NUNCA os fatores das unidades do PingWin (há embalagens com unit_value 1, como a Caixa 6).
 * O fator vem sempre do servidor; o que o ecrã envia é só a quantidade e o nome da unidade.
 */
final class StockUnits
{
    /** @var array<string, array> */
    private array $memo = [];

    public function __construct(private int $companyId) {}

    /**
     * @return array{stock_unit:string, stock_unit_id:?string, units:list<array{unit:string, factor:float, source:string}>}
     */
    public function for(string $productId): array
    {
        if (isset($this->memo[$productId])) {
            return $this->memo[$productId];
        }
        $item = PingwinStockItem::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->first();
        $row = PingwinStock::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->first();
        $stockUnit = (string) ($item?->stock_unit ?: $row?->unit_name ?: 'Unidade');
        $unitValue = (float) ($row?->unit_value ?: 1.0);
        $units = [['unit' => $stockUnit, 'factor' => 1.0, 'source' => 'stock']];
        $seen = [mb_strtolower($stockUnit) => true];
        $convs = ProductUnitConversion::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)
            ->where('source', 'confirmado')->orderByRaw("CASE WHEN supplier_nif = '' OR supplier_nif IS NULL THEN 0 ELSE 1 END")->get();
        foreach ($convs as $c) {
            $label = trim((string) ($c->unit_label ?: $c->unit_key));
            if ($label === '' || isset($seen[mb_strtolower($label)]) || (float) $c->factor_to_base <= 0) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            // factor_to_base é em relação à unidade base; a unidade de stock vale unit_value bases.
            $units[] = ['unit' => $label, 'factor' => round((float) $c->factor_to_base / ($unitValue > 0 ? $unitValue : 1.0), 6), 'source' => 'confirmado'];
        }

        return $this->memo[$productId] = ['stock_unit' => $stockUnit, 'stock_unit_id' => $item?->stock_unit_id, 'units' => $units];
    }

    /** O fator de uma unidade (pelo nome), ou null se não for a de stock nem uma conversão confirmada. */
    public function factor(string $productId, string $unit): ?float
    {
        foreach ($this->for($productId)['units'] as $u) {
            if (mb_strtolower($u['unit']) === mb_strtolower(trim($unit))) {
                return $u['factor'];
            }
        }

        return null;
    }

    /**
     * Normaliza as entradas [{qty, unit}] e soma na unidade de stock. Lista vazia = não contado (null);
     * [{qty: 0}] = zero contado. Uma unidade sem fator confirmado é recusada (o ecrã abre o
     * ConfirmFactorModal antes).
     *
     * @param  list<array{qty:mixed, unit?:?string}>  $entries
     * @return array{entries:list<array{qty:float, unit:string, factor:float}>, qty_base:?float}
     */
    public function sum(string $productId, array $entries): array
    {
        $out = [];
        $total = 0.0;
        $stock = $this->for($productId)['stock_unit'];
        foreach ($entries as $i => $e) {
            $qty = $e['qty'] ?? null;
            if ($qty === null || $qty === '') {
                continue;
            }
            if (! is_numeric(str_replace(',', '.', (string) $qty)) || (float) str_replace(',', '.', (string) $qty) < 0) {
                throw ValidationException::withMessages(["entries.{$i}.qty" => ['A quantidade tem de ser um número maior ou igual a 0.']]);
            }
            $unit = trim((string) ($e['unit'] ?? '')) ?: $stock;
            $factor = $this->factor($productId, $unit);
            if ($factor === null) {
                throw ValidationException::withMessages(["entries.{$i}.unit" => ["A unidade «{$unit}» não tem uma conversão confirmada. Confirme primeiro " . \App\Audit\Fmt::howMany($stock) . " tem cada «{$unit}»."]]);
            }
            $q = (float) str_replace(',', '.', (string) $qty);
            $out[] = ['qty' => $q, 'unit' => $unit, 'factor' => $factor];
            $total += $q * $factor;
        }

        return ['entries' => $out, 'qty_base' => $out ? round($total, 5) : null];
    }
}
