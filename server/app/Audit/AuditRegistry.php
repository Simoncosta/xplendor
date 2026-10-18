<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\AuditRule;

/**
 * XPLENDOR — Auditoria: o registo central das regras (config/auditoria.php → rules). Cada ramo
 * (restauração, automóvel, tracking...) só acrescenta as suas classes à lista.
 */
final class AuditRegistry
{
    /** Os ecrãs com botão "Auditoria": correm só as regras dessa área que declaram o ecrã. */
    public const SCREENS = ['artigos', 'familias', 'faturas', 'fornecedores', 'fichas', 'stock', 'armazens', 'transferencias', 'menu', 'unidades'];
    /** Origens que correm todas as regras da área. */
    public const WHOLE_AREA = ['central', 'noturna', 'diaria'];

    /** @return list<AuditRule> */
    public static function all(): array
    {
        return array_values(array_map(fn (string $class) => app($class), (array) config('auditoria.rules', [])));
    }

    public static function get(string $code): ?AuditRule
    {
        foreach (self::all() as $rule) {
            if ($rule->code() === $code) {
                return $rule;
            }
        }

        return null;
    }

    /** @return list<string> os códigos das regras sensíveis (com nomes de pessoas): só o administrador e o dono */
    public static function sensitiveCodes(): array
    {
        return array_values(array_map(fn (AuditRule $r) => $r->code(), array_filter(self::all(), fn (AuditRule $r) => method_exists($r, 'sensitive') && $r->sensitive())));
    }

    /** @return list<string> os códigos das regras de uma família (ex.: "dinheiro", "unidades") */
    public static function familyCodes(string $family): array
    {
        return array_values(array_map(fn (AuditRule $r) => $r->code(), array_filter(self::all(), fn (AuditRule $r) => method_exists($r, 'family') && $r->family() === $family)));
    }

    /** @return list<string> as áreas que têm regras */
    public static function areas(): array
    {
        return array_values(array_unique(array_map(fn (AuditRule $r) => $r->area(), self::all())));
    }

    /** @return list<AuditRule> as regras de uma área, todas (central, noturna, diária) ou só as de um ecrã */
    public static function forScope(string $area, string $origin): array
    {
        return array_values(array_filter(self::all(), fn (AuditRule $r) => $r->area() === $area
            && (in_array($origin, self::WHOLE_AREA, true) || in_array($origin, $r->screens(), true))));
    }

    public static function origins(): array
    {
        return array_merge(self::WHOLE_AREA, self::SCREENS);
    }
}
