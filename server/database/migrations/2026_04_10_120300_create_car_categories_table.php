<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('vehicle_type', ['car', 'motorhome'])->default('car');
            $table->timestamps();

            $table->index('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_categories');
    }
};
