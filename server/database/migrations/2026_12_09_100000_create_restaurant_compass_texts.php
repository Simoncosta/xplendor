<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola: a frase "O quê" de cada jogada, gerada pela IA (bussola_jogadas) no recálculo dos
 * sinais e guardada até ao recálculo seguinte. Sem frase guardada, o ecrã usa o modelo de
 * frase do tipo da jogada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_compass_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('play_key', 160);
            $table->string('text', 300);
            $table->dateTime('generated_at');
            $table->timestamps();
            $table->unique(['company_id', 'play_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_compass_texts');
    }
};
