<?php

declare(strict_types=1);

namespace App\Services\Compass;

/**
 * As jogadas da semana: no máximo uma por tipo (a mais forte de cada tipo) e no máximo $max no
 * total, as mais fortes primeiro. A força é do fornecedor (um array comparável com <=>).
 */
final class CompassPlayChooser
{
    public const MAX_PLAYS = 3;

    /**
     * @param array<string, list<array>> $groups os grupos candidatos, por tipo
     * @param callable(array): array $strength
     */
    public static function choose(array $groups, callable $strength, int $max = self::MAX_PLAYS): array
    {
        $best = [];
        foreach ($groups as $list) {
            usort($list, fn ($a, $b) => $strength($b) <=> $strength($a));
            $best[] = $list[0];
        }
        usort($best, fn ($a, $b) => $strength($b) <=> $strength($a));

        return array_slice($best, 0, $max);
    }
}
