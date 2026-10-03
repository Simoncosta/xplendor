<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Meta Ads (ingestão por conta): profundidade do último backfill
 * concluído, em meses. O backfill passou de 90 dias para 13 meses (para haver o
 * MESMO MÊS DO ANO ANTERIOR no dashboard de restauração). Com esta coluna, o
 * comando de arranque e o diário sabem que as integrações com o backfill antigo
 * (null = 90 dias) precisam de um novo backfill — sem apagar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->unsignedTinyInteger('insights_backfill_months')->nullable()->after('insights_backfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('insights_backfill_months');
        });
    }
};
