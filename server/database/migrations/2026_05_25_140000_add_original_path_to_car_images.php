<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->text('original_path')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropColumn('original_path');
        });
    }
};
