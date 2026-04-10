<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')
                ->constrained('cars')
                ->cascadeOnDelete();
            $table->json('attributes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_attributes');
    }
};
