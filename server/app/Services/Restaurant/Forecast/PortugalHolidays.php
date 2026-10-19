<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Forecast;

/**
 * Fatia 6a: os feriados de Portugal (os nacionais, com os móveis: Sexta-feira Santa, Páscoa e
 * Corpo de Deus) e o municipal da empresa (Configurações › Restauração e Auditoria, "dd/mm").
 */
final class PortugalHolidays
{
    private const FIXED = [
        '01-01' => 'Ano Novo', '04-25' => 'Dia da Liberdade', '05-01' => 'Dia do Trabalhador', '06-10' => 'Dia de Portugal',
        '08-15' => 'Assunção de Nossa Senhora', '10-05' => 'Implantação da República', '11-01' => 'Dia de Todos os Santos',
        '12-01' => 'Restauração da Independência', '12-08' => 'Imaculada Conceição', '12-25' => 'Natal',
    ];

    /** O domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * @param  string  $municipal  "dd/mm" (vazio = sem feriado municipal)
     * @return array<string, string> data (AAAA-MM-DD) => nome
     */
    public static function forYear(int $year, string $municipal = ''): array
    {
        $out = [];
        foreach (self::FIXED as $md => $name) {
            $out["{$year}-{$md}"] = $name;
        }
        $easter = self::easter($year);
        $out[$easter->modify('-2 days')->format('Y-m-d')] = 'Sexta-feira Santa';
        $out[$easter->format('Y-m-d')] = 'Páscoa';
        $out[$easter->modify('+60 days')->format('Y-m-d')] = 'Corpo de Deus';
        if (preg_match('/^(\d{2})\/(\d{2})$/', $municipal, $m)) {
            $out[sprintf('%04d-%s-%s', $year, $m[2], $m[1])] = $out[sprintf('%04d-%s-%s', $year, $m[2], $m[1])] ?? 'Feriado municipal';
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> os feriados entre duas datas (inclusive) */
    public static function between(string $from, string $to, string $municipal = ''): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach (self::forYear($y, $municipal) as $d => $n) {
                if ($d >= $from && $d <= $to) {
                    $out[$d] = $n;
                }
            }
        }

        return $out;
    }
}
