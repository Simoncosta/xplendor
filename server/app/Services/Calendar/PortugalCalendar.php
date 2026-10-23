<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * A única fonte dos feriados de Portugal na XPLENDOR (Bússola 5): os nacionais obrigatórios, com os
 * móveis (Sexta-feira Santa, Páscoa e Corpo de Deus), e o municipal que a empresa indicou
 * (Configurações, "Feriado municipal", dd/mm). Usam-na a previsão e as sugestões de compra da
 * restauração (Forecast\PortugalHolidays) e os dias especiais dos sinais (RestaurantSpecialDays).
 * As âncoras da Linha Editorial são dados (content_anchors); o teste PortugalCalendarTest garante que
 * os feriados delas são estes.
 *
 * Cada entrada diz a fonte onde se confirma e quando foi confirmada. Os 13 feriados obrigatórios foram
 * confirmados no Código do Trabalho, artigo 234.º, a 23/10/2026 (o Carnaval não é obrigatório).
 */
final class PortugalCalendar
{
    public const SOURCE_CT = ['name' => 'Código do Trabalho, artigo 234.º (feriados obrigatórios)', 'url' => 'https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34546475'];

    public const CONFIRMED_ON = '2026-10-23';

    /** Os feriados de data fixa: mm-dd => nome. */
    public const FIXED = [
        '01-01' => 'Ano Novo', '04-25' => 'Dia da Liberdade', '05-01' => 'Dia do Trabalhador', '06-10' => 'Dia de Portugal',
        '08-15' => 'Assunção de Nossa Senhora', '10-05' => 'Implantação da República', '11-01' => 'Todos os Santos',
        '12-01' => 'Restauração da Independência', '12-08' => 'Imaculada Conceição', '12-25' => 'Natal',
    ];

    /** Os feriados móveis: dias a contar do domingo de Páscoa => nome. */
    public const EASTER = [-2 => 'Sexta-feira Santa', 0 => 'Páscoa', 60 => 'Corpo de Deus'];

    /** De onde vêm as datas e quando foram confirmadas. */
    public static function source(): array
    {
        return ['source' => self::SOURCE_CT, 'confirmed_on' => self::CONFIRMED_ON, 'confirmed_by' => 'person', 'status' => 'confirmada'];
    }

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

    /** Os feriados nacionais de um ano. @return array<string, string> data (AAAA-MM-DD) => nome, por data */
    public static function national(int $year): array
    {
        $out = [];
        foreach (self::FIXED as $md => $name) {
            $out["{$year}-{$md}"] = $name;
        }
        $easter = self::easter($year);
        foreach (self::EASTER as $offset => $name) {
            $out[$easter->modify(sprintf('%+d days', $offset))->format('Y-m-d')] = $name;
        }
        ksort($out);

        return $out;
    }

    /**
     * Os nacionais e o municipal da empresa ("dd/mm"; vazio = sem municipal).
     *
     * @return array<string, string> data => nome
     */
    public static function forYear(int $year, string $municipal = ''): array
    {
        $out = self::national($year);
        if (preg_match('/^(\d{2})\/(\d{2})$/', $municipal, $m) && checkdate((int) $m[2], (int) $m[1], $year)) {
            $out[sprintf('%04d-%s-%s', $year, $m[2], $m[1])] ??= 'Feriado municipal';
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
