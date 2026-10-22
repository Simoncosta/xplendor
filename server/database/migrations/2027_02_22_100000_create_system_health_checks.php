<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitorização: uma linha por verificação (base de dados, Redis, antivírus, disco) e por sinal de
 * vida (scheduler, worker). Guarda o último momento bom, desde quando falha e quando se avisou,
 * para avisar uma só vez e voltar a avisar quando recupera. Na base de dados (e não na cache) para
 * os sinais de vida não dependerem do Redis.
 *
 * ADITIVA: só uma tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_health_checks')) {
            return;
        }

        Schema::create('system_health_checks', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_checks');
    }
};
