<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F2 do marketing da restauração (períodos fracos). Só agregados, nenhum dado
 * pessoal. Aditiva e reversível.
 *  · pingwin_hourly_sales: loja × dia × hora (valor sem IVA), espelho das "Vendas por hora".
 *  · pingwin_hourly_sales_days: conferência de cada loja × dia (soma das horas contra o
 *    líquido diário), com os mesmos estados das vendas por artigo.
 *  · pingwin_locations.hourly_history_complete_at: fim do histórico por hora.
 *  · cm_reservation_hourly / _channel_daily / _leadtime_daily / _status_daily: agregados do
 *    CoverManager tirados da leitura que já se faz (sem chamadas novas).
 *  · cm_reservation_shift_summary.no_show_count: as faltas deixam de contar como anulações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_hourly_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedTinyInteger('hour'); // 0 a 23
            $table->bigInteger('net_cents')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date', 'hour'], 'pw_hourly_loc_date_hour_unique');
            $table->index(['company_id', 'business_date'], 'pw_hourly_company_date_idx');
        });

        Schema::create('pingwin_hourly_sales_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 20); // os estados de PingwinItemSalesDay
            $table->unsignedInteger('rows_count')->default(0);
            $table->bigInteger('hours_net_cents')->default(0);  // soma das horas (sem IVA)
            $table->bigInteger('daily_net_cents')->nullable();  // líquido do Resumo de Vendas
            $table->unsignedSmallInteger('reads_count')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date'], 'pw_hourly_days_loc_date_unique');
            $table->index(['company_id', 'status'], 'pw_hourly_days_company_status_idx');
        });

        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->timestamp('hourly_history_complete_at')->nullable()->after('history_complete_at');
        });

        Schema::create('cm_reservation_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedTinyInteger('hour'); // hora da reserva (chegada)
            $table->unsignedInteger('reservations_count')->default(0);
            $table->unsignedInteger('guests_total')->default(0);
            $table->unsignedInteger('walk_ins_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date', 'hour'], 'cm_hourly_loc_date_hour_unique');
        });

        Schema::create('cm_reservation_channel_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('channel', 40); // provenance do CoverManager, normalizada
            $table->unsignedInteger('reservations_count')->default(0);
            $table->unsignedInteger('guests_total')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date', 'channel'], 'cm_channel_loc_date_channel_unique');
        });

        Schema::create('cm_reservation_leadtime_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('bucket', 12); // same_day | d1_2 | d3_7 | d8_30 | d31_plus
            $table->unsignedInteger('reservations_count')->default(0);
            $table->unsignedInteger('guests_total')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date', 'bucket'], 'cm_lead_loc_date_bucket_unique');
        });

        Schema::create('cm_reservation_status_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status_code', 12); // código de estado do CoverManager, tal como vem
            $table->unsignedInteger('reservations_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date', 'status_code'], 'cm_status_loc_date_code_unique');
        });

        Schema::table('cm_reservation_shift_summary', function (Blueprint $table) {
            $table->unsignedInteger('no_show_count')->default(0)->after('cancelled_count');
        });
    }

    public function down(): void
    {
        Schema::table('cm_reservation_shift_summary', function (Blueprint $table) {
            $table->dropColumn('no_show_count');
        });
        Schema::dropIfExists('cm_reservation_status_daily');
        Schema::dropIfExists('cm_reservation_leadtime_daily');
        Schema::dropIfExists('cm_reservation_channel_daily');
        Schema::dropIfExists('cm_reservation_hourly');
        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->dropColumn('hourly_history_complete_at');
        });
        Schema::dropIfExists('pingwin_hourly_sales_days');
        Schema::dropIfExists('pingwin_hourly_sales');
    }
};
