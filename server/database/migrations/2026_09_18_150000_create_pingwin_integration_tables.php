<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin (Incremento 1). Reutiliza company_integrations (platform=
 * 'pingwin', uma por empresa): a SENHA vai cifrada em access_token (cast
 * EncryptedLegacy); os IDs por-instalação (auth_url, api_url, frontend_url,
 * database, app_version, report_id, stores, username, stores_dataset_id…) num
 * campo JSON `config` (não-secreto).
 *
 * + tabela pingwin_stores: as lojas descobertas por empresa (uma empresa → N
 * lojas) + o último resumo de vendas por loja.
 *
 * ADITIVA e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Campo JSON genérico para config de integrações (IDs não-secretos).
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->json('config')->nullable()->after('property_id');
        });

        Schema::create('pingwin_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('external_id', 64);      // id da loja no PingWin
            $table->string('code', 64)->nullable();
            $table->string('description')->nullable();
            $table->string('city')->nullable();
            $table->json('last_summary')->nullable(); // último resumo de vendas
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'external_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_stores');
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
