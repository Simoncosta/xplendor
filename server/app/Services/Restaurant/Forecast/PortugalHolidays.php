<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Forecast;

use App\Services\Calendar\PortugalCalendar;

/**
 * Fatia 6a: os feriados de Portugal (os nacionais, com os móveis: Sexta-feira Santa, Páscoa e
 * Corpo de Deus) e o municipal da empresa (Configurações › Restauração e Auditoria, "dd/mm").
 * Bússola 5: as datas vêm da fonte única (App\Services\Calendar\PortugalCalendar).
 */
final class PortugalHolidays
{
    /** O domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    public static function easter(int $year): \DateTimeImmutable
    {
        return PortugalCalendar::easter($year);
    }

    /**
     * @param  string  $municipal  "dd/mm" (vazio = sem feriado municipal)
     * @return array<string, string> data (AAAA-MM-DD) => nome
     */
    public static function forYear(int $year, string $municipal = ''): array
    {
        return PortugalCalendar::forYear($year, $municipal);
    }

    /** @return array<string, string> os feriados entre duas datas (inclusive) */
    public static function between(string $from, string $to, string $municipal = ''): array
    {
        return PortugalCalendar::between($from, $to, $municipal);
    }
}
