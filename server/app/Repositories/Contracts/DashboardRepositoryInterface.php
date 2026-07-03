<?php

namespace App\Repositories\Contracts;

interface DashboardRepositoryInterface extends BaseRepositoryInterface
{
    public function getSummary(int $companyId): array;
    public function getTopInterestCars(int $companyId, int $limit = 5);
    public function getLowLeadCars(int $companyId, int $limit = 5);
    public function getOldestCars(int $companyId, int $limit = 5);
    public function getBestInterestRateCars(int $companyId, int $limit = 5);
    public function getCapitalSummary(int $companyId): array;
    public function getHighestStuckCapitalCars(int $companyId, int $limit = 5);
    public function getUrgentActionCars(int $companyId, int $limit = 5);
    public function getHighDemandOpportunityCars(int $companyId, int $limit = 5);
    public function getHighInterestLowConversionCars(int $companyId, int $limit = 5);
    public function getMarketingPerformance(int $companyId): array;
    public function getCompanyInsights(int $companyId): array;
    public function groupCarsByPersona(int $companyId): array;

    /**
     * Visões 1+2 do Dashboard (2026-06-25) — contagem de stock visível por
     * marca e por tipo de veículo. "Stock visível" = mesmo conjunto que
     * `StockPromotionRepository::VISIBLE_STATUSES` (`active`, `available_soon`,
     * `reserved`) — exclui `draft` (incompletos), `sold` (já saiu) e `inactive`.
     *
     * @return array{by_brand: array<int, array{name: string, count: int}>, by_type: array<int, array{type: string, count: int}>}
     */
    public function getStockBreakdown(int $companyId): array;

    /**
     * Visão 3 do Dashboard (2026-06-25) — **FATURAÇÃO** (valor das vendas)
     * por período. **NÃO É LUCRO** — não há `purchase_price` em prod; sem o
     * preço de compra, lucro real só existirá quando essa faixa entrar (sec
     * 15 da auditoria desta sessão).
     *
     * @param string $fromDate `Y-m-d`
     * @param string $toDate   `Y-m-d`
     * @param string $granularity `month`|`year` — bucket do gráfico
     * @return array{
     *     total_revenue: float,
     *     sales_count: int,
     *     sales_without_value_count: int,
     *     buckets: array<int, array{period: string, revenue: float, sales_count: int}>,
     *     range: array{from: string, to: string, granularity: string}
     * }
     */
    public function getSalesRevenue(int $companyId, string $fromDate, string $toDate, string $granularity = 'month'): array;
}
