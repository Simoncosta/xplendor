<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Documentos (Fase D2a): a auditoria pingwin_docconfig_writes passa a carregar
 * também as MUDANÇAS das filhas de marcação (children = {filha: [{id, deleted}]}). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_docconfig_writes')) {
            return;
        }
        Schema::table('pingwin_docconfig_writes', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_docconfig_writes', 'children')) {
                $table->json('children')->nullable()->after('fields');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_docconfig_writes')) {
            return;
        }
        Schema::table('pingwin_docconfig_writes', function (Blueprint $table) {
            if (Schema::hasColumn('pingwin_docconfig_writes', 'children')) {
                $table->dropColumn('children');
            }
        });
    }
};
