<?php

declare(strict_types=1);

namespace App\Services\Radar\Filter;

use App\Services\Radar\RadarText;

/**
 * Radar: procura as listas do Lexicon num texto normalizado, por palavras inteiras (e
 * expressões), com "*" como radical. As expressões regulares compilam-se uma vez por lista.
 */
final class TermMatcher
{
    /** @var array<string, string> */
    private static array $compiled = [];

    /** O texto pronto para procurar: cada parte normalizada, separadas para nenhuma expressão saltar de uma para outra. */
    public static function haystack(array $texts): string
    {
        $parts = array_filter(array_map(fn ($t) => RadarText::normalize((string) $t), $texts), fn ($t) => $t !== '');

        return ' ' . implode(' | ', $parts) . ' ';
    }

    /** As entradas da lista que aparecem no texto (já preparado por haystack()). */
    public static function matches(string $haystack, array $terms): array
    {
        if (trim($haystack) === '' || $terms === []) {
            return [];
        }
        preg_match_all(self::regex($terms), $haystack, $m);

        return array_values(array_unique($m[0] ?? []));
    }

    public static function any(string $haystack, array $terms): bool
    {
        return $terms !== [] && trim($haystack) !== '' && preg_match(self::regex($terms), $haystack) === 1;
    }

    /** Siglas e nomes próprios com maiúsculas, no texto original (os títulos todos em maiúsculas não contam). */
    public static function anyCaseSensitive(array $texts, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }
        $regex = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn ($t) => preg_quote($t, '/'), $terms)) . ')(?![\p{L}\p{N}])/u';
        foreach ($texts as $text) {
            $text = (string) $text;
            if (preg_match('/\p{Ll}/u', $text) && preg_match($regex, $text)) {
                return true;
            }
        }

        return false;
    }

    private static function regex(array $terms): string
    {
        $key = md5(implode("\0", $terms));
        if (! isset(self::$compiled[$key])) {
            $alts = [];
            foreach ($terms as $term) {
                $stem = str_ends_with($term, '*');
                $norm = RadarText::normalize($stem ? substr($term, 0, -1) : $term);
                if ($norm === '') {
                    continue;
                }
                $alts[] = preg_quote($norm, '/') . ($stem ? '[a-z0-9]*' : '');
            }
            // As mais compridas primeiro, para "policia judiciaria" ganhar a "policia".
            usort($alts, fn ($a, $b) => strlen($b) <=> strlen($a));
            self::$compiled[$key] = '/(?<= )(?:' . implode('|', $alts) . ')(?= )/';
        }

        return self::$compiled[$key];
    }
}
