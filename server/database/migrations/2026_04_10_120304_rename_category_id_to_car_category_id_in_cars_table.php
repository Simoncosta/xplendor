<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cars', 'category_id') || Schema::hasColumn('cars', 'car_category_id')) {
            return;
        }

        Schema::table('cars', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->renameColumn('category_id', 'car_category_id');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->foreign('car_category_id')
                ->references('id')
                ->on('car_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('cars', 'car_category_id') || Schema::hasColumn('cars', 'category_id')) {
            return;
        }

        Schema::table('cars', function (Blueprint $table) {
            $table->dropForeign(['car_category_id']);
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->renameColumn('car_category_id', 'category_id');
        });

        Schema::table('cars', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('car_categories')
                ->nullOnDelete();
        });
    }
};
