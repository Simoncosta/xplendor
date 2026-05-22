<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_market_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained('cars')->cascadeOnDelete();
            $table->string('vehicle_type', 20)->nullable();
            $table->enum('status', ['pending', 'success', 'none', 'blocked', 'error', 'failed'])->default('pending');
            $table->enum('confidence', ['high', 'medium', 'low', 'none'])->default('none');
            $table->unsignedInteger('comparables_count')->default(0);
            $table->decimal('median_price', 12, 2)->nullable();
            $table->decimal('min_price', 12, 2)->nullable();
            $table->decimal('max_price', 12, 2)->nullable();
            $table->decimal('avg_price', 12, 2)->nullable();
            $table->decimal('std_dev', 12, 2)->nullable();
            $table->decimal('car_price_gross', 12, 2)->nullable();
            $table->json('top_comparables')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->timestamps();

            $table->index('car_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_market_aggregates');
    }
};
