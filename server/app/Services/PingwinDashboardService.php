<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Cálculo do dashboard de restauração (do spike). Agrega
 * pingwin_daily_sales (SUM GROUP BY) em cêntimos e devolve os 3 cards
 * (anual/mensal/diário) com OS DOIS valores (faturado c/IVA + líquido) e as
 * comparações (vs ano passado / vs mesmo dia da semana). Portão de honestidade:
 *  · opened_on — loja não conta em dias anteriores à abertura;
 *  · comparação só aparece se o período anterior estiver TOTALMENTE sincronizado
 *    (senão delta_pct = null → o ecrã mostra "—"). Tudo scoped por company_id.
 */
class PingwinDashboardService
{
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
     * reservas); ticket só quando há faturação E pessoas. Respeita opened_on.
     */
    public function calendar(int $companyId, int $year, int $month, ?int $locationId = null): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        // Faturação por dia (respeita opened_on).
        $sales = DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('s.location_id', $locationId))
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('(l.opened_on IS NULL OR DATE(s.business_date) >= DATE(l.opened_on))')
            ->groupByRaw('DATE(s.business_date)')
            ->selectRaw('DATE(s.business_date) as d, SUM(s.invoiced_cents) as invoiced, SUM(s.net_cents) as net')
            ->get()->keyBy('d');

        // Pessoas por dia (respeita opened_on).
        $guests = DB::table('cm_reservation_shift_summary as c')
            ->join('pingwin_locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('c.location_id', $locationId))
            ->whereRaw('DATE(c.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('(l.opened_on IS NULL OR DATE(c.business_date) >= DATE(l.opened_on))')
            ->groupByRaw('DATE(c.business_date)')
            ->selectRaw('DATE(c.business_date) as d, SUM(c.guests_total) as guests')
            ->get()->keyBy('d');

        $days = collect($sales->keys())->merge($guests->keys())->unique()->sort()->values();

        return $days->map(function ($d) use ($sales, $guests) {
            $day = substr((string) $d, 0, 10); // normaliza (pode vir 'Y-m-d' ou datetime)
            $invoiced = (int) ($sales[$d]->invoiced ?? 0);
            $net = (int) ($sales[$d]->net ?? 0);
            $g = isset($guests[$d]) ? (int) $guests[$d]->guests : null;
            // Ticket médio do dia: só se há faturação E pessoas (> 0).
            $ticket = ($g && $g > 0 && $invoiced > 0) ? (int) round($invoiced / $g) : null;

            return [
                'date' => $day,
                'invoiced_cents' => $invoiced,
                'net_cents' => $net,
                'guests' => $g,
                'avg_ticket_cents' => $ticket,
            ];
        })->all();
    }

    /**
     * Faturação MENSAL por loja para um ano — UMA série por loja (12 pontos,
     * Jan-Dez, em EUROS). ⚠️ Portão de honestidade: mês SEM sincronização (nenhum
     * pingwin_sync_run nesse mês) → null (não afunda a linha a 0); mês sincronizado
     * → valor real (0 se a loja não faturou). Respeita opened_on (mês inteiro antes
     * da abertura → null). Reutiliza pingwin_daily_sales + pingwin_sync_runs.
     */
    public function monthlyByLocation(int $companyId, int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();

        // Meses COM sincronização (distingue "0€ real" de "não sincronizado").
        $syncedMonths = PingwinSyncRun::where('company_id', $companyId)
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->get()->map(fn ($r) => substr((string) $r->business_date, 0, 7))->unique()->flip();

        // Faturação por loja/mês (substr 'Y-m' funciona em MySQL e SQLite; opened_on gate).
        $rows = DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('(l.opened_on IS NULL OR DATE(s.business_date) >= DATE(l.opened_on))')
            ->groupByRaw('s.location_id, substr(DATE(s.business_date), 1, 7)')
            ->selectRaw('s.location_id as loc, substr(DATE(s.business_date), 1, 7) as ym, SUM(s.invoiced_cents) as inv')
            ->get();

        $byLocMonth = [];
        foreach ($rows as $r) {
            $byLocMonth[(int) $r->loc][$r->ym] = (int) $r->inv;
        }

        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('display_name')->get();

        $series = $locations->map(function (PingwinLocation $loc) use ($year, $syncedMonths, $byLocMonth) {
            $data = [];
            for ($m = 1; $m <= 12; $m++) {
                $ym = sprintf('%04d-%02d', $year, $m);
                // Mês não sincronizado → null (não desce a zero).
                if (! $syncedMonths->has($ym)) {
                    $data[] = null;
                    continue;
                }
                // Mês inteiro antes da abertura da loja → null.
                if ($loc->opened_on && $loc->opened_on->gt(Carbon::create($year, $m, 1)->endOfMonth())) {
                    $data[] = null;
                    continue;
                }
                $cents = $byLocMonth[$loc->id][$ym] ?? 0; // sincronizado → real (0 se sem vendas)
                $data[] = round($cents / 100, 2);          // euros
            }

            return [
                'location_id' => $loc->id,
                'name' => $loc->display_name ?: $loc->winrest_name ?: $loc->winrest_store_id,
                'data' => $data,
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
     * Soma faturado + líquido (cêntimos) num intervalo, respeitando opened_on.
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
            // Portão de honestidade: dias anteriores à abertura da loja não contam.
            ->whereRaw('(l.opened_on IS NULL OR DATE(s.business_date) >= DATE(l.opened_on))')
            ->selectRaw('COALESCE(SUM(s.invoiced_cents),0) as invoiced, COALESCE(SUM(s.net_cents),0) as net')
            ->first();

        return ['invoiced_cents' => (int) $row->invoiced, 'net_cents' => (int) $row->net];
    }

    /**
     * TICKET MÉDIO (cêntimos por pessoa) num intervalo = faturação PingWin ÷ pessoas
     * CoverManager. ⚠️ SOMA-ANTES-DE-DIVIDIR: SUM(invoiced) ÷ SUM(guests) do período
     * (nunca média das médias). Cruza por (location_id, business_date): só entram os
     * pares que têm AMBOS vendas E reservas (guests>0), respeitando opened_on.
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
            ->whereRaw('(l.opened_on IS NULL OR DATE(s.business_date) >= DATE(l.opened_on))')
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
     * (Etapa 1). Total + almoço (lunch) + jantar (dinner) + outro (other), respeitando
     * opened_on. has_data distingue "sem reservas sincronizadas" (→ "—") de 0 real.
     */
    private function occupancyRange(int $companyId, Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        // Sem $locationId → soma TODAS as lojas da empresa; com → só uma (tabela).
        $row = DB::table('cm_reservation_shift_summary as c')
            ->join('pingwin_locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.company_id', $companyId)
            ->when($locationId !== null, fn ($q) => $q->where('c.location_id', $locationId))
            ->whereRaw('DATE(c.business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('(l.opened_on IS NULL OR DATE(c.business_date) >= DATE(l.opened_on))')
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

    /** Nº de dias distintos sincronizados num intervalo (portão de honestidade). */
    private function syncedDays(int $companyId, Carbon $from, Carbon $to): int
    {
        return (int) PingwinSyncRun::where('company_id', $companyId)
            ->where('status', 'success')
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->distinct('business_date')
            ->count('business_date');
    }

    /** O período [from,to] está TOTALMENTE sincronizado? */
    private function isFullySynced(int $companyId, Carbon $from, Carbon $to): bool
    {
        $needed = $from->diffInDays($to) + 1; // inclusivo
        return $this->syncedDays($companyId, $from, $to) >= $needed;
    }

    private function pct(int $current, int $prev): ?float
    {
        if ($prev <= 0) {
            return null; // sem base → não inventar %
        }
        return round((($current - $prev) / $prev) * 100, 1);
    }

    private function annualCard(int $companyId, Carbon $ref): array
    {
        $curFrom = $ref->copy()->startOfYear();
        $current = $this->sumRange($companyId, $curFrom, $ref);

        // Mesma duração no ano anterior: 1 Jan (ano-1) .. ref −1 ano.
        $prevFrom = $ref->copy()->subYear()->startOfYear();
        $prevTo   = $ref->copy()->subYear();
        $prev     = $this->sumRange($companyId, $prevFrom, $prevTo);

        // Só compara se o período anterior estiver todo sincronizado.
        $comparable = $this->isFullySynced($companyId, $prevFrom, $prevTo);

        return [
            'year' => (int) $ref->year,
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
            'prev' => $comparable ? $prev : null,
            'delta_pct_invoiced' => $comparable ? $this->pct($current['invoiced_cents'], $prev['invoiced_cents']) : null,
            'delta_pct_net' => $comparable ? $this->pct($current['net_cents'], $prev['net_cents']) : null,
            'comparable' => $comparable,
        ];
    }

    private function monthlyCard(int $companyId, Carbon $ref): array
    {
        $from = $ref->copy()->startOfMonth();
        $current = $this->sumRange($companyId, $from, $ref);

        return [
            'month' => $ref->format('Y-m'),
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
        ];
    }

    private function dailyCard(int $companyId, Carbon $ref): array
    {
        $current = $this->sumRange($companyId, $ref, $ref);

        // vs MESMO DIA DA SEMANA anterior (−7 dias).
        $prevDay = $ref->copy()->subDays(7);
        $prev = $this->sumRange($companyId, $prevDay, $prevDay);
        $comparable = $this->isFullySynced($companyId, $prevDay, $prevDay);

        return [
            'date' => $ref->toDateString(),
            'invoiced_cents' => $current['invoiced_cents'],
            'net_cents' => $current['net_cents'],
            'prev_date' => $prevDay->toDateString(),
            'prev' => $comparable ? $prev : null,
            'delta_pct_invoiced' => $comparable ? $this->pct($current['invoiced_cents'], $prev['invoiced_cents']) : null,
            'delta_pct_net' => $comparable ? $this->pct($current['net_cents'], $prev['net_cents']) : null,
            'comparable' => $comparable,
        ];
    }

    /**
     * Por loja: faturação (sumRange) E lotação (occupancyRange) nos 3 períodos
     * (anual/mensal/diário) + última sincronização. Mesma agregação dos cards,
     * scoped por loja.
     */
    private function locations(int $companyId, Carbon $ref): array
    {
        $yearFrom = $ref->copy()->startOfYear();
        $monthFrom = $ref->copy()->startOfMonth();

        $locations = PingwinLocation::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();

        return $locations->map(function (PingwinLocation $loc) use ($companyId, $ref, $yearFrom, $monthFrom) {
            $lastSynced = DB::table('pingwin_daily_sales')->where('location_id', $loc->id)->max('synced_at');

            return [
                'id' => $loc->id,
                'display_name' => $loc->display_name ?: $loc->winrest_name ?: $loc->winrest_store_id,
                'annual' => $this->sumRange($companyId, $yearFrom, $ref, $loc->id),
                'monthly' => $this->sumRange($companyId, $monthFrom, $ref, $loc->id),
                'daily' => $this->sumRange($companyId, $ref, $ref, $loc->id),
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
