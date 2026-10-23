<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo da PingWin: as mudanças de unidade (base ou de compra) de um artigo com histórico de compras.
 * As linhas antigas ficam na unidade anterior; o artigo e o Guião de unidades avisam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_unit_changes')) {
            Schema::create('article_unit_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('field', 16);                 // base | purchase
                $table->string('from_unit_id', 32)->nullable();
                $table->string('from_name', 120)->nullable();
                $table->string('to_unit_id', 32)->nullable();
                $table->string('to_name', 120)->nullable();
                $table->unsignedInteger('history_lines')->default(0);
                $table->timestamp('changed_at');
                $table->timestamps();
                // Nome curto: o automático passa dos 64 carateres do MariaDB.
                $table->index(['company_id', 'product_pingwin_id', 'changed_at'], 'auc_company_product_changed_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_unit_changes');
    }
};
