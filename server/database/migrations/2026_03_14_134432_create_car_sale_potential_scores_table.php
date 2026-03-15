<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('car_sale_potential_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')
                ->constrained('cars')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // ── Score ─────────────────────────────────────────────────
            $table->unsignedTinyInteger('score');          // 0–100
            $table->enum('classification', ['hot', 'warm', 'cold']);
            $table->json('score_breakdown');               // pontuação por fator

            // ── Snapshots no momento do cálculo ───────────────────────
            $table->decimal('price_vs_market', 5, 2)->nullable();
            $table->unsignedInteger('days_in_stock_at_calc');
            $table->timestamp('calculated_at');

            // ── Auditoria ─────────────────────────────────────────────
            $table->enum('triggered_by', [
                'scheduled',
                'price_change',
                'status_change',
                'lead_created',
                'image_added',
                'manual',
            ]);

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────
            $table->index(['car_id', 'company_id', 'calculated_at']);
            $table->index(['company_id', 'score']);
            $table->index(['company_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_sale_potential_scores');
    }
};
