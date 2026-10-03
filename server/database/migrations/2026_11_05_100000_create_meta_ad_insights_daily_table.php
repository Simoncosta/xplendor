<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Meta Ads: ingestão ao NÍVEL DO ANÚNCIO.
 *
 * O que cada anúncio gastou por dia (act_{id}/insights?level=ad&time_increment=1).
 * Serve a atribuição de gasto por viatura pela tag [id:N] no nome do anúncio.
 * Aditiva: a ingestão por conta (meta_account_insights_daily) continua intacta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_insights_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 50);            // sem prefixo act_
            $table->date('date');
            $table->string('campaign_id', 50);
            $table->string('adset_id', 50)->nullable();
            $table->string('ad_id', 50);
            $table->string('ad_name')->nullable();
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'date', 'ad_id'], 'meta_ad_ins_unique');
            $table->index(['company_id', 'account_id', 'ad_id'], 'meta_ad_ins_company_ad_idx');
            $table->index(['company_id', 'campaign_id'], 'meta_ad_ins_company_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights_daily');
    }
};
