<?php

use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACL, F5 (documents/ACL-DESENHO.md, D11 e D13):
 *  · permission_profiles.only_assigned_clients: um perfil do lado da agência que só vê os
 *    clientes a que a pessoa está atribuída, mesmo com team_scope = all (Criativo externo);
 *  · as sugestões de perfil (Marketing, Financeiro, Só leitura, Agência convidada; e, do lado
 *    da agência, Administrador da agência, Gestor de clientes e Criativo externo).
 * Só acrescenta: não muda o perfil de ninguém.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_profiles', function (Blueprint $table) {
            $table->boolean('only_assigned_clients')->default(false)->after('is_suggestion');
        });

        ProfileSuggestions::ensure();
    }

    public function down(): void
    {
        \App\Models\PermissionProfile::where('is_suggestion', true)->whereNotNull('system_key')->delete();
        Schema::table('permission_profiles', function (Blueprint $table) {
            $table->dropColumn('only_assigned_clients');
        });
    }
};
