<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1): promove o flag "Pago" do documentconfig
 * (maindataset.settled, rótulo "Pago" no PingWin) a coluna própria. É ele que separa os
 * documentos auto-pagos (ex.: Fatura-recibo compra) do saldo real do fornecedor.
 *
 * Backfill a partir de raw.settled, em PHP (portável MySQL/sqlite). Docconfigs sem raw
 * (anulados, só lista) ficam false — conservador, igual ao PingWin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pingwin_document_configs', function (Blueprint $table) {
            $table->boolean('settled')->default(false)->after('stock_signal');
        });

        DB::table('pingwin_document_configs')->whereNotNull('raw')->orderBy('id')
            ->select(['id', 'raw'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    if (is_array($raw) && (int) ($raw['settled'] ?? 0) === 1) {
                        DB::table('pingwin_document_configs')->where('id', $row->id)->update(['settled' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('pingwin_document_configs', function (Blueprint $table) {
            $table->dropColumn('settled');
        });
    }
};
