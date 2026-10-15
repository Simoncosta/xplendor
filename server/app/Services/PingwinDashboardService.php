<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use App\Services\Restaurant\RestaurantSalesCoverage as Coverage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Cálculo do dashboard de restauração (do spike). Agrega
 * pingwin_daily_sales (SUM GROUP BY) em cêntimos e devolve os 3 cards
 * (anual/mensal/diário) com OS DOIS valores (faturado c/IVA + líquido) e as
 * comparações (vs ano passado / vs mesmo dia da semana). Portão de honestidade
 * (RestaurantSalesCoverage, a mesma regra em todos os ecrãs):
 *  · uma loja só conta a partir do seu início (abertura, senão o início das vendas);
 *  · um dia sem a linha de vendas de uma loja está EM FALTA, não a zero: um período sem
 *    nenhuma loja com dados devolve null ("sem dados"), e cada card diz o que falta;
 *  · a comparação só aparece com os DOIS períodos completos (todas as lojas abertas com
 *    vendas e o dia sincronizado); senão delta_pct = null → o ecrã mostra "—".
 * Tudo scoped por company_id.
 */
class PingwinDashboardService
{
    /** A loja já tinha começado nesse dia: abertura, senão o início das vendas (sem nenhum → todos os dias). */
    public const STARTED_SQL = '(COALESCE(l.opened_on, l.sales_since) IS NULL OR DATE(%s) >= DATE(COALESCE(l.opened_on, l.sales_since)))';

    public function __construct(private readonly Coverage $coverage) {}

    public static function started(string $column): string
    {
        return sprintf(self::STARTED_SQL, $column);
    }

    public function build(int $companyId, ?string $date = null): array
    {
        $ref = $this->resolveReferenceDate($companyId, $date);

        // Flag por empresa: ticket médio com o CoverManager (default true).
        $avgEnabled = (bool) (Company::where('id', $companyId)->value('cm_avg_ticket_enabled') ?? true);

        return [
            'date'     => $ref->toDateString(),
            'currency' => 'EUR',
            'annual'   => $this->annualCard($companyId, $ref),
            'monthly'  => $this->monthlyCard($companyId, $ref),
            'daily'    => $this->dailyCard($companyId, $ref),
            'locations' => $this->locations($companyId, $ref),
            // Ticket médio (faturação PingWin ÷ pessoas CoverManager) — Etapa 2.
            'avg_ticket_enabled' => $avgEnabled,
            'avg_ticket' => [
                'annual'  => $avgEnabled ? $this->avgTicketRange($companyId, $ref->copy()->startOfYear(), $ref) : null,
                'monthly' => $avgEnabled ? $this->avgTicketRange($companyId, $ref->copy()->startOfMonth(), $ref) : null,
                'daily'   => $avgEnabled ? $this->avgTicketRange($companyId, $ref, $ref) : null,
            ],
            // Lotação (pessoas que reservaram) — Etapa 3 simples. Total + almoço + jantar.
            'occupancy' => [
                'annual'  => $this->occupancyRange($companyId, $ref->copy()->startOfYear(), $ref),
                'monthly' => $this->occupancyRange($companyId, $ref->copy()->startOfMonth(), $ref),
                'daily'   => $this->occupancyRange($companyId, $ref, $ref),
            ],
        ];
    }

    /**
     * CALENDÁRIO de faturação: por DIA do mês, faturação (PingWin) + pessoas
     * (CoverManager) + ticket médio. $locationId → só essa loja; senão TODAS
     * somadas. Portão de honestidade: só devolve dias COM dados (vendas OU
     * reservas); um dia só com reservas tem a faturação a null (as vendas estão em
     * falta, não a zero); com "todas as lojas", missing_locations diz que lojas
     * abertas não têm as vendas desse dia. O ticket divide a faturação só pelas pessoas
     * das lojas com vendas nesse dia. Respeita o início de cada loja.
     */
    public function calendar(int $companyId, int $year, int $month, ?int $locationId = null): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        [$f, $t] = [$from->toDateString(), $to->toDateString()];

