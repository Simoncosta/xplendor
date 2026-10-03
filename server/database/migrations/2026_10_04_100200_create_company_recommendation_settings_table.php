<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Motor de recomendações: configuração POR EMPRESA de cada regra
 * (ligada/desligada + parâmetros, ex.: {"max_days": 45}). Sem linha = regra
 * ligada com os parâmetros por omissão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_recommendation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key', 80);
            $table->boolean('enabled')->default(true);
            $table->json('params')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'rule_key'], 'company_reco_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_recommendation_settings');
    }
};
