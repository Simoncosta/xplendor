<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribuição de vendas a anúncios com tag: o match_type era um ENUM fechado
 * (direct_ad, adset_match, campaign_match, fallback). Passa a STRING para os tipos
 * novos: cross_car_ad (o anúncio de outra viatura trouxe o cliente) e
 * tag_fallback (anúncio com a tag desta viatura que gastou antes da venda).
 * Só ALARGA a coluna: os valores existentes ficam iguais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_sale_attributions', function (Blueprint $table) {
            $table->string('match_type', 30)->default('fallback')->change();
        });
    }

    public function down(): void
    {
        Schema::table('car_sale_attributions', function (Blueprint $table) {
            $table->enum('match_type', ['direct_ad', 'adset_match', 'campaign_match', 'fallback'])
                ->default('fallback')->change();
        });
    }
};
