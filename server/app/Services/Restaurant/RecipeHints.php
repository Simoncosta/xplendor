<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use Illuminate\Support\Str;

/**
 * XPLENDOR — Fatia 1b: pistas para as sugestões das fichas técnicas (nunca aplicadas sozinhas):
 *  · quantidades escritas nas descrições ("5LT", "20L", "2.5KG", "15KG", "X24", "0.24 GR", "33CL"),
 *    convertidas para a família da unidade base (Litro, Quilograma, Unidade);
 *  · o nome normalizado de um artigo (sem acentos, sem os prefixos MC/PE, sem calibres nem
 *    embalagens) e a semelhança entre dois nomes;
 *  · o arredondamento "bonito" de um fator (19,997 → 20).
 */
final class RecipeHints
{
    /** Família → unidades escritas e quanto vale cada uma na unidade base da família. */
    private const UNITS = [
        'L' => ['LTS' => 1, 'LT' => 1, 'L' => 1, 'CL' => 0.01, 'ML' => 0.001],
        'KG' => ['KGS' => 1, 'KG' => 1, 'GRS' => 0.001, 'GR' => 0.001, 'G' => 0.001],
        'UN' => ['UNID' => 1, 'UND' => 1, 'UN' => 1, 'X' => 1],
    ];

    /** Palavras de embalagem e ligação que não distinguem artigos. */
    private const STOPWORDS = [
        'GARRAFA', 'GARRAFAO', 'GRF', 'LATA', 'BIDON', 'BIDAO', 'BARRIL', 'CAIXA', 'CX', 'PACK', 'EMB', 'EMBALAGEM',
        'SACO', 'FRASCO', 'BALDE', 'PACOTE', 'FARDO', 'TP', 'VIDRO', 'PET', 'DE', 'DA', 'DO', 'DOS', 'DAS', 'E', 'COM',
        'PARA', 'UN', 'UND', 'UNID', 'KG', 'LT', 'LTS', 'GR',
    ];

    /** A família de uma unidade base pelo nome ("Litro" → L, "Quilograma" → KG, "Unidade" → UN). */
    public static function family(?string $description, ?string $shortname = null): ?string
    {
        $t = Str::upper(Str::ascii(trim(($description ?? '') . ' ' . ($shortname ?? ''))));
        if (preg_match('/\b(LITRO|LITROS|LT|L)\b/', $t)) {
            return 'L';
        }
        if (preg_match('/\b(QUILOGRAMA|QUILO|KILO|KG)\b/', $t)) {
            return 'KG';
        }
        if (preg_match('/\b(UNIDADE|UNIDADES|UN|UND)\b/', $t)) {
            return 'UN';
        }

        return null;
    }

    /**
     * As quantidades escritas num texto, já na unidade base da família pedida.
     *
     * @return list<array{quantity: float, text: string}>
     */
    public static function quantitiesInText(?string $text, string $family): array
    {
        $t = Str::upper(Str::ascii(str_replace("\u{FEFF}", '', (string) $text)));
        $units = self::UNITS[$family] ?? [];
        if (! $units || $t === '') {
            return [];
        }
        $out = [];
        // "5LT", "2.5KG", "0,24 GR", "33CL", "24UN"
        if (preg_match_all('/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(LTS|LT|L|CL|ML|KGS|KG|GRS|GR|G|UNID|UND|UN)\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (isset($units[$x[2]])) {
                    $q = (float) str_replace(',', '.', $x[1]) * $units[$x[2]];
                    if ($q > 0) {
                        $out[] = ['quantity' => round($q, 6), 'text' => trim($x[0])];
                    }
                }
            }
        }
        // "X24", "X 24", "24X" (unidades por embalagem)
        if ($family === 'UN' && preg_match_all('/\bX\s?(\d+)\b|\b(\d+)\s?X\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $q = (float) ($x[1] !== '' ? $x[1] : ($x[2] ?? 0));
                if ($q > 1) {
                    $out[] = ['quantity' => $q, 'text' => trim($x[0])];
                }
            }
        }

        return $out;
    }

    /** As palavras que identificam um artigo (sem acentos, prefixos MC/PE, calibres e embalagens). */
    public static function nameTokens(?string $name): array
    {
        $t = Str::upper(Str::ascii(str_replace("\u{FEFF}", '', (string) $name)));
        $t = preg_replace('/^\s*(MC|PE)\b[\s.\-]*/', '', $t) ?? $t;
        // calibres: "0.35", "1,5L", "80/110", "15KG", "X24", "33CL"
        $t = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:LTS|LT|L|CL|ML|KGS|KG|GRS|GR|G|UNID|UND|UN|CM|MM)?\b/', ' ', $t) ?? $t;
        $t = preg_replace('/\b\d+\s*\/\s*\d+\b|\bX\s?\d+\b/', ' ', $t) ?? $t;
        $tokens = preg_split('/[^A-Z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter($tokens, fn ($w) => strlen($w) > 1 && ! ctype_digit($w) && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($tokens));
    }

    /**
     * Semelhança entre dois nomes (pelas palavras, Jaccard): 1 = as mesmas palavras. Conservadora de
     * propósito: "COCA COLA" não é parecida com "COCA COLA ZERO" (2/3). 0 sem palavras.
     */
    public static function similarity(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
    }

    /** 19,997 → 20; 2,4999 → 2,5; o resto com 4 casas. */
    public static function nice(float $v): float
    {
        foreach ([1.0, 0.5, 0.25] as $step) {
            $r = round($v / $step) * $step;
            if ($r > 0 && abs($v - $r) / $r <= 0.02) {
                return $r;
            }
        }

        return round($v, 4);
    }
}
