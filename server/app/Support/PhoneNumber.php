<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Números de telefone para links (wa.me e tel:). Guarda-se o número como foi escrito
 * (para mostrar) e normaliza-se só ao publicar: só dígitos, sem "00" inicial, e um
 * número português de 9 dígitos ganha o indicativo 351.
 */
final class PhoneNumber
{
    public static function internationalDigits(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 9) {
            $digits = '351' . $digits;
        }

        return strlen($digits) >= 9 ? $digits : null;
    }

    public static function tel(?string $raw): ?string
    {
        $digits = self::internationalDigits($raw);

        return $digits ? '+' . $digits : null;
    }
}
