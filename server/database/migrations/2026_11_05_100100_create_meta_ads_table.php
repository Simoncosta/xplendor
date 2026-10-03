<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Catálogo dos anúncios Meta de cada empresa.
 *
 * Uma linha por anúncio: nome actual, campanha/conjunto, effective_status (de
 * act_{id}/ads, para a regra "anúncio de viatura vendida ainda ativo") e o
 * resultado da leitura da tag [id:N] no nome:
 *   tag_status: untagged | matched | split | invalid
 *   tag_car_ids     → viaturas a quem o gasto é atribuído (válidas para a empresa);
 *   tag_invalid_ids → IDs da tag que não pertencem à empresa (aviso de qualidade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 50);
            $table->string('ad_id', 50);
            $table->string('ad_name')->nullable();
            $table->string('campaign_id', 50)->nullable();
            $table->string('adset_id', 50)->nullable();
            $table->string('effective_status', 40)->nullable();
            $table->timestamp('status_synced_at')->nullable();
            $table->string('tag_status', 10)->default('untagged');
            $table->json('tag_car_ids')->nullable();
            $table->json('tag_invalid_ids')->nullable();
            $table->timestamp('tag_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'ad_id'], 'meta_ads_unique');
            $table->index(['company_id', 'tag_status'], 'meta_ads_company_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads');
    }
};
