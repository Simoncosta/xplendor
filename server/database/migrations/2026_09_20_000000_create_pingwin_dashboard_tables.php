<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Estrutura de dados do dashboard de restauração (do spike Storefire).
 *  · pingwin_locations   — lojas descobertas (fetch_stores).
 *  · pingwin_daily_sales — vendas por loja/dia (CÊNTIMOS inteiros, nunca float).
 *  · pingwin_sync_runs   — que dias foram sincronizados (distingue "0€ real" de
 *                          "ainda não sincronizado" → portão de honestidade).
 * Aditiva e reversível (cautelas 2026-06-09). Dinheiro em bigint (cêntimos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('winrest_store_id');      // id da loja no PingWin (fetch_stores)
            $table->string('winrest_name')->nullable();
            $table->string('display_name')->nullable();
            $table->date('opened_on')->nullable();   // não conta em dias anteriores à abertura
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'winrest_store_id']);
        });

        Schema::create('pingwin_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            // Dinheiro em CÊNTIMOS inteiros (€ → int(round(x*100))). Nunca float.
            $table->bigInteger('gross_cents')->default(0);        // vendas brutas
            $table->bigInteger('credit_notes_cents')->default(0); // notas de crédito
            $table->bigInteger('discounts_cents')->default(0);    // descontos
            $table->bigInteger('net_cents')->default(0);          // vendas líquidas
            $table->bigInteger('tax_cents')->default(0);          // impostos
            $table->bigInteger('invoiced_cents')->default(0);     // valor faturado (c/IVA)
            $table->unsignedInteger('tickets_count')->default(0);
            $table->unsignedInteger('covers_count')->default(0);  // nº pessoas
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date']); // UPSERT idempotente
            $table->index(['company_id', 'business_date']);
        });

        Schema::create('pingwin_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status')->default('success'); // success | error
            $table->unsignedInteger('locations_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'business_date']); // um registo por dia sincronizado
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_sync_runs');
        Schema::dropIfExists('pingwin_daily_sales');
        Schema::dropIfExists('pingwin_locations');
    }
};