        // Faturação e pessoas por loja × dia (só a partir do início de cada loja).
        $sales = DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)->where('l.is_active', true)
            ->when($locationId !== null, fn ($q) => $q->where('s.location_id', $locationId))
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$f, $t])
            ->whereRaw(self::started('s.business_date'))
            ->selectRaw('s.location_id as loc, DATE(s.business_date) as d, s.invoiced_cents as invoiced, s.net_cents as net')
            ->get();
        $guests = DB::table('cm_reservation_shift_summary as c')
            ->join('pingwin_locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.company_id', $companyId)->where('l.is_active', true)
            ->when($locationId !== null, fn ($q) => $q->where('c.location_id', $locationId))
            ->whereRaw('DATE(c.business_date) BETWEEN ? AND ?', [$f, $t])
            ->whereRaw(self::started('c.business_date'))
            ->groupByRaw('c.location_id, DATE(c.business_date)')
            ->selectRaw('c.location_id as loc, DATE(c.business_date) as d, SUM(c.guests_total) as guests')
            ->get();

        $byDay = []; // [d] => ['sales' => [loc => [inv, net]], 'guests' => [loc => n]]
        foreach ($sales as $r) {
            $byDay[substr((string) $r->d, 0, 10)]['sales'][(int) $r->loc] = [(int) $r->invoiced, (int) $r->net];
        }
        foreach ($guests as $r) {
            $byDay[substr((string) $r->d, 0, 10)]['guests'][(int) $r->loc] = (int) $r->guests;
        }
        ksort($byDay);

        $cov = $this->monthCoverage($companyId, $year, $month, $locationId);

        $out = [];
        foreach ($byDay as $day => $x) {
            $s = $x['sales'] ?? [];
            $g = $x['guests'] ?? [];
            $invoiced = $s !== [] ? array_sum(array_column($s, 0)) : null;
            $net = $s !== [] ? array_sum(array_column($s, 1)) : null;
            // Ticket do dia: faturação ÷ pessoas DAS MESMAS lojas (só com as duas e > 0).
            $guestsWithSales = array_sum(array_intersect_key($g, $s));
            $ticket = ($invoiced !== null && $invoiced > 0 && $guestsWithSales > 0) ? (int) round($invoiced / $guestsWithSales) : null;

            $out[] = [
                'date' => $day,
                'invoiced_cents' => $invoiced,   // null = vendas em falta neste dia (não zero)
                'net_cents' => $net,
                'guests' => $g !== [] ? array_sum($g) : null,
                'avg_ticket_cents' => $ticket,
                'missing_locations' => $cov ? Coverage::dayMissing($cov, $day) : [],
            ];
        }

        return $out;
    }

    /**
     * A cobertura de vendas do mês do calendário (até ontem), para a nota "sem dados / faltam
     * dias" do ecrã. null = mês inteiro no futuro.
     */
    public function monthCoverage(int $companyId, int $year, int $month, ?int $locationId = null): ?array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $to = $from->endOfMonth()->min(CarbonImmutable::now('Europe/Lisbon')->startOfDay()->subDay());
        if ($to->lt($from)) {
            return null;
        }

        return $this->coverage->period($companyId, $from->toDateString(), $to->toDateString(), $locationId);
    }

    /**
     * Faturação MENSAL por loja para um ano — UMA série por loja (12 pontos,
     * Jan-Dez, em EUROS). ⚠️ Portão de honestidade: mês em que a loja não tem
     * nenhum dia de vendas (antes do início, não lido, ou no futuro) → null (não
     * afunda a linha a 0); um dia lido sem vendas é zero real. missing_days diz,
     * por mês, quantos dias faltam (mês parcial → o valor é só dos dias lidos).
     */
    public function monthlyByLocation(int $companyId, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($year, 12, 31)->min(CarbonImmutable::now('Europe/Lisbon')->startOfDay()->subDay());

        // Faturação por loja/mês (substr 'Y-m' funciona em MySQL e SQLite; início de cada loja).
        $rows = DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from->toDateString(), $from->endOfYear()->toDateString()])
            ->whereRaw(self::started('s.business_date'))
            ->groupByRaw('s.location_id, substr(DATE(s.business_date), 1, 7)')
            ->selectRaw('s.location_id as loc, substr(DATE(s.business_date), 1, 7) as ym, SUM(s.invoiced_cents) as inv')
            ->get();

        $byLocMonth = [];
        foreach ($rows as $r) {
            $byLocMonth[(int) $r->loc][$r->ym] = (int) $r->inv;
        }

        $cov = $to->gte($from) ? $this->coverage->period($companyId, $from->toDateString(), $to->toDateString()) : null;
        // Dias esperados e lidos por loja × mês (da cobertura).
        $count = []; // [loc][ym] => [esperados, lidos]
        foreach ($cov['days'] ?? [] as $d => $x) {
            $ym = substr($d, 0, 7);
            foreach ($x['expected'] as $loc) {
                $count[$loc][$ym][0] = ($count[$loc][$ym][0] ?? 0) + 1;
            }
            foreach ($x['present'] as $loc) {
                $count[$loc][$ym][1] = ($count[$loc][$ym][1] ?? 0) + 1;
            }
        }

        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('display_name')->get();

        $series = $locations->map(function (PingwinLocation $loc) use ($year, $count, $byLocMonth) {
            $data = [];
            $missing = [];
            for ($m = 1; $m <= 12; $m++) {
                $ym = sprintf('%04d-%02d', $year, $m);
                [$expected, $read] = [$count[$loc->id][$ym][0] ?? 0, $count[$loc->id][$ym][1] ?? 0];
                if ($read === 0) {
                    // Sem nenhum dia lido (antes do início, não sincronizado ou futuro) → null, não zero.
                    $data[] = null;
                    $missing[] = $expected > 0 ? $expected : null;
                    continue;
                }
                $data[] = round(($byLocMonth[$loc->id][$ym] ?? 0) / 100, 2); // euros (dia lido sem vendas = zero real)
                $missing[] = $expected - $read;
            }

            return [
                'location_id' => $loc->id,
                'name' => Coverage::name($loc),
                'data' => $data,
                'missing_days' => $missing,
            ];
        })->values()->all();

        return ['year' => $year, 'series' => $series];
    }

    /** Data de referência: a selecionada, ou o último dia sincronizado, ou ontem. */
    private function resolveReferenceDate(int $companyId, ?string $date): Carbon
    {
        if ($date) {
            return Carbon::parse($date)->startOfDay();
        }
        $last = PingwinSyncRun::where('company_id', $companyId)->max('business_date');

        return $last ? Carbon::parse($last)->startOfDay() : Carbon::yesterday()->startOfDay();
    }

    /**
     * Soma faturado + líquido (cêntimos) num intervalo, a partir do início de cada loja.
     * $locationId opcional → mesma agregação mas só de uma loja (para a tabela).
     */
    private function sumRange(int $companyId, Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        // DATE() nos dois lados: business_date é guardado como datetime (00:00:00),
        // por isso comparar com strings de data perdia o dia do limite superior.
        $row = DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('s.location_id', $locationId))
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            // Portão de honestidade: dias anteriores ao início da loja não contam.
            ->whereRaw(self::started('s.business_date'))
            ->selectRaw('COALESCE(SUM(s.invoiced_cents),0) as invoiced, COALESCE(SUM(s.net_cents),0) as net')
            ->first();

        return ['invoiced_cents' => (int) $row->invoiced, 'net_cents' => (int) $row->net];
    }

    /**
     * Os valores de um período com a sua cobertura: sem nenhuma loja com dados → null
     * ("sem dados", nunca zero); com dias em falta → os valores só dos dias lidos + a nota.
     */
    private function periodValues(int $companyId, Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        $cov = $this->coverage->period($companyId, $from->toDateString(), $to->toDateString(), $locationId);
        $sum = $cov['status'] === Coverage::NO_DATA
            ? ['invoiced_cents' => null, 'net_cents' => null]
            : $this->sumRange($companyId, $from, $to, $locationId);

        return [$sum, $cov];
    }

    /**
     * TICKET MÉDIO (cêntimos por pessoa) num intervalo = faturação PingWin ÷ pessoas
     * CoverManager. ⚠️ SOMA-ANTES-DE-DIVIDIR: SUM(invoiced) ÷ SUM(guests) do período
     * (nunca média das médias). Cruza por (location_id, business_date): só entram os
     * pares que têm AMBOS vendas E reservas (guests>0), a partir do início de cada loja.
     * Devolve null quando não há pessoas (→ "—", sem divisão por zero).
     */
    private function avgTicketRange(int $companyId, Carbon $from, Carbon $to, ?int $locationId = null): ?int
    {
        // Pessoas por (loja, dia) = soma dos 3 turnos do CoverManager.
        $guestsSub = DB::table('cm_reservation_shift_summary')
            ->select('location_id', 'business_date', DB::raw('SUM(guests_total) as guests'))
            ->where('company_id', $companyId)
            ->groupBy('location_id', 'business_date');

        $row = DB::table('pingwin_daily_sales as s')
            ->joinSub($guestsSub, 'c', function ($join) {
                $join->on('c.location_id', '=', 's.location_id')
                    ->on(DB::raw('DATE(c.business_date)'), '=', DB::raw('DATE(s.business_date)'));
            })
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('s.location_id', $locationId))
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw(self::started('s.business_date'))
            ->where('c.guests', '>', 0) // requer reservas (as DUAS integrações no mesmo dia/loja)
            ->selectRaw('COALESCE(SUM(s.invoiced_cents),0) as invoiced, COALESCE(SUM(c.guests),0) as guests')
            ->first();

        $guests = (int) ($row->guests ?? 0);
        if ($guests <= 0) {
            return null; // sem pessoas → "—" (portão de honestidade; requer as duas integrações)
        }

        return (int) round(((int) $row->invoiced) / $guests);
    }

    /**
     * LOTAÇÃO (pessoas que reservaram) num intervalo — do cm_reservation_shift_summary
     * (Etapa 1). Total + almoço (lunch) + jantar (dinner) + outro (other), a partir do
     * início de cada loja. has_data distingue "sem reservas sincronizadas" (→ "—") de 0 real.
     */
    private function occupancyRange(int $companyId, Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        // Sem $locationId → soma TODAS as lojas da empresa; com → só uma (tabela).
        $row = DB::table('cm_reservation_shift_summary as c')
            ->join('pingwin_locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('c.location_id', $locationId))
            ->whereRaw('DATE(c.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw(self::started('c.business_date'))
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(c.guests_total),0) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN c.shift = 'lunch' THEN c.guests_total ELSE 0 END),0) as lunch")
            ->selectRaw("COALESCE(SUM(CASE WHEN c.shift = 'dinner' THEN c.guests_total ELSE 0 END),0) as dinner")
            ->selectRaw("COALESCE(SUM(CASE WHEN c.shift = 'other' THEN c.guests_total ELSE 0 END),0) as other")
            ->first();

        return [
            'has_data' => ((int) ($row->rows_count ?? 0)) > 0, // false → "—" (sem reservas sincronizadas)
            'total'    => (int) ($row->total ?? 0),
            'lunch'    => (int) ($row->lunch ?? 0),
            'dinner'   => (int) ($row->dinner ?? 0),
            'other'    => (int) ($row->other ?? 0),
        ];
    }

    private function pct(?int $current, ?int $prev): ?float
    {
        if ($current === null || $prev === null || $prev <= 0) {
            return null; // sem base → não inventar %
        }
        return round((($current - $prev) / $prev) * 100, 1);
    }

    private function annualCard(int $companyId, Carbon $ref): array
    {
        [$current, $cov] = $this->periodValues($companyId, $ref->copy()->startOfYear(), $ref);

        // Mesma duração no ano anterior: 1 Jan (ano-1) .. ref −1 ano.
        $prevFrom = $ref->copy()->subYear()->startOfYear();
        $prevTo   = $ref->copy()->subYear();
        [$prev, $prevCov] = $this->periodValues($companyId, $prevFrom, $prevTo);

        // Só compara se os DOIS períodos estiverem completos (todas as lojas, todos os dias).
        $comparable = $cov['complete'] && $prevCov['complete'];

        return [
            'year' => (int) $ref->year,
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
            'prev' => $comparable ? $prev : null,
            'delta_pct_invoiced' => $comparable ? $this->pct($current['invoiced_cents'], $prev['invoiced_cents']) : null,
            'delta_pct_net' => $comparable ? $this->pct($current['net_cents'], $prev['net_cents']) : null,
            'comparable' => $comparable,
            'coverage' => Coverage::compact($cov),
        ];
    }

    private function monthlyCard(int $companyId, Carbon $ref): array
    {
        [$current, $cov] = $this->periodValues($companyId, $ref->copy()->startOfMonth(), $ref);

        return [
            'month' => $ref->format('Y-m'),
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
            'coverage' => Coverage::compact($cov),
        ];
    }

    private function dailyCard(int $companyId, Carbon $ref): array
    {
        [$current, $cov] = $this->periodValues($companyId, $ref, $ref);

        // vs MESMO DIA DA SEMANA anterior (−7 dias): só com os dois dias completos.
        $prevDay = $ref->copy()->subDays(7);
        [$prev, $prevCov] = $this->periodValues($companyId, $prevDay, $prevDay);
        $comparable = Coverage::dayComplete($cov, $ref->toDateString()) && Coverage::dayComplete($prevCov, $prevDay->toDateString());

        return [
            'date' => $ref->toDateString(),
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
            'prev_date' => $prevDay->toDateString(),
            'prev' => $comparable ? $prev : null,
            'delta_pct_invoiced' => $comparable ? $this->pct($current['invoiced_cents'], $prev['invoiced_cents']) : null,
            'delta_pct_net' => $comparable ? $this->pct($current['net_cents'], $prev['net_cents']) : null,
            'comparable' => $comparable,
            'coverage' => Coverage::compact($cov),
        ];
    }

    /**
     * Por loja: faturação e lotação (occupancyRange) nos 3 períodos (anual/mensal/diário)
     * + última sincronização. Uma loja sem dados no período fica com os valores a null e o
     * estado (not_open / no_data), nunca com zero; com dias em falta, missing_days diz quantos.
     */
    private function locations(int $companyId, Carbon $ref): array
    {
        $yearFrom = $ref->copy()->startOfYear();
        $monthFrom = $ref->copy()->startOfMonth();

        $locations = PingwinLocation::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();

        $period = function (int $locId, Carbon $from) use ($companyId, $ref) {
            [$sum, $cov] = $this->periodValues($companyId, $from, $ref, $locId);
            $l = $cov['locations'][0] ?? null;

            return $sum + ['status' => $l['status'] ?? Coverage::NO_DATA, 'missing_days' => $l['missing_days'] ?? 0, 'note' => $cov['note']];
        };

        return $locations->map(function (PingwinLocation $loc) use ($companyId, $ref, $yearFrom, $monthFrom, $period) {
            $lastSynced = DB::table('pingwin_daily_sales')->where('location_id', $loc->id)->max('synced_at');

            return [
                'id' => $loc->id,
                'display_name' => Coverage::name($loc),
                'annual' => $period($loc->id, $yearFrom),
                'monthly' => $period($loc->id, $monthFrom),
                'daily' => $period($loc->id, $ref),
                // Lotação (pessoas) desta loja, mesmos períodos.
                'occupancy' => [
                    'annual' => $this->occupancyRange($companyId, $yearFrom, $ref, $loc->id),
                    'monthly' => $this->occupancyRange($companyId, $monthFrom, $ref, $loc->id),
                    'daily' => $this->occupancyRange($companyId, $ref, $ref, $loc->id),
                ],
                'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
            ];
        })->all();
    }
}
