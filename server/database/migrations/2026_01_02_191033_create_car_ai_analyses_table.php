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
        Schema::create('car_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->text('input_data'); // JSON dos dados enviados para a IA
            $table->longText('analysis'); // Texto completo retornado
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');
            $table->enum('feedback', ['positive', 'negative'])->nullable();
            $table->foreignId('car_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_ai_analyses');
    }
};
