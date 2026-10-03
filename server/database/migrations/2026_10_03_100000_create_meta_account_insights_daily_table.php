<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Meta Ads: ingestão ao NÍVEL DA CONTA (todas as verticais).
 *
 * O pipeline antigo (campaign_car_metrics_daily) só guarda campanhas mapeadas a
 * um CARRO — uma empresa sem carros (ex.: restauração) ficava a zero. Esta tabela
 * guarda o que a conta de anúncios gastou, por campanha e por dia, vindo de
 * act_{account_id}/insights?level=campaign&time_increment=1. Aditiva: o pipeline
 * por carro continua intacto (serve a atribuição de vendas dos stands).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_account_insights_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 50);            // sem prefixo act_
            $table->date('date');
            $table->string('campaign_id', 50);
            $table->string('campaign_name')->nullable();
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'date', 'campaign_id'], 'meta_acct_ins_unique');
            $table->index(['company_id', 'date'], 'meta_acct_ins_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_account_insights_daily');
    }
};
