<?php

use App\Access\CompatibilityMigration;
use App\Access\ProfileSuggestions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ACL (pré-deploy, ponto 3): o suporte com a XPLENDOR passa a área base ("suporte": ver e
 * criar, sem módulo) e as tarefas da equipa ficam na área "tarefas" (módulo "Suporte /
 * Tarefas"). Só dados, sem mudar o esquema:
 *  · os perfis de sistema e as sugestões voltam a ter as permissões do catálogo;
 *  · nos perfis personalizados, quem tinha o suporte fica também com as tarefas equivalentes
 *    (ninguém perde acesso), e saem suporte.editar e suporte.apagar, que deixaram de existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('permission_profiles')) {
            return;
        }
        foreach (DB::table('permission_profiles')->where('is_system', false)->pluck('id') as $profileId) {
            $actions = DB::table('profile_permissions')->where('profile_id', $profileId)->where('area', 'suporte')->pluck('action')->all();
            foreach ($actions as $action) {
                DB::table('profile_permissions')->insertOrIgnore(['profile_id' => $profileId, 'area' => 'tarefas', 'action' => $action]);
            }
            DB::table('profile_permissions')->where('profile_id', $profileId)->where('area', 'suporte')->whereIn('action', ['editar', 'apagar'])->delete();
        }
        CompatibilityMigration::ensureSystemProfiles();
        ProfileSuggestions::ensure();
    }

    public function down(): void
    {
        // Os dados novos (tarefas.*) ficam; o catálogo antigo voltaria a ignorá-los.
    }
};
