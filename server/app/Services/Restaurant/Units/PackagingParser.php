<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use Illuminate\Support\Str;

/**
 * XPLENDOR — Auditoria v2: a embalagem escrita na descrição do fornecedor ("CAFÉ GRÃO 1KG", "24X0,33",
 * "6X1L", "C/12", "GR24", "PACK 6", "BIDON 30L", "PEDRAS TR 0.25X0.24 GR"). Só uma pista: nunca se aplica
 * sozinha; serve de evidência ao perfil de unidades e às regras U.
 *
 * Resultado: o conteúdo de cada peça (each, na unidade da família: kg, L ou un), quantas peças há
 * (count), o total (quantity = count × each) e o recipiente (bidon, saco, barril, caixa, grade, pack).
 */
final class PackagingParser
{
    /** Unidade escrita → [família, quanto vale na unidade da família]. */
    private const UNITS = [
        'KGS' => ['kg', 1.0], 'KG' => ['kg', 1.0], 'KILOS' => ['kg', 1.0], 'KILO' => ['kg', 1.0],
        'GRS' => ['kg', 0.001], 'GR' => ['kg', 0.001], 'G' => ['kg', 0.001],
        'LTS' => ['L', 1.0], 'LT' => ['L', 1.0], 'LITROS' => ['L', 1.0], 'LITRO' => ['L', 1.0], 'L' => ['L', 1.0],
        'CL' => ['L', 0.01], 'ML' => ['L', 0.001],
    ];

    private const UNIT_RX = 'KGS|KG|KILOS|KILO|GRS|GR|G|LTS|LT|LITROS|LITRO|L|CL|ML';

    private const CONTAINERS = [
        'bidon' => '/\bBID(?:ON|AO)\b/', 'barril' => '/\bBARRIL\b/', 'saco' => '/\bSACO\b/', 'grade' => '/\bGR(?:ADE|D)?\s?\d+\b|\bGRADE\b/',
        'caixa' => '/\bCX\b|\bCAIXA\b|\bC\/\s?\d+\b|\bCX\s?\d+\b/', 'pack' => '/\bPACK\b|\bPK\s?\d+\b/', 'garrafa' => '/\bGARRAF(?:A|AO)\b|\bGRF\b/',
        'lata' => '/\bLATA\b/', 'pacote' => '/\bPACOTE\b/', 'frasco' => '/\bFRASCO\b/', 'balde' => '/\bBALDE\b/',
    ];

    /**
     * @return array{family:string, each:float, count:int, quantity:float, container:?string, text:string, label:string}|null
     */
    public static function parse(?string $description): ?array
    {
        $t = Str::upper(Str::ascii(str_replace("\u{FEFF}", '', (string) $description)));
        $t = preg_replace('/\s+/', ' ', trim($t)) ?? $t;
        if ($t === '') {
            return null;
        }
        $container = self::container($t);
        $u = self::UNIT_RX;

        // Grade escrita como "0.25X0.24 GR": 24 garrafas de 0,25 L.
        if (preg_match('/(?<![\d.,])(\d+[.,]\d+)\s*(' . $u . ')?\s*X\s*0[.,](\d{1,3})\s*(?:GR|GRD|GRADE)\b/', $t, $m)) {
            return self::result('L', self::num($m[1]) * self::factor($m[2] ?? 'L', 'L'), (int) $m[3], 'grade', $m[0]);
        }
        // "24X0,33", "6X1L", "24 X 33CL", "12X1KG"
        if (preg_match('/(?<![\d.,])(\d{1,3})\s*X\s*(\d+(?:[.,]\d+)?)\s*(' . $u . ')?\b/', $t, $m) && (int) $m[1] > 1) {
            [$family, $each] = self::sized(self::num($m[2]), $m[3] ?? '');
            if ($each > 0) {
                return self::result($family, $each, (int) $m[1], $container, $m[0]);
            }
        }
        // "0,33X24", "1,5L X 6"
        if (preg_match('/(?<![\d.,])(\d+[.,]\d+|\d+)\s*(' . $u . ')?\s*X\s*(\d{1,3})\b(?![.,]\d)/', $t, $m) && (int) $m[3] > 1) {
            [$family, $each] = self::sized(self::num($m[1]), $m[2] ?? '');
            if ($each > 0) {
                return self::result($family, $each, (int) $m[3], $container, $m[0]);
            }
        }

        // Tamanho de cada peça ("1KG", "500G", "0,75L", "30LT", "33CL"); o último é o da embalagem.
        $size = null;
        if (preg_match_all('/(?<![\d.,A-Z])(\d+(?:[.,]\d+)?)\s*(' . $u . ')\b/', $t, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                // "GR24" (grade) não é gramas: aqui o número vem antes da unidade, não depois.
                $q = self::num($m[1]) * self::UNITS[$m[2]][1];
                if ($q > 0) {
                    $size = [self::UNITS[$m[2]][0], $q, $m[0]];
                }
            }
        }
        // Peças por embalagem ("C/12", "CX12", "CX 12", "GR24", "GR 24", "PACK 6", "PK6", "12UN", "X24").
        $count = null;
        if (preg_match('/\bC\/\s?(\d{1,3})\b|\bCX\s?(\d{1,3})\b|\bGR(?:ADE|D)?\s?(\d{1,3})\b(?!\s*(?:' . $u . ')\b)|\bPACK\s?(\d{1,3})\b|\bPK\s?(\d{1,3})\b|(?<![\d.,])(\d{1,3})\s?(?:UN|UND|UNID|UNIDADES)\b|\bX\s?(\d{1,3})\b/', $t, $m)) {
            $n = (int) (array_values(array_filter(array_slice($m, 1), fn ($v) => $v !== ''))[0] ?? 0);
            if ($n > 1) {
                $count = [$n, $m[0]];
            }
        }
        if ($size && $count) {
            return self::result($size[0], $size[1], $count[0], $container, $size[2] . ' ' . $count[1]);
        }
        if ($size) {
            return self::result($size[0], $size[1], 1, $container, $size[2]);
        }
        if ($count) {
            return self::result('un', 1.0, $count[0], $container, $count[1]);
        }
        if ($container !== null) {
            // Só o recipiente ("BIDON", "SACO", "CX"): sabe-se que é embalado, não quanto leva.
            return ['family' => 'un', 'each' => 0.0, 'count' => 0, 'quantity' => 0.0, 'container' => $container, 'text' => $container, 'label' => self::containerLabel($container)];
        }

        return null;
    }

