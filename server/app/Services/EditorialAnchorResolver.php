<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentAnchor;
use App\Models\EditorialOwnAnchor;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Linha Editorial: RESOLVEDOR. Dada uma âncora (regra) + um ano, calcula a
 * DATA concreta. As móveis dependem da Páscoa, calculada pelo COMPUTUS (algoritmo
 * "Anonymous Gregorian" / Gauss) — sem ele, Carnaval/Sexta-Santa/Corpo de Deus saem
 * errados. Função pura (não toca na BD).
 */
class EditorialAnchorResolver
{
    /**
     * Domingo de Páscoa (calendário gregoriano) do ano dado — Computus.
     * Ex.: 2024→31/03, 2025→20/04, 2026→05/04.
     */
    public function easter(int $year): CarbonImmutable
    {
        // Bússola 5: o Computus vive só na fonte única dos feriados.
        return CarbonImmutable::instance(\App\Services\Calendar\PortugalCalendar::easter($year))->startOfDay();
    }

    /**
     * O n-ésimo dia-da-semana de um mês. ordinal 1..5 (1º..5º) ou -1 (último).
     * weekday: 0=domingo .. 6=sábado (igual ao Carbon::dayOfWeek).
     */
    public function nthWeekday(int $year, int $month, int $ordinal, int $weekday): CarbonImmutable
    {
        if ($ordinal === -1) {
            $d = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();
            while ($d->dayOfWeek !== $weekday) {
                $d = $d->subDay();
            }

            return $d;
        }

        $d = CarbonImmutable::create($year, $month, 1);
        while ($d->dayOfWeek !== $weekday) {
            $d = $d->addDay();
        }

        return $d->addDays(7 * ($ordinal - 1));
    }

    /**
     * Resolve uma âncora para um ano. Devolve um shape normalizado:
     *  - dia único:  ['type'=>'day', 'date'=>'Y-m-d']
     *  - intervalo:  ['type'=>'range', 'start'=>'Y-m-d', 'end'=>'Y-m-d']
     */
    public function resolve(ContentAnchor|EditorialOwnAnchor $a, int $year): array
    {
        $r = $this->resolveRule($a, $year);
        // Bússola 6: um prazo (IMI, IRS) que calha num fim de semana ou feriado nacional passa para o dia útil seguinte.
        if ($a instanceof ContentAnchor && $a->next_business_day) {
            if ($r['type'] === 'day') {
                $r['date'] = self::nextBusinessDay($r['date']);
            } else {
                $r['end'] = self::nextBusinessDay($r['end']);
            }
        }

        return $r;
    }

    /** O próprio dia, se for útil; senão o dia útil seguinte (sem sábados, domingos e feriados nacionais). */
    public static function nextBusinessDay(string $date): string
    {
        $d = CarbonImmutable::parse($date);
        $holidays = \App\Services\Calendar\PortugalCalendar::national((int) $d->year) + \App\Services\Calendar\PortugalCalendar::national((int) $d->year + 1);
        while ($d->isWeekend() || isset($holidays[$d->toDateString()])) {
            $d = $d->addDay();
        }

        return $d->toDateString();
    }

    private function resolveRule(ContentAnchor|EditorialOwnAnchor $a, int $year): array
    {
        switch ($a->rule_type) {
            case 'fixa':
                return ['type' => 'day', 'date' => CarbonImmutable::create($year, (int) $a->month, (int) $a->day)->toDateString()];

            case 'nth_weekday':
                return ['type' => 'day', 'date' => $this->nthWeekday($year, (int) $a->month, (int) $a->ordinal, (int) $a->weekday)->toDateString()];

            case 'periodo':
                return [
                    'type'  => 'range',
                    'start' => CarbonImmutable::create($year, (int) $a->start_month, (int) $a->start_day)->toDateString(),
                    'end'   => CarbonImmutable::create($year, (int) $a->end_month, (int) $a->end_day)->toDateString(),
                ];

            case 'relativa_pascoa':
                return ['type' => 'day', 'date' => $this->easter($year)->addDays((int) $a->easter_offset)->toDateString()];

            default:
                throw new \InvalidArgumentException("Tipo de regra desconhecido: {$a->rule_type}");
        }
    }
}
