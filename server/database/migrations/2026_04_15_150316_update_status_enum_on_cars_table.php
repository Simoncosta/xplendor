<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE cars 
            MODIFY COLUMN status 
            ENUM('draft', 'active', 'inactive', 'sold', 'available_soon', 'reserved') 
            DEFAULT 'draft'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE cars 
            MODIFY COLUMN status 
            ENUM('draft', 'active', 'inactive', 'sold', 'available_soon') 
            DEFAULT 'draft'
        ");
    }
};
