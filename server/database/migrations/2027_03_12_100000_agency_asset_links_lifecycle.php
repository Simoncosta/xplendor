<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parceiro 5: o estado de cada ativo da agência ao longo do tempo. Colunas novas que aceitam vazio:
 * o motivo do estado (por exemplo, porque ficou "sem acesso") e quando foi verificado pela última vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_asset_links', function (Blueprint $table) {
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->dateTime('checked_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('agency_asset_links', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'checked_at']);
        });
    }
};
