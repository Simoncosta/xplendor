<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_performance_metrics', function (Blueprint $table) {
            $table->unsignedInteger('interactions_count')->default(0)->after('leads_count');
            $table->unsignedInteger('whatsapp_clicks')->default(0)->after('interactions_count');
            $table->unsignedInteger('phone_clicks')->default(0)->after('whatsapp_clicks');
        });
    }

    public function down(): void
    {
        Schema::table('car_performance_metrics', function (Blueprint $table) {
            $table->dropColumn(['interactions_count', 'whatsapp_clicks', 'phone_clicks']);
        });
    }
};
