<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parceiro 2: a ligação da Meta da agência (uma por agência), feita por um administrador da agência,
 * com business_management, pages_show_list, pages_read_engagement, instagram_basic e ads_read. Token
 * de utilizador de longa duração, cifrado, com o utilizador da Meta, as permissões e o estado; o aviso
 * de renovação perto do fim fica marcado (renewal_warned_at). Tabela nova (aditiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('meta_user_id', 40)->nullable();
            $table->text('access_token');
            $table->dateTime('token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->string('status', 20)->default('active'); // active | expired | permission
            $table->unsignedBigInteger('connected_by_user_id')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('renewal_warned_at')->nullable();
            $table->timestamps();
            $table->index('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_meta_connections');
    }
};
