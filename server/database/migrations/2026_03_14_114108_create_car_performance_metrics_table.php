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
        Schema::create('car_performance_metrics', function (Blueprint $table) {
            $table->id();

            // ── Identificação ─────────────────────────────────────────
            $table->foreignId('car_id')
                ->constrained('cars')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->enum('channel', [
                'paid',
                'organic_search',
                'organic_social',
                'direct',
                'referral',
                'email',
                'utm',
            ]);

            $table->date('period_start');
            $table->date('period_end');

            // ── Tráfego ───────────────────────────────────────────────
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 5, 2)->nullable()->comment('Calculado: (clicks/impressions)*100');
            $table->unsignedInteger('sessions')->default(0);

            // ── Custo ─────────────────────────────────────────────────
            $table->decimal('spend_amount', 10, 2)->default(0.00);
            $table->decimal('cpc', 8, 4)->nullable()->comment('Calculado: spend_amount/clicks');
            $table->decimal('cost_per_lead', 10, 2)->nullable()->comment('Calculado: spend_amount/leads_count');
            $table->decimal('cost_per_sale', 10, 2)->nullable();

            // ── Conversão ─────────────────────────────────────────────
            $table->unsignedInteger('leads_count')->default(0);
            $table->decimal('conversion_rate', 5, 2)->nullable()->comment('Calculado: (leads_count/sessions)*100');
            $table->unsignedInteger('time_to_first_lead_hours')->nullable();
            $table->unsignedInteger('time_to_sale_days')->nullable();

            // ── Financeiro ────────────────────────────────────────────
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('gross_margin', 10, 2)->nullable()->comment('Calculado: sale_price - purchase_price - total_spend');
            $table->decimal('roi', 8, 2)->nullable()->comment('Calculado: (gross_margin/spend_amount)*100');

            // ── Controlo ──────────────────────────────────────────────
            $table->enum('data_source', [
                'manual',
                'google_ads',
                'meta_ads',
                'calculated',
            ])->default('manual');

            $table->boolean('requires_review')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────
            $table->unique(
                ['car_id', 'company_id', 'channel', 'period_start', 'period_end'],
                'uq_car_channel_period'
            );

            $table->index(['company_id', 'car_id']);
            $table->index(['company_id', 'channel']);
            $table->index(['period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_performance_metrics');
    }
};
