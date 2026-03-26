<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_views', function (Blueprint $table) {
            $table->uuid('client_view_key')
                ->nullable()
                ->after('session_id');

            $table->unsignedInteger('view_duration_seconds')
                ->nullable()
                ->after('user_agent');

            $table->index(['company_id', 'car_id', 'visitor_id', 'client_view_key'], 'car_views_silent_buyer_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('car_views', function (Blueprint $table) {
            $table->dropIndex('car_views_silent_buyer_lookup_idx');
            $table->dropColumn(['client_view_key', 'view_duration_seconds']);
        });
    }
};
