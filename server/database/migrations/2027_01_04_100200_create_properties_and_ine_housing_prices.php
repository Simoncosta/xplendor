<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola Master, R3: a carteira do consultor imobiliário e a referência oficial do INE.
 *  · properties: só os campos que a Bússola usa; sem morada exata nem dados de proprietários,
 *    compradores ou inquilinos. O interesse são dois contadores atualizados à mão.
 *  · ine_housing_prices: o valor mediano das vendas por m² (indicador 0012234, últimos 12 meses,
 *    categoria Total) por freguesia e por concelho, ligado pelo código oficial (DICOFRE e DICO).
 * Tabelas novas; nada do que existe muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->string('title', 120);
            $table->string('kind', 20);            // apartamento | moradia | terreno | loja | escritorio | armazem | outro
            $table->string('typology', 4)->nullable(); // T0 a T6
            $table->unsignedSmallInteger('area_m2')->nullable();
            $table->foreignId('parish_id')->nullable()->constrained('parishes')->nullOnDelete();
            $table->string('listing', 12);         // venda | arrendamento
            $table->unsignedInteger('price');      // euros (venda) ou euros por mês (arrendamento)
            $table->string('energy_class', 12)->nullable(); // A+, A, B, B-, C, D, E, F, isento, em_processo
            $table->string('status', 12)->default('disponivel'); // disponivel | reservado | fechado
            $table->date('listed_at');
            $table->date('closed_at')->nullable();
            $table->unsignedSmallInteger('contacts_30')->default(0);
            $table->unsignedSmallInteger('visits_30')->default(0);
            $table->date('interest_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'parish_id']);
        });
        Schema::create('ine_housing_prices', function (Blueprint $table) {
            $table->id();
            $table->string('geo_level', 10);       // freguesia | municipio
            $table->string('geo_code', 9);         // NUTS III + DICOFRE (9) ou NUTS III + DICO (7)
            $table->string('geo_name', 191);
            $table->string('official_code', 6);    // DICOFRE (6) ou DICO (4)
            $table->string('period_code', 10);     // S5A20261
            $table->string('period_label', 60);    // 1.º Trimestre de 2026
            $table->unsignedInteger('median_m2')->nullable();
            $table->boolean('has_value')->default(false);
            $table->date('ine_updated_on')->nullable();
            $table->dateTime('fetched_at');
            $table->timestamps();
            $table->unique(['geo_code', 'period_code']);
            $table->index(['official_code', 'period_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ine_housing_prices');
        Schema::dropIfExists('properties');
    }
};
