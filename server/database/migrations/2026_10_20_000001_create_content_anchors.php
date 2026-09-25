<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (Fatia 1): ÂNCORAS de conteúdo. Vivem num nó da árvore
 * (sector_id) e guardam a REGRA de recorrência (NÃO a data — a data calcula-se por ano
 * no resolvedor). 4 tipos de regra:
 *   fixa            → month + day (Natal 12/25)
 *   nth_weekday     → month + ordinal (1..5 ou -1=último) + weekday (0=dom..6=sáb)
 *   periodo         → start_month/start_day .. end_month/end_day (Santos Populares: junho)
 *   relativa_pascoa → easter_offset (inteiro +/-; Sexta-Santa -2, Corpo de Deus +60)
 * country = 'PT' | null(=universal); origin = 'fiavel' (semeadas) | 'variavel' (curadoria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_anchors', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('sector_id')->constrained('content_sectors')->cascadeOnDelete();
            $table->string('country')->nullable();            // 'PT' | null = universal (atributo, não nível)
            $table->string('origin')->default('variavel');    // fiavel | variavel
            $table->string('rule_type');                      // fixa | nth_weekday | periodo | relativa_pascoa

            // fixa (e month também serve o nth_weekday)
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            // nth_weekday
            $table->tinyInteger('ordinal')->nullable();       // 1..5 ou -1 = último
            $table->unsignedTinyInteger('weekday')->nullable(); // 0=dom .. 6=sáb
            // periodo
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedTinyInteger('start_day')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->unsignedTinyInteger('end_day')->nullable();
            // relativa_pascoa
            $table->integer('easter_offset')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sector_id');
            $table->index(['sector_id', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_anchors');
    }
};
