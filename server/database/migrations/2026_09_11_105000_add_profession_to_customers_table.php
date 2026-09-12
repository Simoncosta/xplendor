<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Fase 3 — `profession` no Cliente (reutilizável entre documentos).
 *
 * Decisão do Simon: profissão é estável e repete-se noutros documentos (BCFT,
 * contrato) → vive no Cliente para preencher uma vez. `nationality` já existia
 * na entidade Cliente — só a profissão é nova.
 *
 * Aditiva, nullable, reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('profession')->nullable()->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('profession');
        });
    }
};
