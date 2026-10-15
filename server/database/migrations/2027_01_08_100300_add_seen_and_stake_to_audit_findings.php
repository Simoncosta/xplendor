<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Auditoria (polimento 2b, textos em linguagem simples): o painel de cada achado tem
 * quatro blocos. "O que vimos" (seen) e "Quanto está em jogo" (stake) ganham colunas próprias;
 * "Porque importa" e "O que fazer" continuam em why e suggestion. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_findings')) {
            return;
        }
        Schema::table('audit_findings', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_findings', 'seen')) {
                $table->text('seen')->nullable()->after('title');
            }
            if (! Schema::hasColumn('audit_findings', 'stake')) {
                $table->text('stake')->nullable()->after('suggestion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_findings', function (Blueprint $table) {
            foreach (['seen', 'stake'] as $c) {
                if (Schema::hasColumn('audit_findings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
