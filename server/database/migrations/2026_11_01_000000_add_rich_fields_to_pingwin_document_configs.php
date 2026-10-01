<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Documentos (Fase D0, leitura rica): estende pingwin_document_configs com a
 * config COMPLETA de cada documento. HÍBRIDO: colunas dedicadas para os _id-chave que as
 * Faturas vão consultar + raw JSON para o resto do maindataset. As 14 tabelas filhas em
 * UMA coluna JSON cada (nomes = chaves do servidor, incl. a grafia real "default_docsatatus").
 * additionalfields (maindataset/storedataset) e options dos selects em JSON. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_document_configs')) {
            return;
        }

        Schema::table('pingwin_document_configs', function (Blueprint $table) {
            // _id-chave (gravar sempre o _id, nunca o _descr). stock_signal é -1/1.
            foreach (['taxscenario_id', 'doctype_id', 'docfiscaltype_id', 'default_paycond_id', 'stock_signal', 'docseries_id'] as $col) {
                if (! Schema::hasColumn('pingwin_document_configs', $col)) {
                    $table->string($col)->nullable();
                }
            }
            // raw do maindataset (fonte de verdade para a edição) + options dos selects (snapshot read-only).
            foreach (['raw', 'options', 'additionalfields_maindataset', 'additionalfields_storedataset'] as $col) {
                if (! Schema::hasColumn('pingwin_document_configs', $col)) {
                    $table->json($col)->nullable();
                }
            }
            // 14 tabelas filhas — uma coluna JSON por filha (nome = chave do servidor).
            $children = [
                'entitytype_docconfig', 'docconfig_detailstatus', 'default_detailstatus',
                'docconfig_docmovreason', 'docconfig_docstatus', 'default_docsatatus',
                'docconfig_docaccount', 'docconfig_local', 'docconfig_import',
                'docconfig_paymethod', 'docconfig_docreference', 'docconfig_paycond',
                'userrole_docconfig', 'store_docconfig',
            ];
            foreach ($children as $col) {
                if (! Schema::hasColumn('pingwin_document_configs', $col)) {
                    $table->json($col)->nullable();
                }
            }
            if (! Schema::hasColumn('pingwin_document_configs', 'rich_synced_at')) {
                $table->timestamp('rich_synced_at')->nullable();   // marca a última sync RICA (≠ synced_at da lista)
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_document_configs')) {
            return;
        }
        Schema::table('pingwin_document_configs', function (Blueprint $table) {
            foreach ([
                'taxscenario_id', 'doctype_id', 'docfiscaltype_id', 'default_paycond_id', 'stock_signal', 'docseries_id',
                'raw', 'options', 'additionalfields_maindataset', 'additionalfields_storedataset',
                'entitytype_docconfig', 'docconfig_detailstatus', 'default_detailstatus',
                'docconfig_docmovreason', 'docconfig_docstatus', 'default_docsatatus',
                'docconfig_docaccount', 'docconfig_local', 'docconfig_import',
                'docconfig_paymethod', 'docconfig_docreference', 'docconfig_paycond',
                'userrole_docconfig', 'store_docconfig', 'rich_synced_at',
            ] as $col) {
                if (Schema::hasColumn('pingwin_document_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
