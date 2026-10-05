<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Uma atribuição por VENDA (car_sale_attributions).
 *
 * A car_sales só permite uma venda por viatura (unique car_id), por isso a chave
 * da atribuição passa a ser a viatura (company_id + car_id), com a ligação à venda
 * registada em car_sale_id. Antes a chave era a HORA da venda (company, car,
 * sold_at): marcar a venda duas vezes com horas diferentes criava duas atribuições
 * para a mesma venda.
 *
 * A chave única só se pode criar sem duplicados. A migração tenta; se houver
 * duplicados, fica para o comando sales:dedupe-attributions --write.
 */
final class SaleAttributionUniqueness
{
    public const NEW_INDEX = 'car_sale_attr_car_unique';
    public const OLD_INDEX = 'car_sale_attr_sale_unique';

    /**
     * Vendas SEM campanha gravadas como 'fallback' passam a 'none' (tipo próprio).
     * Reconhecem-se por não terem campanha, conjunto nem anúncio: o recurso ao
     * mapeamento manual traz sempre a campanha. Devolve quantas mudaram.
     */
    public static function relabelUnattributed(): int
    {
        return DB::table('car_sale_attributions')
            ->where('match_type', 'fallback')
            ->whereNull('attributed_campaign_id')
            ->whereNull('attributed_adset_id')
            ->whereNull('attributed_ad_id')
            // sold_at = sold_at: em MariaDB a coluna tem ON UPDATE CURRENT_TIMESTAMP.
            ->update(['match_type' => 'none', 'sold_at' => DB::raw('sold_at')]);
    }

    /** Viaturas com mais do que uma atribuição. */
    public static function duplicateCount(): int
    {
        return DB::table('car_sale_attributions')
            ->select('company_id', 'car_id')
            ->groupBy('company_id', 'car_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    public static function hasNewIndex(): bool
    {
        return collect(Schema::getIndexes('car_sale_attributions'))->contains(fn ($i) => $i['name'] === self::NEW_INDEX);
    }

    /**
     * Cria a chave única (company_id, car_id), se ainda não existir e não houver
     * duplicados. Devolve 'created', 'exists' ou 'duplicates'.
     */
    public static function ensure(): string
    {
        if (self::hasNewIndex()) {
            return 'exists';
        }
        if (self::duplicateCount() > 0) {
            return 'duplicates';
        }

        $hasOld = collect(Schema::getIndexes('car_sale_attributions'))->contains(fn ($i) => $i['name'] === self::OLD_INDEX);
        Schema::table('car_sale_attributions', function ($table) use ($hasOld) {
            if ($hasOld) {
                $table->dropUnique(self::OLD_INDEX);
                // A pesquisa por empresa + viatura + data continua indexada.
                $table->index(['company_id', 'car_id', 'sold_at'], 'car_sale_attr_sale_idx');
            }
            $table->unique(['company_id', 'car_id'], self::NEW_INDEX);
        });

        return 'created';
    }
}
