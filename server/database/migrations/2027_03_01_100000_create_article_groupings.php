<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artigos de agrupamento (definição da XPLENDOR, por empresa e por artigo; não mexe no PingWin): ao
 * lançar uma fatura, as linhas ligadas a um destes artigos juntam-se numa só, com quantidade 1 e o total
 * somado. Pelo id do artigo no PingWin (sobrevive à sincronização do catálogo); quem mudou e quando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_groupings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('product_pingwin_id', 30);
            $table->boolean('is_grouping')->default(false);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'product_pingwin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_groupings');
    }
};
