<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualidade de dados das atribuições de vendas (aditiva):
 *   · car_sale_id → a venda registada (car_sales) a que a atribuição pertence;
 *   · match_type 'none' → venda SEM campanha. Antes ficava como 'fallback' (o mesmo
 *     tipo do recurso ao mapeamento manual) e misturava-se nas estatísticas. As
 *     existentes são reconhecíveis: 'fallback' sem campanha, conjunto nem anúncio
 *     (o recurso ao mapeamento traz sempre a campanha).
 *
 * ⚠️ Em MariaDB, car_sale_attributions.sold_at é TIMESTAMP com ON UPDATE
 * CURRENT_TIMESTAMP: uma atualização que não indique a data reescreve a data da
 * venda para "agora". Todas as atualizações aqui repõem sold_at = sold_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('car_sale_attributions', 'car_sale_id')) {
            Schema::table('car_sale_attributions', function (Blueprint $table) {
                $table->unsignedBigInteger('car_sale_id')->nullable()->after('car_id');
                $table->index('car_sale_id', 'car_sale_attr_car_sale_idx');
            });
        }

        // Ligar às vendas registadas que já existem (uma por viatura), sem tocar na data.
        foreach (DB::table('car_sales')->get(['id', 'car_id']) as $sale) {
            DB::table('car_sale_attributions')->where('car_id', $sale->car_id)->whereNull('car_sale_id')
                ->update(['car_sale_id' => $sale->id, 'sold_at' => DB::raw('sold_at')]);
        }

        \App\Support\SaleAttributionUniqueness::relabelUnattributed();
    }

    public function down(): void
    {
        DB::table('car_sale_attributions')->where('match_type', 'none')->update(['match_type' => 'fallback']);

        Schema::table('car_sale_attributions', function (Blueprint $table) {
            $table->dropIndex('car_sale_attr_car_sale_idx');
            $table->dropColumn('car_sale_id');
        });
    }
};
