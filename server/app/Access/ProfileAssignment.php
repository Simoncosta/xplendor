<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * ACL (F2): o perfil de um utilizador acompanha o papel enquanto o perfil for de sistema.
 *  · utilizador novo sem perfil → o perfil de sistema do papel (admin → Administrador;
 *    user → "Utilizador (como hoje)"); numa agência, também o perfil dentro dos clientes;
 *  · mudança de papel com perfil de sistema → o perfil de sistema do novo papel;
 *  · root → sem perfil.
 * Um perfil personalizado (F5) nunca é trocado aqui.
 */
final class ProfileAssignment
{
    private static ?bool $ready = null;

    public static function syncWithRole(User $user): void
    {
        if (! self::ready()) {
            return; // antes da migração dos perfis (por exemplo, a meio de um migrate)
        }
        if ($user->role === 'root') {
            $user->profile_id = null;
            $user->agency_profile_id = null;

            return;
        }

        $roleChanged = $user->exists && $user->isDirty('role');
        if ($user->profile_id === null || ($roleChanged && self::isSystem($user->profile_id))) {
            $user->profile_id = CompatibilityMigration::profileForRole((string) $user->role);
        }

        $isAgency = $user->company_id && Company::whereKey($user->company_id)->whereNotNull('agency_enabled_at')->exists();
        if ($isAgency && ($user->agency_profile_id === null || ($roleChanged && self::isSystem($user->agency_profile_id)))) {
            $user->agency_profile_id = CompatibilityMigration::agencyProfileForRole((string) $user->role);
        }
    }

    private static function isSystem(?int $profileId): bool
    {
        return $profileId !== null && (bool) PermissionProfile::whereKey($profileId)->value('is_system');
    }

    private static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }
        // Só se guarda o "sim": durante as migrações o esquema ainda pode estar a mudar.
        $ready = Schema::hasColumn('users', 'profile_id') && Schema::hasTable('permission_profiles');
        self::$ready = $ready ? true : null;

        return $ready;
    }

    /** Para os testes (o esquema muda entre migrações). */
    public static function flush(): void
    {
        self::$ready = null;
    }
}
