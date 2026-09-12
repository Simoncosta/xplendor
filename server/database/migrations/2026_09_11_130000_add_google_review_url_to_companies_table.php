<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Pós-venda (Incremento 3) — link de avaliação Google do stand.
 *
 * O relatório usa-o no ramo ≥4 estrelas ("Ir para o Google"). Distinto da coluna
 * `google` (link social genérico). Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('google_review_url')->nullable()->after('google');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('google_review_url');
        });
    }
};
