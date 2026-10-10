<?php

declare(strict_types=1);

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;

/**
 * Ficheiros (pré-deploy): o teto da agência e a sugestão "Agência convidada" passam a ter
 * ficheiros.editar (mudar o nome e mover), além de ver e criar; apagar e restaurar continuam de
 * fora. Volta a gravar os perfis de sistema e as sugestões (idempotente). Os perfis que cada
 * empresa criou (incluindo cópias das sugestões e tetos próprios) ficam como estão.
 */
return new class extends Migration
{
    public function up(): void
    {
        CompatibilityMigration::ensureSystemProfiles();
        ProfileSuggestions::ensure();
    }

    public function down(): void
    {
        // Sem volta automática: os perfis de sistema seguem o código (CompatibilityProfiles e ProfileSuggestions).
    }
};
