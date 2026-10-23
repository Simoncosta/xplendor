<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola 4: o histórico das reações ao Radar (base da aprendizagem futura, Bússola 9). Uma linha
 * por decisão (reagir, ignorar, não mostrar), com o tema, o ramo, a empresa, a pontuação e a data;
 * nunca o título nem o conteúdo dos artigos. Sem chave para as tendências nem para os sinais: a
 * purga dos 30 dias não lhe toca. Retenção de 12 meses, com purga própria. Tabela nova (aditiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_reaction_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('signal_id')->nullable();
            $table->string('topic_kind', 10);          // trend | news | weather | bce | calendar
            $table->string('sector_slug', 40)->nullable();
            $table->string('theme', 80)->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('decision', 12);            // reacted | ignored | muted
            $table->dateTime('decided_at');
            $table->dateTime('created_at')->nullable();
            $table->index(['company_id', 'decided_at']);
            $table->index(['sector_slug', 'decision']);
            $table->index('decided_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_reaction_history');
    }
};
