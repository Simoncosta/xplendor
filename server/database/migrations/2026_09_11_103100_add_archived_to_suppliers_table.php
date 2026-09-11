<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS sub-fase 1c.2b — arquivo de fornecedores.
 *
 * Na 1c.1 o fornecedor não tinha `archived` (eliminação era simples, a
 * reavaliar quando as despesas existissem). Agora existem: fornecedor com
 * despesas associadas não pode ser eliminado — só arquivado (mantém o
 * histórico das despesas que o usam, mas sai da selecção de novas despesas).
 *
 * Aditiva, default false, reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('archived')->default(false)->after('notes');
            $table->index(['company_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'archived']);
            $table->dropColumn('archived');
        });
    }
};
