<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fotografia dos públicos personalizados da conta de anúncios Meta,
 * guardada pelo sync diário. Alimenta o motor de recomendações (regra "público de
 * clientes desatualizado"). Só metadados públicos da Meta: nenhum dado pessoal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_custom_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 50);
            $table->string('audience_id', 50);
            $table->string('name')->nullable();
            $table->string('subtype', 40)->nullable();          // CUSTOM = lista de clientes
            $table->bigInteger('approximate_count_lower_bound')->nullable();
            $table->bigInteger('approximate_count_upper_bound')->nullable();
            $table->timestamp('time_content_updated')->nullable(); // só existe em listas de clientes
            $table->integer('delivery_status_code')->nullable();
            $table->string('delivery_status_description')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'audience_id'], 'meta_custom_audiences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_custom_audiences');
    }
};
