<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ACL (D12): SEMPRE PELO MENOS UM ADMINISTRADOR ativo por empresa (clientes e agências).
 * Não se pode apagar, desativar, retirar o perfil nem mudar para outro perfil o último
 * administrador ativo: primeiro nomeia-se outro.
 */
final class LastAdminGuard
{
    public static function isActiveAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->deactivated_at === null;
    }

    /** @param string $action o que se tenta fazer, para a frase ("desativar", "retirar o perfil de Administrador a") */
    public static function assertKeeps(User $target, string $action): void
    {
        if (! self::isActiveAdmin($target)) {
            return;
        }
        $others = User::where('company_id', $target->company_id)->whereKeyNot($target->id)
            ->where('role', 'admin')->whereNull('deactivated_at')->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['user' => [
                "Nomeie outro administrador antes de {$action} {$target->name}: cada empresa tem sempre pelo menos um administrador ativo.",
            ]]);
        }
    }
}
