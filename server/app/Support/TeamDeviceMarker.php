<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Marca assinada de "browser da equipa". Entregue no login do root e de quem pertence a uma
 * agência, e guardada pelo frontend em localStorage (partilhado entre separadores; apagada
 * quando a pessoa termina a sessão). As páginas públicas (orçamento, aprovação de conteúdos)
 * enviam-na com o sinal de abertura e o servidor não conta a abertura da equipa (o root, ou a
 * agência que gere a empresa do link). Não dá acesso a nada: só identifica o browser.
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

    /** O root do browser marcado (a equipa XPLENDOR). */
    public static function verify(?string $marker): bool
    {
        return self::user($marker)?->role === 'root';
    }

    /** A pessoa do browser marcado, se a marca for válida (para saber se é da agência gestora). */
    public static function user(?string $marker): ?User
    {
        $parts = explode('.', (string) $marker);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }
        [$userId, $issued, $signature] = $parts;
        if (! hash_equals(self::sign($userId, $issued), $signature)) {
            return null;
        }
        $age = now()->timestamp - (int) $issued;
        if ($age < -300 || $age > self::MAX_AGE_DAYS * 86400) {
            return null;
        }

        return User::whereKey((int) $userId)->whereNull('deactivated_at')->first();
    }

    private static function sign(string $userId, string $issued): string
    {
        return hash_hmac('sha256', "team-device|{$userId}|{$issued}", (string) config('app.key'));
    }
}
