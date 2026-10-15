<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — que dias de vendas (pingwin_daily_sales) existem, por loja, num período. É a regra
 * única dos ecrãs da Restauração com loja e período (calendário, dashboard, faturação mensal,
 * bloco de marketing), para nunca mostrar zeros falsos:
 *
 *  · uma loja só "deve ter" vendas a partir do seu início: a abertura indicada (opened_on), senão
 *    o primeiro dia das vendas (sales_since), senão o primeiro dia lido;
 *  · um dia TEM vendas de uma loja quando há a linha desse dia (um dia lido sem vendas é uma
 *    linha a zero: esse sim é zero real). Sem a linha, o dia está EM FALTA, não a zero;
 *  · um dia está COMPLETO quando todas as lojas que já tinham aberto têm a linha e o dia foi
 *    sincronizado (pingwin_sync_runs). Só os períodos completos se comparam;
 *  · uma loja sem nenhum dia esperado no período (ainda não tinha aberto) ou sem nenhum dia lido
 *    fica "sem dados neste período"; "todas as lojas" soma só as que têm dados e diz quais faltam.
 */
final class RestaurantSalesCoverage
{
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';
    public const NO_DATA = 'no_data';
    public const NOT_OPEN = 'not_open';

    /** Quantos intervalos em falta se escrevem na nota (o resto vai em "e mais N dias"). */
    private const NOTE_RANGES = 4;

    /**
     * @return array{
     *   from: string, to: string, status: string, complete: bool, note: ?string,
     *   locations: list<array{id: int, name: string, start: ?string, status: string, expected_days: int, present_days: int, missing_days: int, missing_ranges: list<array{0: string, 1: string}>}>,
     *   days: array<string, array{expected: list<int>, present: list<int>, synced: bool}>
     * }
     */
    public function period(int $companyId, string $from, string $to, ?int $locationId = null): array
    {
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)
            ->when($locationId !== null, fn ($q) => $q->whereKey($locationId))
            ->orderBy('display_name')->get();
        $ids = $locations->pluck('id')->all();

