<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial: a empresa passa a ter um RAMO (setor-folha escolhido).
 * Uma empresa tem exatamente UM ramo → coluna FK nullable em `companies` (mais simples
 * que uma pivot 1:1, e alinhado com o padrão das outras FKs da empresa, ex.: plan_id).
 * nullable = ainda não escolheu (a tela mostra o ecrã de escolha). Aditiva/reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('content_sector_id')->nullable()->after('plan_id')
                ->constrained('content_sectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_sector_id');
        });
    }
};
