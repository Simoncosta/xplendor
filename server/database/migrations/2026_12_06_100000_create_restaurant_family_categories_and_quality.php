<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F1-3 do marketing da restauração.
 *  · restaurant_family_categories: categoria de marketing de cada família do PingWin. A
 *    sugestão (regras ou IA) e a confirmação (só pela equipa) ficam separadas.
 *  · restaurant_data_quality: um retrato por empresa (cobertura do catálogo, conferência
 *    com o líquido diário, histórico por loja, famílias por classificar).
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_family_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('family_pingwin_id', 32);
            $table->string('family_path')->nullable();
            $table->string('suggested_category', 32)->nullable();
            $table->string('suggested_by', 16)->nullable(); // rules | ai
            $table->string('category', 32)->nullable();     // confirmada pela equipa
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'family_pingwin_id'], 'rest_family_cat_company_family_unique');
        });

        Schema::create('restaurant_data_quality', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('catalog_sold_count')->default(0);    // artigos vendidos (90 dias)
            $table->unsignedInteger('catalog_missing_count')->default(0); // desses, fora do catálogo
            $table->decimal('catalog_coverage_pct', 5, 1)->nullable();
            $table->unsignedInteger('days_checked')->default(0);          // loja × dia com conferência (90 dias)
            $table->unsignedInteger('days_ok')->default(0);
            $table->unsignedInteger('days_marked')->default(0);           // não batem ou vazios protegidos
            $table->unsignedInteger('families_total')->default(0);        // famílias com vendas (90 dias)
            $table->unsignedInteger('families_unconfirmed')->default(0);
            $table->decimal('revenue_unconfirmed_pct', 5, 1)->nullable(); // peso das famílias por confirmar
            $table->json('locations')->nullable();                        // por loja: início, dias, histórico
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_data_quality');
        Schema::dropIfExists('restaurant_family_categories');
    }
};
