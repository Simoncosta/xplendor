<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->decimal('promo_price_gross', 10, 2)->nullable()->after('car_price_gross');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->dropColumn('promo_price_gross');
        });
    }
};
