<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OCR com a Anthropic: cada leitura regista também o fornecedor e o esforço (o modelo, os
 * tokens, o tempo e o custo já ficavam). Só colunas novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->string('ai_provider', 20)->nullable()->after('model');
            $table->string('ai_effort', 20)->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', fn (Blueprint $t) => $t->dropColumn(['ai_provider', 'ai_effort']));
    }
};
