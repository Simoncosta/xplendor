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
        Schema::create('car_360_exterior_images', function (Blueprint $table) {
            $table->id();
            $table->integer('order')->comment('Ordem das imagens para o 360º');
            $table->string('image'); // ex: storage/cars/360/exterior/car_123/1.jpg
            $table->foreignId('company_id')->constrained();
            $table->foreignId('car_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_360_exterior_images');
    }
};
