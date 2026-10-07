<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1d-1 (ajustes):
 *  · companies.purged_at: a empresa arquivada foi apagada de forma definitiva (ficam só as
 *    cobranças da XPLENDOR e a identificação mínima).
 *  · management_requests.identifier passa a aceitar vazio e ganha identifier_scrubbed_at: o
 *    NIPC ou email de um pedido sem empresa correspondente é apagado 30 dias depois do fim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable();
        });
        Schema::table('management_requests', function (Blueprint $table) {
            $table->string('identifier', 255)->nullable()->change();
            $table->timestamp('identifier_scrubbed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('management_requests', function (Blueprint $table) {
            $table->dropColumn('identifier_scrubbed_at');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
