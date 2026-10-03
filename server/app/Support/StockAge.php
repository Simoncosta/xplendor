<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Car;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — DIAS EM STOCK: uma única fonte de verdade (PHP e SQL).
 *
 *   entryDate()   = car_created_at ?? created_at
 *                   (car_created_at é a data oficial de entrada, vinda do DMS/Carmine;
 *                   created_at é quando o registo entrou no XPLENDOR)
 *   daysInStock() = dias de CALENDÁRIO de startOfDay(entrada) até:
 *                     sold_at  (se status = 'sold' e houver sold_at)
 *                     ?? asOf  (data de referência, ex.: um cálculo histórico)
 *                     ?? hoje
 *                   nunca negativo.
 *   sqlExpr()     = a MESMA regra em SQL portável (MariaDB / SQLite dos testes).
 *
 * Dias de calendário (não horas/24): uma viatura que entra às 23h e é vista às 9h
 * do dia seguinte tem 1 dia — igual em PHP e em SQL (DATEDIFF ignora as horas).
 * Há um teste que prova PHP == SQL num conjunto de casos.
 */
final class StockAge
{
    public static function entryDate(Car $car): ?CarbonInterface
    {
        return $car->car_created_at ?? $car->created_at;
    }

    public static function daysInStock(Car $car, ?CarbonInterface $asOf = null): ?int
    {
        $entry = self::entryDate($car);
        if ($entry === null) {
            return null;
        }

        $end = ($car->status === 'sold' && $car->sold_at !== null)
            ? $car->sold_at
            : ($asOf ?? CarbonImmutable::now());

        $from = CarbonImmutable::parse($entry)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        return max(0, (int) $from->diffInDays($to, false));
    }

    /**
     * Expressão SQL equivalente a daysInStock(), para filtros/ordenação/agregados.
     * $table = tabela ou alias de `cars`. $asOf = data de referência (omissão: hoje,
     * calculado em PHP — NÃO o CURRENT_DATE da BD, para nunca divergir do PHP por
     * causa de fusos horários). O literal é gerado aqui (não é input do utilizador).
     */
    public static function sqlExpr(string $table = 'cars', ?CarbonInterface $asOf = null): string
    {
        $t = $table;
        $ref = ($asOf ?? CarbonImmutable::now())->toDateString();
        $entry = "COALESCE({$t}.car_created_at, {$t}.created_at)";
        $end = "CASE WHEN {$t}.status = 'sold' AND {$t}.sold_at IS NOT NULL THEN {$t}.sold_at ELSE '{$ref}' END";

        if (DB::connection()->getDriverName() === 'sqlite') {
            $diff = "CAST(julianday(date({$end})) - julianday(date({$entry})) AS INTEGER)";

            return "(CASE WHEN {$diff} < 0 THEN 0 ELSE {$diff} END)";
        }

        return "GREATEST(0, DATEDIFF({$end}, {$entry}))";
    }
}
