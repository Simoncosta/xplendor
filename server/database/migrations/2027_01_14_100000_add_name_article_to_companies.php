<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — o artigo do nome da empresa (o, a, os, as), para os textos ("nos Pinheiros", "na Adega").
 * Opcional: sem artigo, os textos não usam artigo nem contração. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'name_article')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('name_article', 3)->nullable()->after('trade_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'name_article')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('name_article');
            });
        }
    }
};
