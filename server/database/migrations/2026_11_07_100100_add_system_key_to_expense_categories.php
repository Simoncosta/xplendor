<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias de sistema (ex.: 'meta_ads' = "Publicidade Meta"), criadas pela
 * aplicação e encontradas por esta chave mesmo que o nome seja mudado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('system_key', 40)->nullable()->after('company_id');
            $table->unique(['company_id', 'system_key'], 'expense_categories_company_system_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique('expense_categories_company_system_key_unique');
            $table->dropColumn('system_key');
        });
    }
};
