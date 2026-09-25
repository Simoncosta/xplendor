<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (Fatia B2): máquina de estados dos meses.
 * Uma linha POR mês que já foi aberto alguma vez, por empresa (scoped).
 * Fechar NÃO apaga a linha → muda state para 'closed'. Assim o trabalho do mês
 * (conteúdo da B3, que ligará a editorial_months.id) sobrevive ao fecho e
 * reaparece ao reabrir. Leituras: sem linha = nunca tocado (fechado/cadeado);
 * state='closed' = já esteve aberto, agora fechado; state='open' = aberto.
 * A janela deslizante (12 meses a partir do corrente) calcula-se em runtime a
 * partir de now(Europe/Lisbon) — nada aqui a persiste; o passado fica no histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');                // ex.: 2026
            $table->tinyInteger('month');                // 1..12
            $table->string('state', 8)->default('open'); // 'open' | 'closed'
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_months');
    }
};
