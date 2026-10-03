<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visões 1+2 do Dashboard (2026-06-25) — stock visível por marca e tipo.
 *
 * Wraps o array devolvido por `DashboardRepository::getStockBreakdown()`.
 * Não usa Models — o repository já faz `GROUP BY` e devolve linhas
 * agregadas. A Resource garante o shape para o frontend (sec 8 do
 * CLAUDE.md — "nunca devolver Models directamente").
 *
 * Frontend (`labelOf(VEHICLE_TYPE_LABELS)`) traduz `type` para pt-PT —
 * aqui sai cru (`car|motorcycle|motorhome|caravan`) por simetria com os
 * outros enums emitidos (sec 12 + B1 do 1.11.0).
 */
class StockBreakdownResource extends JsonResource
{
    /** Disable wrapping — colecionamos arrays directamente. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var array{by_brand: array, by_type: array} $data */
        $data = $this->resource;

        return [
            // Âmbito "Ativos" (retrocompatível — o que sempre foi devolvido).
            'by_brand' => self::brands($data['by_brand'] ?? []),
            'by_type'  => self::types($data['by_type'] ?? []),
            // Filtro do dashboard [Todos | Ativos | Vendidos] — tudo num só pedido,
            // o frontend troca de âmbito sem novas chamadas.
            'scopes' => array_map(static fn (array $scope): array => [
                'by_brand' => self::brands($scope['by_brand'] ?? []),
                'by_type'  => self::types($scope['by_type'] ?? []),
            ], $data['scopes'] ?? []),
        ];
    }

    private static function brands(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'name'  => (string) $row['name'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    private static function types(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'type'  => (string) $row['type'],
            'count' => (int) $row['count'],
        ], $rows);
    }
}
