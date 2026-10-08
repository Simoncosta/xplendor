<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artigos excluídos das sugestões (Bússola e sinais), por exemplo o Couvert, sem mexer na
 * categoria da família. Permanente até "Voltar a incluir"; guarda quem e quando excluiu e
 * voltou a incluir (uma linha por exclusão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_excluded_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('product_pingwin_id', 64);
            $table->string('product_name', 255);
            $table->foreignId('excluded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('excluded_at');
            $table->foreignId('included_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('included_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_pingwin_id'], 'rei_company_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_excluded_items');
    }
};
