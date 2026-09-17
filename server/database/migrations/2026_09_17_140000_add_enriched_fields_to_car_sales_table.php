<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Registo de venda enriquecido (Fase 1: só os campos). A base
 * (car_sales com customer_id + buyer_*) já existia; faltam estes campos que a
 * Matilde pediu. Tudo NULLABLE (vendas antigas não têm estes dados).
 *
 * Booleans tri-estado (nullable): null = desconhecido (venda antiga), true/false
 * = registado. `lead_id` fica pronto para a Fase 2 (fluxo vender→mover-lead) —
 * por agora é só plumbing; a origem da venda lê-se da lead quando ligada.
 *
 * ADD COLUMN puro (aditivo, reversível) — não reconstrói a tabela, seguro em
 * MySQL e sqlite (cautela 2026-06-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_sales', function (Blueprint $table) {
            // Ligação à lead (Fase 2 popula; aqui só a coluna, para herdar a origem).
            $table->unsignedBigInteger('lead_id')->nullable()->after('customer_id');
            $table->index('lead_id');

            // Valor anunciado vs final (sale_price = final, já existe).
            $table->decimal('advertised_price', 10, 2)->nullable()->after('sale_price');
            // Desconto + ofertas incluídas.
            $table->decimal('discount_amount', 10, 2)->nullable()->after('advertised_price');
            $table->text('offers')->nullable()->after('discount_amount');

            // Financiamento.
            $table->boolean('has_financing')->nullable()->after('offers');
            $table->string('financing_entity')->nullable()->after('has_financing');
            $table->decimal('financed_amount', 10, 2)->nullable()->after('financing_entity');

            // Retoma (só REGISTO, não avaliação).
            $table->boolean('has_trade_in')->nullable()->after('financed_amount');
            $table->string('trade_in_vehicle')->nullable()->after('has_trade_in');
            $table->decimal('trade_in_value', 10, 2)->nullable()->after('trade_in_vehicle');

            // Perfil da compra.
            $table->boolean('first_motorhome')->nullable()->after('trade_in_value');
            $table->string('previous_vehicle')->nullable()->after('first_motorhome');
        });
    }

    public function down(): void
    {
        Schema::table('car_sales', function (Blueprint $table) {
            $table->dropIndex(['lead_id']);
            $table->dropColumn([
                'lead_id', 'advertised_price', 'discount_amount', 'offers',
                'has_financing', 'financing_entity', 'financed_amount',
                'has_trade_in', 'trade_in_vehicle', 'trade_in_value',
                'first_motorhome', 'previous_vehicle',
            ]);
        });
    }
};
