<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->string('vehicle_type', 20)->nullable()->after('source')->index();
        });

        // Existing rows are all from car scraping — backfill to keep data clean.
        DB::table('car_market_snapshots')->update(['vehicle_type' => 'car']);
    }

    public function down(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->dropIndex(['vehicle_type']);
            $table->dropColumn('vehicle_type');
        });
    }
};
