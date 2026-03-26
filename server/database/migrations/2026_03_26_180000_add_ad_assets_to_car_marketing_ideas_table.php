<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_marketing_ideas', function (Blueprint $table) {
            $table->json('primary_texts')->nullable()->after('formats');
            $table->json('headlines')->nullable()->after('primary_texts');
            $table->json('descriptions')->nullable()->after('headlines');
        });
    }

    public function down(): void
    {
        Schema::table('car_marketing_ideas', function (Blueprint $table) {
            $table->dropColumn(['primary_texts', 'headlines', 'descriptions']);
        });
    }
};
