<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memória por fornecedor e descrição, para as linhas da fatura OCR sem código do fornecedor: a
 * descrição normalizada → o artigo da última ligação feita à mão. Entra no decide() depois do código
 * e antes das sugestões. As descrições genéricas nunca se aprendem. Só acrescenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_description_memory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('supplier_nif', 20);
            $table->string('description_norm', 191);
            $table->foreignId('article_id')->constrained('pingwin_catalog_items')->cascadeOnDelete();
            $table->foreignId('learned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'supplier_nif', 'description_norm'], 'sdm_company_nif_desc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_description_memory');
    }
};
