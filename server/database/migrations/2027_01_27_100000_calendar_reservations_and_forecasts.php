<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 6a · Calendário: as reservas futuras lidas no CoverManager (uma fotografia por leitura, só
 * números: a futura "curva de reservas"), a previsão por loja e dia (pessoas e faturação) e o erro
 * medido de cada modelo (backtest das últimas 8 semanas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cm_reservation_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $t->date('business_date');
            $t->timestamp('read_at');
            $t->unsignedSmallInteger('lead_days');
            $t->unsignedInteger('tables_count')->default(0);   // reservas válidas (mesas)
            $t->unsignedInteger('guests_total')->default(0);   // pessoas das reservas válidas
            $t->json('by_status')->nullable();                 // {valid|cancelled|no_show|unclassified: {tables, guests}}
            $t->json('by_channel')->nullable();                // {canal: mesas} das válidas
            $t->timestamps();
            $t->unique(['location_id', 'business_date', 'read_at'], 'cm_snap_loc_day_read');
            $t->index(['company_id', 'business_date']);
        });
        Schema::create('restaurant_forecasts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $t->date('business_date');
            $t->string('metric', 12);          // guests | invoiced
            $t->string('model', 16);           // media | reservas
            $t->decimal('value', 12, 2)->nullable();
            $t->decimal('low', 12, 2)->nullable();
            $t->decimal('high', 12, 2)->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
            $t->unique(['location_id', 'business_date', 'metric'], 'rest_fc_loc_day_metric');
            $t->index(['company_id', 'business_date']);
        });
        Schema::create('restaurant_forecast_errors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $t->string('metric', 12);          // guests | invoiced
            $t->string('model', 16);           // media_d1 | media_d7 | reservas_d1 | media_d1_res (a média nos mesmos dias)
            $t->decimal('mape', 8, 2)->nullable();
            $t->decimal('p10', 8, 4)->nullable();
            $t->decimal('p90', 8, 4)->nullable();
            $t->unsignedSmallInteger('n')->default(0);
            $t->date('from')->nullable();
            $t->date('to')->nullable();
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
            $t->unique(['location_id', 'metric', 'model'], 'rest_fc_err_loc_metric_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_forecast_errors');
        Schema::dropIfExists('restaurant_forecasts');
        Schema::dropIfExists('cm_reservation_snapshots');
    }
};
