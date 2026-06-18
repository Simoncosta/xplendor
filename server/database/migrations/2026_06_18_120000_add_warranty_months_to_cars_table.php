<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-fase F (Lote Matilde 3) — warranty_months
 *
 * Coluna nova para registar duração de garantia em meses (decisão fechada
 * com a Matilde: só meses, sem data; a data calcula-se da venda + meses,
 * fora do âmbito desta migration). unsignedSmallInteger nullable cobre
 * folga até 65535 — sobra muito para qualquer garantia humanamente possível;
 * cap real (max:180 = 15 anos) é aplicado no Form Request, NÃO na BD,
 * para não bloquear afinações futuras com migrations.
 *
 * CO-EXISTÊNCIA INTENCIONAL: as 3 colunas anteriores (warranty_available
 * varchar nullable, warranty_due_date date nullable, warranty_km
 * unsignedInteger nullable, criadas na migration original de 2025-12-22)
 * NÃO são tocadas. Continuam órfãs (sem UI, sempre null em prod) MAS
 * são emitidas cruas pela CarPublicResource — cleanup é decisão separada
 * com coordenação dos sites externos (breaking change). Ver dívida 66
 * em CLAUDE.md.
 *
 * Migration ADITIVA — categoria mais segura (sec 14.3). Nada de
 * migrate:fresh em dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')
                ->nullable()
                ->after('warranty_km')
                ->comment('Duração da garantia em meses (Sub-fase F, 2026-06-18). Distinto das 3 colunas órfãs warranty_available/due_date/km.');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
