<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\PingwinSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Dashboard de RESTAURAÇÃO: bloco "marketing e resultados" de UM mês.
 *
 * Mostra LADO A LADO o que se investiu/atraiu (Meta, site/GA4) e os resultados do
 * negócio (faturação, pessoas, reservas). NUNCA afirma causalidade: devolve números
 * e deltas estruturados para o frontend compor frases DESCRITIVAS ("no mesmo
 * período…"), nunca "por causa de".
 *
 * As 5 métricas:
 *   1. revenue + covers        — faturação (pingwin_daily_sales) + pessoas (CoverManager)
 *   2. meta_spend + meta_clicks — meta_account_insights_daily (conta actual)
 *   3. ga4_sessions            — sessões por origem (pago, social orgânico, pesquisa, direto, outros)
 *   4. marketing_weight        — gasto Meta ÷ faturação (%)
 *   5. reservations_mix        — reservas feitas vs walk-ins (CoverManager)
 *
 * COMPARAÇÃO (por métrica, com a etiqueta do escalão usado):
 *   1. 'same_month_last_year' — mesmo mês do ano anterior, SÓ se os dados desse
 *      período estiverem completos (internos: isFullySynced; Meta: cobertura do
 *      backfill; GA4: base > 0);
 *   2. 'previous_month'       — senão, mês anterior, com seasonality_warning=true;
 *   3. 'declared'             — RESERVADO (valores declarados pelo cliente). A
 *      estrutura aceita-o mas NÃO é produzido nesta versão.
 * Mês em curso: compara os MESMOS DIAS (1..N contra 1..N), só dias completos (até
 * ontem). Mês passado: mês inteiro contra mês inteiro.
 */
class RestaurantMarketingService
{
    public const TIER_SAME_MONTH_LAST_YEAR = 'same_month_last_year';
    public const TIER_PREVIOUS_MONTH = 'previous_month';
    public const TIER_DECLARED = 'declared'; // reservado — não produzido nesta versão

    /** sessionDefaultChannelGroup do GA4 → grupos do dashboard. O resto → 'other'. */
    private const GA4_GROUPS = [
        'paid' => ['Paid Search', 'Paid Social', 'Display', 'Paid Video', 'Paid Shopping', 'Paid Other', 'Cross-network'],
        'organic_social' => ['Organic Social'],
        'search' => ['Organic Search'],
        'direct' => ['Direct'],
    ];

    public function __construct(private readonly GoogleAnalyticsService $ga4) {}

    public function build(int $companyId, ?string $month = null, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today();
        $monthStart = $month
            ? CarbonImmutable::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : $today->startOfMonth();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $isCurrent = $monthStart->equalTo($today->startOfMonth());

        // Só dias COMPLETOS: no mês em curso, até ontem (as vendas do dia ainda não
        // estão sincronizadas e o GA4/Meta de hoje é parcial).
        $periodEnd = $isCurrent ? $today->subDay() : $monthEnd;
        // Carbon 3: diffInDays devolve float → int.
        $days = $periodEnd->lt($monthStart) ? 0 : (int) $monthStart->diffInDays($periodEnd) + 1;

        $period = ['start' => $monthStart->toDateString(), 'end' => $periodEnd->toDateString(), 'days' => (int) $days];

        // Janelas de comparação (mesmos dias no mês em curso; mês inteiro no passado).
        $lyStart = $monthStart->subYear();
        $pmStart = $monthStart->subMonth();
        $windows = $days > 0 ? [
            self::TIER_SAME_MONTH_LAST_YEAR => $this->window($lyStart, $days, true),
            self::TIER_PREVIOUS_MONTH       => $this->window($pmStart, $days, $isCurrent),
        ] : [];

        $metaIntegration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        $ga4Integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'google')->first();

        $metaState = MetaAccountInsightsService::connectionState($metaIntegration);
        $internalHasData = DB::table('pingwin_daily_sales')->where('company_id', $companyId)->exists();

        $out = [
            'month' => $monthStart->format('Y-m'),
            'is_current_month' => $isCurrent,
            'period' => $period,
            'comparison_windows' => array_map(fn ($w) => ['start' => $w['start'], 'end' => $w['end']], $windows),
            'sources' => [
                'internal' => ['state' => $internalHasData ? 'ok' : 'no_data'],
                'meta' => ['state' => $metaState],
                'ga4' => ['state' => 'not_connected'],
            ],
            'metrics' => [],
            // Escalão 3 (declarado pelo cliente): estrutura pronta, não construído.
            'declared' => null,
        ];

        if ($days === 0) {
            // Mês acabou de começar (dia 1): ainda não há dias completos.
            $out['metrics'] = [
                'revenue' => null, 'covers' => null, 'meta_spend' => null, 'meta_clicks' => null,
                'ga4_sessions' => null, 'marketing_weight' => null, 'reservations_mix' => null,
            ];
            $out['sources']['ga4']['state'] = $this->ga4Connected($ga4Integration) ? 'ok' : 'not_connected';

            return $out;
        }

        // ── 1. Faturação + pessoas (internos) ─────────────────────────────────
        $internalTier = $this->firstTier($windows, fn ($w) => $this->isFullySynced($companyId, $w['start'], $w['end']));

        $revenueSeries = $this->dailyRevenue($companyId, $period['start'], $period['end']);
        $revenueTotal = round(array_sum($revenueSeries), 2);
        $revenueBase = $internalTier ? round(array_sum($this->dailyRevenue($companyId, $windows[$internalTier]['start'], $windows[$internalTier]['end'])), 2) : null;

        $cm = $this->dailyReservations($companyId, $period['start'], $period['end']);
        $coversTotal = array_sum(array_column($cm, 'guests'));
        $cmBase = $internalTier ? $this->dailyReservations($companyId, $windows[$internalTier]['start'], $windows[$internalTier]['end']) : null;

        $out['metrics']['revenue'] = [
            'unit' => 'EUR',
            'total' => $revenueTotal,
            'series' => $this->seriesFrom($revenueSeries, $period),
            'comparison' => $this->compare($revenueTotal, $internalTier, $revenueBase, $windows),
        ];
        $out['metrics']['covers'] = [
            'unit' => 'people',
            'has_data' => $cm !== [],
            'total' => $coversTotal,
            'series' => $this->seriesFrom(array_map(fn ($d) => $d['guests'], $cm), $period),
            'comparison' => $cm === [] ? $this->noComparison()
                : $this->compare($coversTotal, $internalTier, $cmBase !== null ? array_sum(array_column($cmBase, 'guests')) : null, $windows),
        ];

        // ── 5. Reservas feitas vs walk-ins (CoverManager) ──────────────────────
        // reservations_count INCLUI os walk-ins (ver CoverManagerService::aggregate).
        $reserved = array_sum(array_map(fn ($d) => max(0, $d['reservations'] - $d['walk_ins']), $cm));
        $walkIns = array_sum(array_column($cm, 'walk_ins'));
        $share = ($reserved + $walkIns) > 0 ? round($reserved / ($reserved + $walkIns) * 100, 1) : null;
        $baseShare = null;
        if ($cmBase !== null) {
            $bRes = array_sum(array_map(fn ($d) => max(0, $d['reservations'] - $d['walk_ins']), $cmBase));
            $bWalk = array_sum(array_column($cmBase, 'walk_ins'));
            $baseShare = ($bRes + $bWalk) > 0 ? round($bRes / ($bRes + $bWalk) * 100, 1) : null;
        }
        $out['metrics']['reservations_mix'] = $cm === [] ? null : [
            'reserved' => $reserved,
            'walk_ins' => $walkIns,
            'reserved_share_pct' => $share,
            'series' => array_map(fn ($date) => [
                'date' => $date,
                'reserved' => isset($cm[$date]) ? max(0, $cm[$date]['reservations'] - $cm[$date]['walk_ins']) : 0,
                'walk_ins' => $cm[$date]['walk_ins'] ?? 0,
            ], $this->dates($period)),
            'comparison' => $this->comparePoints($share, $internalTier, $baseShare, $windows),
        ];

        // ── 2. Gasto Meta + cliques ────────────────────────────────────────────
        $metaTier = null;
        $metaSpendTotal = null;
        if (in_array($metaState, ['ok', 'sync_failed', 'token_expired'], true)
            && $metaIntegration && $metaIntegration->insights_backfilled_at !== null) {
            // token_expired / sync_failed: os dados já ingeridos continuam válidos
            // (o estado vai no payload para o ecrã avisar).
            $accountId = MetaAccountInsightsService::normalizeAccountId($metaIntegration->account_id);
            $coverage = MetaAccountInsightsService::coverageStart($metaIntegration);
            $syncedUntil = $metaIntegration->insights_synced_until
                ? CarbonImmutable::parse($metaIntegration->insights_synced_until->toDateString())
                : null;

            $metaTier = $accountId === null ? null : $this->firstTier($windows, fn ($w) =>
                $coverage !== null
                && CarbonImmutable::parse($w['start'])->gte($coverage)
                && ($syncedUntil === null || CarbonImmutable::parse($w['end'])->lte($syncedUntil)));

            if ($accountId !== null) {
                $meta = $this->dailyMeta($companyId, $accountId, $period['start'], $period['end']);
                $metaBase = $metaTier ? $this->dailyMeta($companyId, $accountId, $windows[$metaTier]['start'], $windows[$metaTier]['end']) : null;

                $metaSpendTotal = round(array_sum(array_column($meta, 'spend')), 2);
                $clicksTotal = (int) array_sum(array_column($meta, 'clicks'));

                $out['metrics']['meta_spend'] = [
                    'unit' => 'EUR',
                    'total' => $metaSpendTotal,
                    'series' => $this->seriesFrom(array_map(fn ($d) => $d['spend'], $meta), $period),
                    'comparison' => $this->compare($metaSpendTotal, $metaTier, $metaBase !== null ? round(array_sum(array_column($metaBase, 'spend')), 2) : null, $windows),
                ];
                $out['metrics']['meta_clicks'] = [
                    'unit' => 'clicks',
                    'total' => $clicksTotal,
                    'series' => $this->seriesFrom(array_map(fn ($d) => $d['clicks'], $meta), $period),
                    'comparison' => $this->compare($clicksTotal, $metaTier, $metaBase !== null ? (int) array_sum(array_column($metaBase, 'clicks')) : null, $windows),
                ];

                // ── 4. Peso do marketing = gasto Meta ÷ faturação ───────────────
                // Escalão comum às duas fontes (senão não é comparável).
                $weight = $revenueTotal > 0 ? round($metaSpendTotal / $revenueTotal * 100, 2) : null;
                $commonTier = $this->commonTier($windows, $internalTier, $metaTier, fn ($w) =>
                    $this->isFullySynced($companyId, $w['start'], $w['end'])
                    && $coverage !== null && CarbonImmutable::parse($w['start'])->gte($coverage)
                    && ($syncedUntil === null || CarbonImmutable::parse($w['end'])->lte($syncedUntil)));
                $baseWeight = null;
                if ($commonTier) {
                    $bRev = array_sum($this->dailyRevenue($companyId, $windows[$commonTier]['start'], $windows[$commonTier]['end']));
                    $bSpend = array_sum(array_column($this->dailyMeta($companyId, $accountId, $windows[$commonTier]['start'], $windows[$commonTier]['end']), 'spend'));
                    $baseWeight = $bRev > 0 ? round($bSpend / $bRev * 100, 2) : null;
                }
                $out['metrics']['marketing_weight'] = [
                    'unit' => 'pct',
                    'value' => $weight,
                    'comparison' => $this->comparePoints($weight, $commonTier, $baseWeight, $windows),
                ];
            }
        }
        $out['metrics']['meta_spend'] ??= null;
        $out['metrics']['meta_clicks'] ??= null;
        $out['metrics']['marketing_weight'] ??= null;

        // ── 3. Sessões do site por origem (GA4, ao vivo + cache) ───────────────
        $out['metrics']['ga4_sessions'] = null;
        if ($this->ga4Connected($ga4Integration)) {
            $propertyId = (int) $ga4Integration->property_id;
            try {
                $cur = $this->ga4->sessionsByChannel($companyId, $propertyId, $period['start'], $period['end']);

                // GA4 não diz desde quando a propriedade tem dados: um escalão só
                // serve se a base tiver sessões (> 0); senão tenta o seguinte.
                $ga4Tier = null;
                $ga4Base = null;
                foreach ($windows as $tier => $w) {
                    $b = $this->ga4->sessionsByChannel($companyId, $propertyId, $w['start'], $w['end']);
                    if ($b['total'] > 0) {
                        $ga4Tier = $tier;
                        $ga4Base = $b;
                        break;
                    }
                }

                $curGroups = $this->groupChannels($cur['by_channel']);
                $baseGroups = $ga4Base ? $this->groupChannels($ga4Base['by_channel']) : null;

                $byGroup = [];
                foreach (array_keys($curGroups) as $g) {
                    $byGroup[$g] = [
                        'total' => $curGroups[$g],
                        'comparison' => $this->compare($curGroups[$g], $ga4Tier, $baseGroups[$g] ?? null, $windows),
                    ];
                }

                $out['metrics']['ga4_sessions'] = [
                    'unit' => 'sessions',
                    'total' => $cur['total'],
                    'by_group' => $byGroup,
                    'series' => array_map(fn ($date) => ['date' => $date] + $this->groupChannels($cur['series'][$date] ?? []), $this->dates($period)),
                    'comparison' => $this->compare($cur['total'], $ga4Tier, $ga4Base['total'] ?? null, $windows),
                ];
                $out['sources']['ga4']['state'] = 'ok';
            } catch (\Throwable $e) {
                // Nunca rebentar o dashboard por causa do GA4.
                Log::warning('[RestaurantMarketing] GA4 indisponível', [
                    'company_id' => $companyId,
                    'error' => \App\Services\Ga4\Ga4Redact::message($e->getMessage()),
                ]);
                $out['sources']['ga4'] = [
                    'state' => 'error',
                    'error' => 'Não foi possível ler os dados do GA4. Confirma o acesso da Service Account à propriedade.',
                ];
            }
        }

        return $out;
    }

    // ── Comparações ───────────────────────────────────────────────────────────

    /** Janela de N dias a começar em $start, sem passar do fim desse mês. */
    private function window(CarbonImmutable $start, int $days, bool $sameDays): array
    {
        $monthEnd = $start->endOfMonth()->startOfDay();
        $end = $sameDays ? $start->addDays($days - 1) : $monthEnd;
        if ($end->gt($monthEnd)) {
            $end = $monthEnd;
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'reference_month' => $start->format('Y-m')];
    }

    /** 1.º escalão (por ordem) cuja janela cumpre $usable. */
    private function firstTier(array $windows, callable $usable): ?string
    {
        foreach ($windows as $tier => $w) {
            if ($usable($w)) {
                return $tier;
            }
        }

        return null;
    }

    /** Escalão comum a duas fontes (o 1.º em que AMBAS têm dados completos). */
    private function commonTier(array $windows, ?string $a, ?string $b, callable $bothUsable): ?string
    {
        if ($a === null || $b === null) {
            return null;
        }

        return $this->firstTier($windows, $bothUsable);
    }

    private function noComparison(): array
    {
        return ['tier' => null, 'reason' => 'no_history'];
    }

    /** Delta em % de um total (nunca inventa %: base ≤ 0 → delta_pct null). */
    private function compare(float|int $current, ?string $tier, float|int|null $base, array $windows): array
    {
        if ($tier === null || $base === null) {
            return $this->noComparison();
        }

        return [
            'tier' => $tier,
            'reference_month' => $windows[$tier]['reference_month'],
            'window' => ['start' => $windows[$tier]['start'], 'end' => $windows[$tier]['end']],
            'base_value' => $base,
            'delta_abs' => round($current - $base, 2),
            'delta_pct' => $base > 0 ? round(($current - $base) / $base * 100, 1) : null,
            'seasonality_warning' => $tier === self::TIER_PREVIOUS_MONTH,
        ];
    }

    /** Delta em PONTOS PERCENTUAIS de uma percentagem (peso do marketing, quota de reservas). */
    private function comparePoints(?float $current, ?string $tier, ?float $base, array $windows): array
    {
        if ($current === null || $tier === null || $base === null) {
            return $this->noComparison();
        }

        return [
            'tier' => $tier,
            'reference_month' => $windows[$tier]['reference_month'],
            'window' => ['start' => $windows[$tier]['start'], 'end' => $windows[$tier]['end']],
            'base_value' => $base,
            'delta_pp' => round($current - $base, 2),
            'seasonality_warning' => $tier === self::TIER_PREVIOUS_MONTH,
        ];
    }

    // ── Fontes de dados ───────────────────────────────────────────────────────

    /** Período totalmente sincronizado (PingWin + CoverManager correm no mesmo orquestrador). */
    private function isFullySynced(int $companyId, string $from, string $to): bool
    {
        $needed = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $synced = PingwinSyncRun::where('company_id', $companyId)
            ->where('status', 'success')
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])
            ->distinct('business_date')
            ->count('business_date');

        return $synced >= $needed;
    }

    /** Faturação (EUR, c/ IVA = invoiced) por dia, respeitando opened_on das lojas. */
    private function dailyRevenue(int $companyId, string $from, string $to): array
    {
        return DB::table('pingwin_daily_sales as s')
            ->join('pingwin_locations as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->whereRaw('DATE(s.business_date) BETWEEN ? AND ?', [$from, $to])
            ->whereRaw('(l.opened_on IS NULL OR DATE(s.business_date) >= DATE(l.opened_on))')
            ->selectRaw('DATE(s.business_date) as d, COALESCE(SUM(s.invoiced_cents),0) as cents')
            ->groupByRaw('DATE(s.business_date)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => round(((int) $r->cents) / 100, 2)])
            ->all();
    }

    /** Pessoas / reservas / walk-ins (CoverManager) por dia, todas as lojas e turnos. */
    private function dailyReservations(int $companyId, string $from, string $to): array
    {
        return DB::table('cm_reservation_shift_summary as c')
            ->join('pingwin_locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.company_id', $companyId)
            ->whereRaw('DATE(c.business_date) BETWEEN ? AND ?', [$from, $to])
            ->whereRaw('(l.opened_on IS NULL OR DATE(c.business_date) >= DATE(l.opened_on))')
            ->selectRaw('DATE(c.business_date) as d, COALESCE(SUM(c.guests_total),0) as guests, COALESCE(SUM(c.reservations_count),0) as reservations, COALESCE(SUM(c.walk_ins_count),0) as walk_ins')
            ->groupByRaw('DATE(c.business_date)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => [
                'guests' => (int) $r->guests,
                'reservations' => (int) $r->reservations,
                'walk_ins' => (int) $r->walk_ins,
            ]])
            ->all();
    }

    /** Gasto (EUR) + cliques Meta por dia (conta actual). */
    private function dailyMeta(int $companyId, string $accountId, string $from, string $to): array
    {
        return DB::table('meta_account_insights_daily')
            ->where('company_id', $companyId)
            ->where('account_id', $accountId)
            ->whereRaw('DATE(date) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('DATE(date) as d, COALESCE(SUM(spend),0) as spend, COALESCE(SUM(clicks),0) as clicks')
            ->groupByRaw('DATE(date)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => ['spend' => round((float) $r->spend, 2), 'clicks' => (int) $r->clicks]])
            ->all();
    }

    private function ga4Connected(?CompanyIntegration $i): bool
    {
        return $i !== null && $i->status !== 'revoked' && ! empty($i->property_id);
    }

    /** Agrupa canais GA4 nos grupos do dashboard (sempre com as 5 chaves). */
    private function groupChannels(array $byChannel): array
    {
        $out = ['paid' => 0, 'organic_social' => 0, 'search' => 0, 'direct' => 0, 'other' => 0];
        foreach ($byChannel as $channel => $sessions) {
            $group = 'other';
            foreach (self::GA4_GROUPS as $g => $channels) {
                if (in_array($channel, $channels, true)) {
                    $group = $g;
                    break;
                }
            }
            $out[$group] += (int) $sessions;
        }

        return $out;
    }

    /** Todas as datas do período (série contínua, dias sem dados = 0). */
    private function dates(array $period): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($period['start']); $d->lte(CarbonImmutable::parse($period['end'])); $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** [data => valor] → [{date, value}] contínuo. */
    private function seriesFrom(array $byDate, array $period): array
    {
        return array_map(fn ($date) => ['date' => $date, 'value' => $byDate[$date] ?? 0], $this->dates($period));
    }
}
