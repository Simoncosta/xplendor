<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha Editorial (aditiva): modo de produção por empresa.
 *  · self ("Produção própria", por omissão): os utilizadores da empresa produzem, como até aqui.
 *  · team ("Produção pela equipa XPLENDOR"): os utilizadores do cliente comentam, aprovam e
 *    pedem alterações, mas não editam conteúdo nem mudam etapas; produz a equipa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('content_production_mode', 10)->default('self');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('content_production_mode'));
    }
};
