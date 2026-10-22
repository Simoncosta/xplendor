<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos do site: a área nova "avisos_site" (ver, criar, editar, apagar) nos perfis de sistema
 * (o Administrador tudo; o utilizador e a agência ver, criar e editar) e nas sugestões (a
 * "Agência convidada" ver, criar e editar; a "Só leitura" ver). Só dados. Os perfis
 * personalizados não mudam (o administrador decide).
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
