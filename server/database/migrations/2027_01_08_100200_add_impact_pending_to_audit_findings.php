<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Auditoria (polimentos da Fatia 2): o impacto "a confirmar" (ex.: provável unidade
 * trocada). Mostra-se, mas não entra na soma do Pareto, que conta só o dinheiro em jogo.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_findings') && ! Schema::hasColumn('audit_findings', 'impact_pending_cents')) {
            Schema::table('audit_findings', function (Blueprint $table) {
                $table->bigInteger('impact_pending_cents')->nullable()->after('impact_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_findings', 'impact_pending_cents')) {
            Schema::table('audit_findings', function (Blueprint $table) {
                $table->dropColumn('impact_pending_cents');
            });
        }
    }
};
