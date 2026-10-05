<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Expiração de um token da Meta, a partir do debug_token. A Meta devolve expires_at = 0
 * quando o token NÃO expira (por exemplo, tokens de longa duração de alguns utilizadores
 * de sistema ou de Páginas); 0, ausente ou inválido significa "sem data de expiração" e
 * grava-se NULL. Nunca uma data de 1970 (o MariaDB recusa timestamps antes de
 * 1970-01-01 00:00:01 UTC). NULL não é "expirado": o token só é dado como expirado
 * quando a Meta o recusa (código 190) ou tem data no passado.
 */
final class MetaTokenExpiry
{
    public static function fromDebug(array $tokenInfo): ?Carbon
    {
        $value = $tokenInfo['expires_at'] ?? null;
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }
}
