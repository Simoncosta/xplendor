<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * XPLENDOR — Auditoria: durante uma regra, nada de IA nem de serviços externos (PingWin,
 * CoverManager). As regras só leem o espelho e o que estiver guardado; a IA corre quando alguém
 * carrega em "Perguntar à IA". Uma chamada destas dentro da auditoria é recusada (a regra falha com
 * a explicação e a corrida continua).
 */
final class AuditGuard
{
    private static bool $active = false;

    /** Corre $fn com a guarda ligada. */
    public static function run(callable $fn): mixed
    {
        $prev = self::$active;
        self::$active = true;
        try {
            return $fn();
        } finally {
            self::$active = $prev;
        }
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function assertOutside(string $what): void
    {
        if (self::$active) {
            throw new \RuntimeException("A auditoria não chama {$what} durante a corrida: usa só o que estiver guardado.");
        }
    }
}
