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
        Schema::table('car_leads', function (Blueprint $table) {
            $table->uuid('visitor_id')
                ->nullable()
                ->index()
                ->after('channel'); // quem

            $table->uuid('session_id')
                ->nullable()
                ->index()
                ->after('visitor_id'); // sessão
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_leads', function (Blueprint $table) {
            //
        });
    }
};
