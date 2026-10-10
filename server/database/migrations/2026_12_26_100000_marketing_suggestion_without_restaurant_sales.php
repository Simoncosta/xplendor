<?php

use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * ACL: a sugestão "Marketing" deixa de ter os ecrãs de vendas da restauração (restauracao.ver),
 * coerente com a Bússola sem Finanças. Só dados: as sugestões voltam a ter as permissões do
 * catálogo. Os perfis já criados a partir dela não mudam (o administrador decide).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permission_profiles')) {
            ProfileSuggestions::ensure();
        }
    }

    public function down(): void
    {
        // Sem retorno: a sugestão anterior voltaria com o código anterior.
    }
};
