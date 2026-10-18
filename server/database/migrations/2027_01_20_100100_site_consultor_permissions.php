<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Site do consultor: as áreas novas "site_consultor" (o perfil do site; publicar é do
 * Administrador) e "contactos" (a central de contactos recebidos, dados pessoais) nos perfis de
 * sistema e na sugestão "Agência convidada" (o perfil do site, sem os contactos). Só dados. Os perfis personalizados não mudam (o
 * administrador decide). O módulo "site_consultor" nasce desligado.
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
        // Sem retorno: o catálogo anterior ignora as áreas.
    }
};
