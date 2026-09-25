<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (B3a): âncoras PRÓPRIAS da empresa. Tabela DEDICADA
 * (separada de content_anchors, que fica intacto): são puramente da empresa, NÃO têm
 * sector_id, não passam pela árvore, ninguém as herda. Mesmas colunas de REGRA que a
 * content_anchors → o EditorialAnchorResolver calcula-as igual (lê por propriedades).
 * 4 tipos: fixa | nth_weekday | periodo | relativa_pascoa. Quem cria (o cliente) apaga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_own_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('rule_type'); // fixa | nth_weekday | periodo | relativa_pascoa

            // fixa (month também serve nth_weekday)
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            // nth_weekday
            $table->tinyInteger('ordinal')->nullable();          // 1..5 ou -1 = último
            $table->unsignedTinyInteger('weekday')->nullable();  // 0=dom .. 6=sáb
            // periodo
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedTinyInteger('start_day')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->unsignedTinyInteger('end_day')->nullable();
            // relativa_pascoa
            $table->integer('easter_offset')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_own_anchors');
    }
};