    /** O tamanho de uma peça sem unidade escrita: "0,33" em bebidas é litro; um inteiro sem unidade é peça. */
    private static function sized(float $n, string $unit): array
    {
        if ($unit !== '') {
            return [self::UNITS[$unit][0], $n * self::UNITS[$unit][1]];
        }
        if ($n > 0 && $n < 5 && floor($n) !== $n) {
            return ['L', $n];
        }

        return ['un', 0.0];
    }

    private static function factor(string $unit, string $family): float
    {
        return isset(self::UNITS[$unit]) && self::UNITS[$unit][0] === $family ? self::UNITS[$unit][1] : 1.0;
    }

    private static function num(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }

    private static function container(string $t): ?string
    {
        foreach (self::CONTAINERS as $name => $rx) {
            if (preg_match($rx, $t)) {
                return $name;
            }
        }

        return null;
    }

    private static function result(string $family, float $each, int $count, ?string $container, string $text): array
    {
        $each = round($each, 6);
        $quantity = round($each * max(1, $count), 6);
        if ($container === null && $count > 1) {
            $container = 'caixa';
        }

        return ['family' => $family, 'each' => $each, 'count' => max(1, $count), 'quantity' => $quantity, 'container' => $container,
            'text' => trim($text), 'label' => self::label($family, $each, max(1, $count), $container)];
    }

    /** "Embalagem de 1 kg", "Grade de 24 × 0,25 L", "Caixa de 12 unidades", "Bidão de 30 L". */
    public static function label(string $family, float $each, int $count, ?string $container): string
    {
        $name = $container ? self::containerLabel($container) : 'Embalagem';
        $size = self::size($family, $each);
        if ($family === 'un') {
            return "{$name} de {$count} unidades";
        }

        return $count > 1 ? "{$name} de {$count} × {$size}" : "{$name} de {$size}";
    }

    public static function size(string $family, float $q): string
    {
        $fmt = fn (float $v) => rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
        if ($family === 'kg') {
            return $q < 1 ? $fmt($q * 1000) . ' g' : $fmt($q) . ' kg';
        }
        if ($family === 'L') {
            return $q < 0.1 ? $fmt($q * 1000) . ' ml' : $fmt($q) . ' L';
        }

        return $fmt($q) . ' un';
    }

    private static function containerLabel(string $c): string
    {
        return ['bidon' => 'Bidão', 'barril' => 'Barril', 'saco' => 'Saco', 'grade' => 'Grade', 'caixa' => 'Caixa', 'pack' => 'Pack',
            'garrafa' => 'Garrafa', 'lata' => 'Lata', 'pacote' => 'Pacote', 'frasco' => 'Frasco', 'balde' => 'Balde'][$c] ?? 'Embalagem';
    }
}
