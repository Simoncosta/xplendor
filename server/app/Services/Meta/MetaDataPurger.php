<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\CompanyIntegration;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Apaga TODOS os dados recebidos da Meta de UMA empresa (opção "Apagar todos os
 * dados da Meta" ao desligar a integração, ou pedido de eliminação por email).
 *
 * Apaga: as tabelas meta_*, os derivados (métricas por viatura, campanhas
 * associadas às viaturas, linhas pagas vindas da Meta, despesas projetadas) e a
 * própria integração (conta de anúncios e estado das sincronizações).
 * Mantém as vendas: nas atribuições e no histórico de vendas só se anulam os IDs
 * de campanha, conjunto e anúncio; a atribuição passa a "sem campanha" ('none').
 * Não toca no registo de visitas dos sites (car_ad_attributions), que é medição
 * própria do site do cliente e não vem da API da Meta.
 *
 * ⚠️ Em MariaDB, car_sale_attributions.sold_at e car_sales_learning.sold_at são
 * TIMESTAMP com ON UPDATE CURRENT_TIMESTAMP: todas as atualizações repõem
 * sold_at = sold_at, para não reescrever a data da venda.
 */
class MetaDataPurger
{
    /** Palavra que o cliente escreve para confirmar a eliminação. */
    public const CONFIRMATION = 'APAGAR';

    /** Tabelas com dados da Meta que se apagam por inteiro (por empresa). */
    private const TABLES = [
        'meta_account_insights_daily',
        'meta_ad_insights_daily',
        'meta_ads',
        'meta_ad_car_spend_daily',
        'meta_custom_audiences',
        'meta_audience_insights',
    ];

    /**
     * @return array<string, int> linhas apagadas ou anuladas, por tabela
     */
    public function purge(int $companyId): array
    {
        return DB::transaction(function () use ($companyId) {
            $counts = [];

            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->where('company_id', $companyId)->delete();
            }

            // Métricas por viatura das campanhas Meta associadas e, depois, as associações.
            $mappings = DB::table('car_ad_campaigns')->where('company_id', $companyId)->where('platform', 'meta')->select('id');
            $counts['campaign_car_metrics_daily'] = DB::table('campaign_car_metrics_daily')
                ->where('company_id', $companyId)->whereIn('mapping_id', $mappings)->delete();
            $counts['car_ad_campaigns'] = DB::table('car_ad_campaigns')
                ->where('company_id', $companyId)->where('platform', 'meta')->delete();

            $counts['car_performance_metrics'] = DB::table('car_performance_metrics')
                ->where('company_id', $companyId)->where('channel', 'paid')->where('data_source', 'meta_ads')->delete();

            $counts['expenses'] = DB::table('expenses')
                ->where('company_id', $companyId)->where('source', Expense::SOURCE_META_ADS)->delete();

            $counts['car_sale_attributions'] = DB::table('car_sale_attributions')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->where('attributed_platform', 'meta')
                    ->orWhereNotNull('attributed_campaign_id')
                    ->orWhereNotNull('attributed_adset_id')
                    ->orWhereNotNull('attributed_ad_id'))
                ->update([
                    'attributed_platform' => null,
                    'attributed_campaign_id' => null,
                    'attributed_adset_id' => null,
                    'attributed_ad_id' => null,
                    'match_type' => 'none',
                    'confidence_score' => 0,
                    'confidence_reason' => 'Dados da Meta apagados a pedido do cliente.',
                    'sold_at' => DB::raw('sold_at'),
                    'updated_at' => now(),
                ]);

            $counts['car_sales_learning'] = DB::table('car_sales_learning')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->whereNotNull('campaign_ids')->orWhereNotNull('ad_ids')->orWhereNotNull('adset_ids'))
                ->update([
                    'campaign_ids' => null,
                    'ad_ids' => null,
                    'adset_ids' => null,
                    'sold_at' => DB::raw('sold_at'),
                    'updated_at' => now(),
                ]);

            $counts['company_integrations'] = CompanyIntegration::where('company_id', $companyId)
                ->where('platform', 'meta')->delete();

            return $counts;
        });
    }
}
