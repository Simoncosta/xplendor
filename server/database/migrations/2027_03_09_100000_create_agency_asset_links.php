<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parceiro 4: os ativos da Meta da agência (Páginas, contas de Instagram e contas de anúncios) ligados a
 * uma empresa gerida. Cada ativo só numa empresa (único por tipo e id). As tabelas de sempre
 * (social_connections, social_connection_accounts e company_integrations) ganham a origem: "agencia"
 * quando a ligação vem da agência; vazio nas ligações da própria empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_asset_links')) {
            Schema::create('agency_asset_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('asset_type', 16);            // page | instagram | ad_account
                $table->string('external_id', 64);
                $table->string('page_id', 64)->nullable();   // a Página do Instagram
                $table->string('name', 190)->nullable();
                $table->string('business_id', 64)->nullable();
                $table->string('status', 16);                // ligado | sem_tarefa
                $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['asset_type', 'external_id'], 'agency_asset_links_asset_unique');
                $table->index(['agency_company_id', 'company_id'], 'agency_asset_links_agency_company_idx');
            });
        }
        foreach (['social_connections', 'company_integrations'] as $t) {
            if (! Schema::hasColumn($t, 'origin')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->string('origin', 16)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['social_connections', 'company_integrations'] as $t) {
            if (Schema::hasColumn($t, 'origin')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('origin');
                });
            }
        }
        Schema::dropIfExists('agency_asset_links');
    }
};