        $present = []; // [loc][Y-m-d] = true
        if ($ids !== [] && $from <= $to) {
            DB::table('pingwin_daily_sales')->where('company_id', $companyId)->whereIn('location_id', $ids)
                ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw('location_id, DATE(business_date) as d')->get()
                ->each(function ($r) use (&$present) {
                    $present[(int) $r->location_id][substr((string) $r->d, 0, 10)] = true;
                });
        }
        $synced = $from <= $to ? PingwinSyncRun::where('company_id', $companyId)->where('status', 'success')
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])->pluck('business_date')
            ->mapWithKeys(fn ($d) => [substr((string) $d, 0, 10) => true])->all() : [];

        $starts = $this->starts($locations);
        $dates = $this->dates($from, $to);
        $days = [];
        foreach ($dates as $d) {
            $days[$d] = ['expected' => [], 'present' => [], 'synced' => isset($synced[$d])];
        }

        $out = [];
        foreach ($locations as $loc) {
            $start = $starts[$loc->id];
            $expected = 0;
            $have = 0;
            $missing = [];
            foreach ($dates as $d) {
                if ($start === null || $d < $start) {
                    continue;
                }
                $expected++;
                $days[$d]['expected'][] = $loc->id;
                if (isset($present[$loc->id][$d])) {
                    $have++;
                    $days[$d]['present'][] = $loc->id;
                } else {
                    $missing[] = $d;
                }
            }
            $status = match (true) {
                $start !== null && $start > $to => self::NOT_OPEN,
                $have === 0                      => self::NO_DATA,
                $missing !== []                  => self::PARTIAL,
                default                          => self::COMPLETE,
            };
            $out[] = [
                'id' => $loc->id, 'name' => self::name($loc), 'start' => $start, 'status' => $status,
                'expected_days' => $expected, 'present_days' => $have, 'missing_days' => count($missing),
                'missing_ranges' => self::ranges($missing),
            ];
        }

        $withData = array_filter($out, fn ($l) => in_array($l['status'], [self::COMPLETE, self::PARTIAL], true));
        $status = match (true) {
            $withData === [] => self::NO_DATA,
            count(array_filter($out, fn ($l) => $l['status'] === self::COMPLETE || $l['status'] === self::NOT_OPEN)) === count($out)
                && $this->allSynced($days) => self::COMPLETE,
            default => self::PARTIAL,
        };

        return [
            'from' => $from, 'to' => $to, 'status' => $status, 'complete' => $status === self::COMPLETE,
            'note' => self::note($out), 'locations' => $out, 'days' => $days,
        ];
    }

    /** O dia está completo (todas as lojas abertas com vendas e o dia sincronizado)? */
    public static function dayComplete(array $coverage, string $date): bool
    {
        $d = $coverage['days'][$date] ?? null;

        return $d !== null && $d['synced'] && $d['expected'] !== [] && count($d['present']) === count($d['expected']);
    }

    /** O dia tem vendas de pelo menos uma loja? (sem nenhuma → "sem dados", nunca zero). */
    public static function dayHasData(array $coverage, string $date): bool
    {
        return ($coverage['days'][$date]['present'] ?? []) !== [];
    }

    /** Os nomes das lojas que já tinham aberto e não têm as vendas deste dia. */
    public static function dayMissing(array $coverage, string $date): array
    {
        $d = $coverage['days'][$date] ?? null;
        if (! $d) {
            return [];
        }
        $names = array_column($coverage['locations'], 'name', 'id');

        return array_values(array_map(fn ($id) => $names[$id], array_diff($d['expected'], $d['present'])));
    }

    /** O resumo de uma loja (ou de todas), sem a grelha dos dias, para devolver à UI. */
    public static function compact(array $coverage): array
    {
        return [
            'status' => $coverage['status'],
            'complete' => $coverage['complete'],
            'note' => $coverage['note'],
            'locations' => array_map(fn ($l) => [
                'id' => $l['id'], 'name' => $l['name'], 'start' => $l['start'], 'status' => $l['status'],
                'missing_days' => $l['missing_days'], 'missing_ranges' => $l['missing_ranges'],
            ], $coverage['locations']),
        ];
    }

    /** Início das vendas de cada loja: abertura, senão sales_since, senão o primeiro dia lido. */
    private function starts(Collection $locations): array
    {
        $first = $locations->isEmpty() ? collect() : DB::table('pingwin_daily_sales')->whereIn('location_id', $locations->pluck('id'))
            ->groupBy('location_id')->selectRaw('location_id, MIN(DATE(business_date)) as d')->pluck('d', 'location_id');
        $out = [];
        foreach ($locations as $loc) {
            $start = $loc->opened_on ?? $loc->sales_since;
            $out[$loc->id] = $start ? $start->toDateString() : (isset($first[$loc->id]) ? substr((string) $first[$loc->id], 0, 10) : null);
        }

        return $out;
    }

    /** Todos os dias do período sincronizados (sem isso, um início de loja só deduzido do 1.º dia lido enganava). */
    private function allSynced(array $days): bool
    {
        foreach ($days as $d) {
            if (! $d['synced']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** ['2026-05-29','2026-05-30','2026-06-02'] → [['2026-05-29','2026-05-30'], ['2026-06-02','2026-06-02']] */
    private static function ranges(array $dates): array
    {
        $out = [];
        foreach ($dates as $d) {
            $last = array_key_last($out);
            if ($last !== null && CarbonImmutable::parse($out[$last][1])->addDay()->toDateString() === $d) {
                $out[$last][1] = $d;
            } else {
                $out[] = [$d, $d];
            }
        }

        return $out;
    }

    /** A nota em português: as lojas sem dados (e porquê) e os dias que faltam. null = nada a dizer. */
    private static function note(array $locations): ?string
    {
        $parts = [];
        $day = fn (string $d) => CarbonImmutable::parse($d)->format('d/m');
        foreach ($locations as $l) {
            if ($l['status'] === self::NOT_OPEN) {
                $parts[] = "Sem dados de {$l['name']} neste período (só tem vendas desde " . CarbonImmutable::parse($l['start'])->format('d/m/Y') . ').';
            } elseif ($l['status'] === self::NO_DATA && $l['missing_days'] === 0) {
                $parts[] = "Sem dados de {$l['name']} neste período.";
            } elseif ($l['status'] === self::NO_DATA || $l['status'] === self::PARTIAL) {
                // A loja já tinha aberto: os dias sem vendas lidas estão EM FALTA (não a zero).
                $shown = array_slice($l['missing_ranges'], 0, self::NOTE_RANGES);
                $text = array_map(fn ($r) => $r[0] === $r[1] ? $day($r[0]) : $day($r[0]) . ' a ' . $day($r[1]), $shown);
                $rest = $l['missing_days'] - array_sum(array_map(fn ($r) => (int) CarbonImmutable::parse($r[0])->diffInDays(CarbonImmutable::parse($r[1])) + 1, $shown));
                $list = count($text) > 1 ? implode(', ', array_slice($text, 0, -1)) . ' e ' . end($text) : $text[0];
                $parts[] = "Faltam as vendas de {$l['name']} " . ($l['missing_days'] === 1 ? 'no dia ' : "em {$l['missing_days']} dias: ") . $list
                    . ($rest > 0 ? " e mais {$rest} " . ($rest === 1 ? 'dia' : 'dias') : '') . '.';
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    public static function name(PingwinLocation $l): string
    {
        return $l->display_name ?: $l->winrest_name ?: (string) $l->winrest_store_id;
    }
}
