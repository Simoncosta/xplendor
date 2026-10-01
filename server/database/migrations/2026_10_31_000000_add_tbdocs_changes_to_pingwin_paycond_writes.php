<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Condições de Pagamento, EDITAR (Fatia 2b): a linha de auditoria
 * pingwin_paycond_writes passa a carregar as MUDANÇAS de tbdocs do editar
 * (tbdocs_changes = [{docconfig_id, deleted}]). Distinto do tbdocs_unlinked do
 * inserir (lista de ids a desmarcar). Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_paycond_writes')) {
            return;
        }
        Schema::table('pingwin_paycond_writes', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_paycond_writes', 'tbdocs_changes')) {
                $table->json('tbdocs_changes')->nullable()->after('tbdocs_unlinked');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_paycond_writes')) {
            return;
        }
        Schema::table('pingwin_paycond_writes', function (Blueprint $table) {
            if (Schema::hasColumn('pingwin_paycond_writes', 'tbdocs_changes')) {
                $table->dropColumn('tbdocs_changes');
            }
        });
    }
};
