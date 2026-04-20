<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cars', 'subsegment')) {
            return;
        }

        DB::table('cars')
            ->where('subsegment', 'caravanas')
            ->update(['subsegment' => 'caravana']);

        DB::table('cars')
            ->where('subsegment', 'campervan')
            ->update(['subsegment' => null]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cars MODIFY subsegment ENUM('autocaravana', 'caravana', 'residencial') NULL");
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('cars', 'subsegment')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cars MODIFY subsegment ENUM('autocaravana', 'caravana', 'residencial') NULL");
        }
    }
};
