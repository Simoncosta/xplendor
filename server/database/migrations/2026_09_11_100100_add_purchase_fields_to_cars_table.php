<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Fase 1a — dados internos da compra da viatura.
 *
 *  - purchase_price: preço de compra (custo de aquisição). CONFIDENCIAL — nunca
 *    exposto no CarPublicResource (allow-list). Fundação para margem/lucro (Fase 2).
 *  - vat_regime: regime de IVA da compra (margem/normal/isento). Guardado como
 *    string (não enum de BD) para ser extensível — a taxonomia final será
 *    validada com contabilista na Fase 2; aqui só se CAPTURA, não se calcula.
 *
 * Nota: já existe um `purchase_price` órfão em `car_performance_metrics` (tabela
 * de métricas de marketing, sempre vazio, sem UI). NÃO é tocado — `cars.purchase_price`
 * passa a ser a fonte canónica.
 *
 * Aditiva, colunas nullable, reversível. Retro-compat: viaturas existentes ficam
 * com ambos NULL (dado histórico não capturado — normal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->decimal('purchase_price', 12, 2)
                ->nullable()
                ->after('price_net')
                ->comment('Preço de compra (custo de aquisição) — CONFIDENCIAL, uso interno');

            $table->string('vat_regime', 20)
                ->nullable()
                ->after('purchase_price')
                ->comment('Regime de IVA da compra: margem | normal | isento (extensível)');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'vat_regime']);
        });
    }
};
