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
        Schema::create('car_details', function (Blueprint $table) {
            $table->id();
            $table->enum('detail', ['technical', 'equipment', 'electric_hybrid', 'history', 'purchase_options']);
            $table->string('field', 50)->nullable();
            $table->string('specification', 50)->nullable();
            $table->string('value');
            $table->foreignId('car_id')->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_details');
    }
};
