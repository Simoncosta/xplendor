<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Marca assinada de "browser da equipa". Entregue no login do root e guardada pelo
 * frontend em localStorage (partilhado entre separadores; apagada quando o root termina
 * a sessão). A página pública do orçamento envia-a com o sinal de abertura e o servidor
 * não conta a abertura. Não dá acesso a nada: só identifica o browser como da equipa.
 * Formato: "{id do utilizador}.{emitida em}.{assinatura HMAC}".
 */
final class TeamDeviceMarker
{
    private const MAX_AGE_DAYS = 180;

    public static function issue(User $user): string
    {
        $issued = (string) now()->timestamp;

        return $user->id . '.' . $issued . '.' . self::sign((string) $user->id, $issued);
    }

    public static function verify(?string $marker): bool
    {
        $parts = explode('.', (string) $marker);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return false;
        }
        [$userId, $issued, $signature] = $parts;
        if (! hash_equals(self::sign($userId, $issued), $signature)) {
            return false;
        }
        $age = now()->timestamp - (int) $issued;
        if ($age < -300 || $age > self::MAX_AGE_DAYS * 86400) {
            return false;
        }

        return User::whereKey((int) $userId)->where('role', 'root')->exists();
    }

    private static function sign(string $userId, string $issued): string
    {
        return hash_hmac('sha256', "team-device|{$userId}|{$issued}", (string) config('app.key'));
    }
}
