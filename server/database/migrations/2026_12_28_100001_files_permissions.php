<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Ficheiros: a área nova "ficheiros" nos perfis de sistema (Administrador tudo; Utilizador ver,
 * criar e editar; Agência convidada ver e criar) e nas sugestões (Só leitura ver; Agência
 * convidada ver e criar; Marketing e Financeiro ver, criar e editar). Só dados. Os perfis
 * personalizados não mudam (o administrador decide). O módulo "ficheiros" nasce desligado.
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
