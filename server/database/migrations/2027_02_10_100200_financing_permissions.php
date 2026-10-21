<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Envio seguro de documentos para financiamento: a área nova "financiamento" (ver, criar,
 * apagar) nos perfis de sistema (o Administrador tudo; o utilizador ver e criar; a agência nada)
 * e nas sugestões (a "Agência convidada" e a "Só leitura" nada). Só dados. Os perfis
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
