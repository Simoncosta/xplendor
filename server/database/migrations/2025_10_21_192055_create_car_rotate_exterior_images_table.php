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
        Schema::create('car_rotate_exterior_images', function (Blueprint $table) {
            $table->id();
            $table->string('image');
            $table->integer('order')->default(0);
            $table->foreignId('car_id')->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_rotate_exterior_images');
    }
};
