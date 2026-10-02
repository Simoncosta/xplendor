<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Documentos (Fase D2b): a auditoria pingwin_docconfig_writes passa a carregar
 * também as mudanças do docaccount (docaccount = [{docaccount_id, deleted, credit, debit}]).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_docconfig_writes')) {
            return;
        }
        Schema::table('pingwin_docconfig_writes', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_docconfig_writes', 'docaccount')) {
                $table->json('docaccount')->nullable()->after('children');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_docconfig_writes')) {
            return;
        }
        Schema::table('pingwin_docconfig_writes', function (Blueprint $table) {
            if (Schema::hasColumn('pingwin_docconfig_writes', 'docaccount')) {
                $table->dropColumn('docaccount');
            }
        });
    }
};
