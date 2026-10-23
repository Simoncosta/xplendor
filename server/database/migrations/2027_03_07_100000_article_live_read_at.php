<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PingWin: a hora da última leitura ao vivo de um artigo (ao abrir ou em "Atualizar do PingWin"), que
 * grava na cópia os ids e os nomes das unidades. O ecrã do artigo mostra logo a cópia com esta hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pingwin_catalog_items', 'live_read_at')) {
            Schema::table('pingwin_catalog_items', function (Blueprint $table) {
                $table->timestamp('live_read_at')->nullable()->after('bom_synced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pingwin_catalog_items', 'live_read_at')) {
            Schema::table('pingwin_catalog_items', function (Blueprint $table) {
                $table->dropColumn('live_read_at');
            });
        }
    }
};
