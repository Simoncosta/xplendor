<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatório A — car_promotion_priorities
 *
 * Marcação persistente de "esta viatura é prioridade de promoção" feita
 * pelo Stand (Matilde + sócio) ou pela Agência (root). É a Camada 1 da
 * faixa "Priorização de promoção" (sec 15 do CLAUDE.md).
 *
 * Cada toggle ON cria uma nova row. Toggle OFF faz UPDATE is_active=false
 * + carimba unmarked_*. Não há partial unique (MariaDB não suporta WHERE em
 * UNIQUE) — a unicidade do "estado activo" é garantida em código pelo
 * service `StockPromotionService::markForPromotion()` numa transaction.
 *
 * Camada 2 (Agência define orçamento + integra Meta Ads) **NÃO** entra
 * agora — quando entrar, será migration separada que adiciona
 * `monthly_budget INT NULL` a esta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_promotion_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('marked_at');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('unmarked_at')->nullable();
            $table->foreignId('unmarked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // Queries típicas: lista de prioridades ACTIVAS por company.
            $table->index(['company_id', 'is_active']);
            // Lookup do estado actual de uma viatura específica.
            $table->index(['car_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_promotion_priorities');
    }
};
