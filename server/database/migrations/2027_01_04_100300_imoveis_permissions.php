<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola Master, R3: a área nova "imoveis" nos perfis de sistema (Administrador tudo; Utilizador
 * ver, criar e editar; a agência ver, criar e editar, sem apagar) e na sugestão "Agência
 * convidada" (ver, criar e editar), pelo padrão dos Ficheiros (resposta à pergunta 7). Só dados.
 * Os perfis personalizados não mudam (o administrador decide). O módulo "imoveis" nasce desligado.
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
        // Sem retorno: o catálogo anterior ignora a área.
    }
};
