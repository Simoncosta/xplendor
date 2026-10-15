<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Stock;

use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Audit\Rules\Restaurant\RestaurantRule;
use App\Models\PingwinStock;
use App\Models\PingwinWarehouse;

/**
 * Fatia 7a · Base das regras do stock: o espelho do stock (pingwin_stock) e o consumo teórico. Só lê
 * o espelho; nunca chama o PingWin.
 */
abstract class StockRule extends RestaurantRule
{
    /** @var array<string, PingwinWarehouse>|null */
    private ?array $warehouses = null;

    protected function warehouse(int $companyId, string $id): ?PingwinWarehouse
    {
        $this->warehouses ??= PingwinWarehouse::where('company_id', $companyId)->get()->keyBy(fn ($w) => (string) $w->pingwin_id)->all();

        return $this->warehouses[$id] ?? null;
    }

    protected function warehouseName(int $companyId, string $id): string
    {
        return (string) ($this->warehouse($companyId, $id)?->description ?? $id);
    }

    /** "1 896 unidades", "2,85 litros" (a unidade de stock no plural certo). */
    protected static function qty(float $q, ?string $unit, int $decimals = 3): string
    {
        return Fmt::units($q, $unit ?: 'unidade', $decimals);
    }

    /** A unidade de stock de um artigo (a das linhas do espelho). */
    protected static function unitOf(array $lines): ?string
    {
        foreach ($lines as $l) {
            if ($l->unit_name) {
                return (string) $l->unit_name;
            }
        }

        return null;
    }

    /** Consumo médio diário na unidade de stock (÷ unit_value; com 1 fica igual). */
    protected static function perDayInStockUnit(float $perDay, array $lines): float
    {
        $uv = $lines[0]->unit_value ?? null;

        return $uv !== null && $uv > 0 ? $perDay / $uv : $perDay;
    }

    /** O link do separador "Stock" do artigo. */
    protected static function stockUrl(string $pid): string
    {
        return "/restauracao/artigos/{$pid}?tab=stock";
    }

    /** As linhas da tabela de evidência, uma por armazém. */
    protected function stockTable(int $companyId, array $lines): array
    {
        return [
            'title' => 'Stock por armazém (PingWin)',
            'columns' => [
                ['key' => 'warehouse', 'label' => 'Armazém'], ['key' => 'qnt', 'label' => 'Quantidade', 'align' => 'end'],
                ['key' => 'pcm', 'label' => 'Custo médio', 'align' => 'end', 'format' => 'eur4'],
                ['key' => 'value', 'label' => 'Valor', 'align' => 'end', 'format' => 'eur'],
                ['key' => 'valorization', 'label' => 'Última valorização', 'format' => 'date'],
            ],
            'rows' => array_map(fn (PingwinStock $l) => [
                'warehouse' => $this->warehouseName($companyId, (string) $l->warehouse_pingwin_id),
                'qnt' => Fmt::qty($l->qnt), 'pcm' => $l->pcm, 'value' => $l->pcm !== null ? round($l->qnt * $l->pcm, 2) : null,
                'valorization' => $l->valorization_date?->toDateString(),
            ], $lines),
        ];
    }

    /** "a, b e c". */
    protected static function join(array $parts): string
    {
        if (count($parts) <= 1) {
            return (string) ($parts[0] ?? '');
        }
        $last = array_pop($parts);

        return implode(', ', $parts) . ' e ' . $last;
    }

    protected static function data(\App\Audit\AuditScope $scope): RestaurantAuditData
    {
        return RestaurantAuditData::for($scope);
    }
}
