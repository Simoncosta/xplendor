<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\CmReservationHourly;
use App\Models\CmReservationShiftSummary;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — F2: mapa de calor da semana (dia da semana × hora), por loja.
 *  · vendas: média do valor sem IVA por hora, nos dias lidos das "Vendas por hora";
 *  · pessoas: média das pessoas com reserva por hora de chegada (CoverManager), nos dias
 *    com reservas lidas.
 * Média = soma ÷ número de dias desse dia da semana com dados (um dia lido sem vendas conta
 * como zero). Janela: as últimas N semanas completas até ontem, a partir do início efetivo
 * da loja (a abertura indicada, se houver; senão o primeiro dia com vendas). Só agregados.
 * "Ontem" é sempre o de Lisboa (o dia do restaurante). Com $excludeSpecial (a Bússola: 8
 * semanas, como os períodos fracos), os feriados e as datas especiais ficam fora da média.
 */
class RestaurantHeatmapService
{
    public const DEFAULT_WEEKS = 12;
    /** Ordem das horas no mapa: o dia do restaurante começa às 5h e acaba às 4h. */
    public const HOUR_ORDER = [5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 0, 1, 2, 3, 4];

    public function build(int $companyId, ?int $locationId = null, int $weeks = self::DEFAULT_WEEKS, bool $excludeSpecial = false): array
    {
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
        $enabled = PingwinItemSalesService::isEnabled($companyId);
        $base = [
            'enabled' => $enabled,
            'locations' => $locations->map(fn (PingwinLocation $l) => ['id' => $l->id, 'name' => $this->name($l)])->values()->all(),
        ];
        $location = $locationId ? $locations->firstWhere('id', $locationId) : $locations->first();
        if (! $enabled || ! $location) {
            return $base + ['location_id' => $location?->id, 'weeks' => $weeks, 'hours' => [], 'sales' => null, 'guests' => null];
        }

        $to = CarbonImmutable::now('Europe/Lisbon')->startOfDay()->subDay();
        $from = $to->subDays($weeks * 7 - 1);
        $start = $location->opened_on ?? $location->sales_since;
        if ($start && CarbonImmutable::parse($start)->gt($from)) {
            $from = CarbonImmutable::parse($start)->startOfDay();
        }
        [$f, $t] = [$from->toDateString(), $to->toDateString()];
        $special = $excludeSpecial ? app(RestaurantSpecialDays::class)->between(\App\Models\Company::findOrFail($companyId), $f, $t) : [];

        // Vendas: dias lidos (com ou sem vendas), sem os vazios por confirmar.
        $salesDays = PingwinHourlySalesDay::where('location_id', $location->id)->whereBetween('business_date', [$f, $t])
            ->whereNotIn('status', PingwinItemSalesDay::STATUSES_TO_REREAD)->pluck('business_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->reject(fn ($d) => isset($special[$d]))->values()->all();
        $salesRows = PingwinHourlySale::where('location_id', $location->id)->whereIn('business_date', $salesDays)
            ->get(['business_date', 'hour', 'net_cents'])
            ->map(fn ($r) => [substr((string) $r->business_date, 0, 10), (int) $r->hour, (int) $r->net_cents])->all();

        // Pessoas: dias com reservas lidas (há resumo por turno) e as pessoas por hora de chegada.
        $guestDays = CmReservationShiftSummary::where('location_id', $location->id)->whereBetween('business_date', [$f, $t])
            ->distinct()->pluck('business_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->reject(fn ($d) => isset($special[$d]))->values()->all();
        $guestRows = CmReservationHourly::where('location_id', $location->id)->whereIn('business_date', $guestDays)
            ->get(['business_date', 'hour', 'guests_total'])
            ->map(fn ($r) => [substr((string) $r->business_date, 0, 10), (int) $r->hour, (int) $r->guests_total])->all();

        $sales = $this->matrix($salesDays, $salesRows);
        $guests = $this->matrix($guestDays, $guestRows);
        $used = array_unique([...$sales['hours_used'], ...$guests['hours_used']]);
        $hours = array_values(array_filter(self::HOUR_ORDER, fn ($h) => in_array($h, $used, true)));

        return $base + [
            'location_id' => $location->id,
            'weeks' => $weeks,
            'special_excluded' => count($special),
            'from' => $f,
            'to' => $t,
            'hours' => $hours,
            'sales' => ['unit' => 'cents', 'cells' => $sales['cells'], 'days' => $sales['days'], 'max' => $sales['max']],
            'guests' => ['unit' => 'people', 'cells' => $guests['cells'], 'days' => $guests['days'], 'max' => $guests['max']],
        ];
    }

    /**
     * @param  array<int, string>  $days  dias com dados
     * @param  array<int, array{0: string, 1: int, 2: int}>  $rows  [dia, hora, valor]
     * @return array{cells: array<int, array<int, float>>, days: array<int, int>, max: float, hours_used: array<int, int>}
     */
    private function matrix(array $days, array $rows): array
    {
        $daysByWeekday = array_fill(1, 7, 0);
        foreach (array_unique($days) as $d) {
            $daysByWeekday[CarbonImmutable::parse($d)->dayOfWeekIso]++;
        }
        $sums = [];
        $used = [];
        foreach ($rows as [$date, $hour, $value]) {
            $wd = CarbonImmutable::parse($date)->dayOfWeekIso;
            $sums[$wd][$hour] = ($sums[$wd][$hour] ?? 0) + $value;
            if ($value > 0) {
                $used[$hour] = true;
            }
        }
        $cells = [];
        $max = 0.0;
        foreach ($sums as $wd => $byHour) {
            foreach ($byHour as $hour => $sum) {
                $avg = $daysByWeekday[$wd] > 0 ? round($sum / $daysByWeekday[$wd], 1) : 0.0;
                $cells[$wd][$hour] = $avg;
                $max = max($max, $avg);
            }
        }

        return ['cells' => $cells, 'days' => $daysByWeekday, 'max' => $max, 'hours_used' => array_keys($used)];
    }

    private function name(PingwinLocation $location): string
    {
        return $location->display_name ?: $location->winrest_name ?: (string) $location->winrest_store_id;
    }

    /** As vendas em intensidade (0 a 100 da hora mais forte), sem euros. */
    public static function relativeSales(array $map): array
    {
        if (! is_array($map['sales'] ?? null)) {
            return $map;
        }
        $max = (int) ($map['sales']['max'] ?? 0);
        $cells = [];
        foreach ((array) ($map['sales']['cells'] ?? []) as $wd => $hours) {
            foreach ((array) $hours as $h => $v) {
                $cells[$wd][$h] = $max > 0 ? (int) round((float) $v / $max * 100) : 0;
            }
        }
        $map['sales'] = ['unit' => 'relative', 'cells' => $cells, 'days' => $map['sales']['days'] ?? [], 'max' => $max > 0 ? 100 : 0];

        return $map;
    }
}
