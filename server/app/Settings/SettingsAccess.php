<?php

declare(strict_types=1);

namespace App\Settings;

use App\Access\Access;
use App\Models\User;

/**
 * Configurações: quem muda as definições da empresa. O administrador da própria empresa (o perfil
 * Administrador) ou o root. Não há um perfil de dono na XPLENDOR: a regra das anulações por
 * funcionário fica também com o administrador (com a autorização do dono no texto do ecrã).
 */
final class SettingsAccess
{
    public static function companyAdmin(?User $user, int $companyId): bool
    {
        return $user !== null && ($user->isRoot() || ((int) $user->company_id === $companyId && app(Access::class)->isCompanyAdmin($user)));
    }
}
