<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Gasto Meta atribuído a cada viatura (pela tag [id:N] do anúncio).
 *
 * Uma linha por (dia, anúncio, viatura da tag). [id:89,113] gera duas linhas com
 * share 0.5 (allocation_type 'split'); a soma das partes é sempre o gasto do
 * anúncio nesse dia (o resto do arredondamento vai para a última parte).
 *
 * SEM chave estrangeira para cars (de propósito): apagar uma viatura não pode
 * apagar o histórico. tagged_car_id guarda sempre o N da tag; car_id fica null
 * quando a viatura foi removida ("viatura removida"). O "pós-venda" calcula-se na
 * leitura contra cars.sold_at (a data de venda pode ser corrigida depois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_car_spend_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 50);
            $table->date('date');
            $table->string('campaign_id', 50);
            $table->string('adset_id', 50)->nullable();
            $table->string('ad_id', 50);
            $table->unsignedBigInteger('car_id')->nullable();        // null = viatura removida
            $table->unsignedBigInteger('tagged_car_id');             // o N da tag, para sempre
            $table->decimal('share', 8, 6)->default(1);
            $table->decimal('spend_allocated', 14, 4)->default(0);
            $table->decimal('impressions_allocated', 16, 4)->default(0);
            $table->decimal('clicks_allocated', 16, 4)->default(0);
            $table->string('allocation_type', 10)->default('single'); // single | split
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'date', 'ad_id', 'tagged_car_id'], 'meta_ad_car_spend_unique');
            $table->index(['company_id', 'car_id', 'date'], 'meta_ad_car_spend_company_car_date_idx');
            $table->index(['company_id', 'tagged_car_id'], 'meta_ad_car_spend_company_tagged_idx');
            $table->index(['company_id', 'date'], 'meta_ad_car_spend_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_car_spend_daily');
    }
};
