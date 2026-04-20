<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            if (!Schema::hasColumn('cars', 'subsegment')) {
                $table->enum('subsegment', [
                    'autocaravana',
                    'caravana',
                    'residencial',
                ])->nullable()->after('vehicle_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            if (Schema::hasColumn('cars', 'subsegment')) {
                $table->dropColumn('subsegment');
            }
        });
    }
};
