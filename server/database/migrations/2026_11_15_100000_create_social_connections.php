<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligação das redes sociais (Instagram e Facebook), separada da ligação dos anúncios
 * (company_integrations, platform = meta). Mesmo app Meta, só com as permissões
 * pages_show_list, pages_read_engagement e instagram_basic.
 *
 *  · social_connections: uma por empresa. Token do utilizador cifrado, permissões
 *    concedidas, estado e o resultado da última leitura.
 *  · social_connection_accounts: as Páginas e as contas de Instagram escolhidas.
 *    O token da Página (cifrado) serve para ler os seguidores; a conta principal
 *    de cada rede é a que alimenta social_follower_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('meta_user_id', 64)->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            // pending_selection | active | expired | permission_removed | not_approved | revoked
            $table->string('status', 24)->default('pending_selection');
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            // failed | expired | permission_removed | not_approved
            $table->string('last_error_kind', 24)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('social_connection_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // facebook | instagram
            $table->string('external_id', 64);
            $table->string('page_id', 64);  // a Página (no Instagram, a Página a que a conta está ligada)
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->text('page_access_token')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('last_followers_count')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_kind', 24)->nullable();
            $table->timestamps();

            // Nomes curtos: o MariaDB limita os identificadores a 64 caracteres.
            $table->unique(['company_id', 'platform', 'external_id'], 'social_accounts_company_platform_ext_unique');
            $table->index(['social_connection_id', 'platform'], 'social_accounts_connection_platform_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connection_accounts');
        Schema::dropIfExists('social_connections');
    }
};
