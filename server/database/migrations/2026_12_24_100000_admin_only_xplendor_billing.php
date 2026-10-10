<?php

use App\Access\CompatibilityMigration;
use App\Access\Permissions;
use App\Access\ProfileSuggestions;
use App\Models\PermissionProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACL (complemento ao pré-deploy, ponto 1): ver e aprovar a faturação da XPLENDOR (orçamentos e
 * cobranças) é só do perfil Administrador. Só dados, sem mudar o esquema:
 *  · sai de todos os outros perfis (de sistema, sugestões e personalizados);
 *  · os perfis de sistema e as sugestões voltam a ter as permissões do catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permission_profiles')) {
            return;
        }
        $adminId = DB::table('permission_profiles')->where('system_key', PermissionProfile::ADMIN)->value('id');
        $removed = DB::table('profile_permissions')->whereIn('area', Permissions::ADMIN_ONLY_AREAS)
            ->when($adminId, fn ($q) => $q->where('profile_id', '!=', $adminId))
            ->delete();
        CompatibilityMigration::ensureSystemProfiles();
        ProfileSuggestions::ensure();
        if ($removed > 0) {
            \App\Models\PermissionProfileEvent::log('faturacao_so_administrador', null, null, null, ['permissoes_retiradas' => $removed]);
        }
    }

    public function down(): void
    {
        // Não repõe: a regra antiga voltaria a dar a faturação a quem a tinha pelo papel.
    }
};
