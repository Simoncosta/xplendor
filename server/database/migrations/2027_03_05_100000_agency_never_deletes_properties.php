<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Imóveis: a agência não apaga imóveis (como diz a migração 2027_01_04_100300). O perfil de
 * sistema "Administrador da agência (como hoje)" perde o imoveis.apagar. Só dados; os perfis
 * personalizados não mudam (o administrador decide). O acesso efetivo da agência já não apagava
 * (o teto da relação não tem o apagar); agora o perfil diz o mesmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permission_profiles')) {
            CompatibilityMigration::ensureSystemProfiles();
            ProfileSuggestions::ensure();
        }
    }

    public function down(): void
    {
        // Sem retorno: voltar a dar o apagar à agência seria contra a decisão.
    }
};
