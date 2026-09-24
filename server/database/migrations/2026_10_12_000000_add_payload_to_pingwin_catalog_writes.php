<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Artigos (Etapa 1b): a linha de auditoria pingwin_catalog_writes passa
 * a carregar o PAYLOAD completo do artigo a criar (ponte controller→job) + os preços
 * em CÊNTIMOS (auditoria). O job lê o payload, converte cêntimos→decimal na fronteira
 * e chama o Python. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_catalog_writes')) {
            return;
        }

        Schema::table('pingwin_catalog_writes', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_catalog_writes', 'payload')) {
                $table->json('payload')->nullable()->after('description'); // campos do maindataset (nomes PingWin)
            }
            if (! Schema::hasColumn('pingwin_catalog_writes', 'saleprice_cents')) {
                $table->integer('saleprice_cents')->nullable()->after('payload');
            }
            if (! Schema::hasColumn('pingwin_catalog_writes', 'purchaseprice_cents')) {
                $table->integer('purchaseprice_cents')->nullable()->after('saleprice_cents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_catalog_writes')) {
            return;
        }

        Schema::table('pingwin_catalog_writes', function (Blueprint $table) {
            foreach (['payload', 'saleprice_cents', 'purchaseprice_cents'] as $col) {
                if (Schema::hasColumn('pingwin_catalog_writes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
