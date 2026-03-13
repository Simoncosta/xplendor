<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_ai_analyses', function (Blueprint $table) {

            // Substitui analysis (text) por JSON estruturado
            // Renomeia o campo antigo para não perder dados históricos
            $table->renameColumn('analysis', 'analysis_raw');

            // Resposta completa estruturada da IA
            $table->json('analysis')->nullable()->after('analysis_raw');

            // Campos indexáveis para dashboards e filtros
            $table->unsignedTinyInteger('score_conversao')->nullable()->after('analysis')
                ->comment('0-100 — indexável para ordenar carros por prioridade');

            $table->string('score_classificacao', 20)->nullable()->after('score_conversao')
                ->comment('Crítico | Baixo | Médio | Alto | Excelente');

            $table->string('urgency_level', 20)->nullable()->after('score_classificacao')
                ->comment('Imediata | Alta | Normal | Baixa');

            $table->boolean('price_alert')->default(false)->after('urgency_level')
                ->comment('true se o preço está acima do mercado');

            // Índices para queries do dashboard
            $table->index('score_conversao', 'idx_ai_score');
            $table->index('score_classificacao', 'idx_ai_classificacao');
            $table->index('urgency_level', 'idx_ai_urgency');
            $table->index('price_alert', 'idx_ai_price_alert');
        });
    }

    public function down(): void
    {
        Schema::table('car_ai_analyses', function (Blueprint $table) {
            $table->dropIndex('idx_ai_score');
            $table->dropIndex('idx_ai_classificacao');
            $table->dropIndex('idx_ai_urgency');
            $table->dropIndex('idx_ai_price_alert');

            $table->dropColumn(['analysis', 'score_conversao', 'score_classificacao', 'urgency_level', 'price_alert']);
            $table->renameColumn('analysis_raw', 'analysis');
        });
    }
};
