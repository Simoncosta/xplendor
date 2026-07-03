<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visão 3 do Dashboard (2026-06-25) — wrapper do retorno de
 * `DashboardRepository::getSalesRevenue`.
 *
 * NÃO É LUCRO — é faturação. Manter este rótulo é da responsabilidade
 * do FE; o backend emite cru com os contadores honestos de "vendas sem
 * valor registado" para o FE poder sinalizar.
 */
class SalesRevenueResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var array{
         *   total_revenue: float|int,
         *   sales_count: int,
         *   sales_without_value_count: int,
         *   buckets: array<int, array{period: string, revenue: float|int, sales_count: int}>,
         *   range: array{from: string, to: string, granularity: string}
         * } $data */
        $data = $this->resource;

        return [
            'total_revenue'             => (float) ($data['total_revenue'] ?? 0),
            'sales_count'               => (int) ($data['sales_count'] ?? 0),
            'sales_without_value_count' => (int) ($data['sales_without_value_count'] ?? 0),
            'buckets' => array_map(static fn (array $row): array => [
                'period'      => (string) $row['period'],
                'revenue'     => (float) $row['revenue'],
                'sales_count' => (int) $row['sales_count'],
            ], $data['buckets'] ?? []),
            'range' => [
                'from'        => (string) ($data['range']['from'] ?? ''),
                'to'          => (string) ($data['range']['to'] ?? ''),
                'granularity' => (string) ($data['range']['granularity'] ?? 'month'),
            ],
        ];
    }
}
