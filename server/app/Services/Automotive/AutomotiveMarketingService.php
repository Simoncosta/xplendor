<?php

declare(strict_types=1);

namespace App\Services\Automotive;

use App\Models\CompanyIntegration;
use App\Models\MetaAd;
use App\Repositories\CarAdSpendRepository;
use App\Services\GoogleAnalyticsService;
use App\Services\MetaAccountInsightsService;
use App\Support\MonthComparison;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Dashboard do AUTOMÓVEL: bloco "Marketing e resultados" de UM mês.
 *
 * Mostra LADO A LADO o que se investiu/atraiu (Meta, site/GA4) e o que aconteceu
 * no stand (leads, contactos diretos, vendas). NUNCA afirma causalidade: devolve
 * números e deltas estruturados para o ecrã compor frases DESCRITIVAS.
 *
 * Métricas:
 *   · leads          → car_leads (total e pagas: channel='paid');
 *   · meta_spend     → meta_account_insights_daily (conta actual) + cliques, e a
 *                      divisão do mês por viatura / stock geral / por atribuir
 *                      (CarAdSpendRepository + ingestão por anúncio);
 *   · ga4_sessions   → visitas ao site por origem (GA4);
 *   · paid_cpl       → gasto Meta ÷ leads pagas; 0 leads → 'spend_without_lead'
 *                      (nunca divide por zero);
 *   · contacts       → WhatsApp + chamada + ver/copiar telefone (car_interactions);
 *   · sales          → vendas do mês, SÓ contexto (sem delta nem comparação).
 * Comparação por métrica com o escalão usado (MonthComparison). Aviso de qualidade:
 * investimento com zero leads pagas e leads de social orgânico no mesmo período →
 * possíveis anúncios sem parâmetros UTM.
 */
class AutomotiveMarketingService
{
    /** Contactos diretos (a mesma definição da coluna contacts do view do funil). */
    public const CONTACT_TYPES = ['whatsapp_click', 'call_click', 'show_phone', 'copy_phone'];

    /**
     * Registo de visitas PARADO: o último evento (vista, interação ou lead) é mais
     * antigo do que isto em relação ao fim do período. Os zeros deixam de parecer
     * reais: o ecrã avisa "sem registos há N dias".
     */
    public const TRACKING_STALE_DAYS = 7;

    public function __construct(
        private readonly GoogleAnalyticsService $ga4,
        private readonly CarAdSpendRepository $spend,
    ) {}

    public function build(int $companyId, ?string $month = null, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today();
        $p = MonthComparison::periodFor($month, $today);
        $period = $p['period'];
        $windows = $p['windows'];

        $metaIntegration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'meta')->first();
        $ga4Integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'google')->first();
        $metaState = MetaAccountInsightsService::connectionState($metaIntegration);
        $trackingStart = $this->trackingStart($companyId);
        $trackingLast = $this->trackingLast($companyId);

        $out = [
            'month' => $p['month'],
            'is_current_month' => $p['is_current'],
            'period' => $period,
            'comparison_windows' => array_map(fn ($w) => ['start' => $w['start'], 'end' => $w['end']], $windows),
            'sources' => [
                'tracking' => $this->trackingState($trackingStart, $trackingLast, $period, $p['is_current'], $today),
                'meta' => ['state' => $metaState],
                'ga4' => ['state' => $this->ga4Connected($ga4Integration) ? 'ok' : 'not_connected'],
            ],
            'metrics' => [
                'leads' => null, 'meta_spend' => null, 'ga4_sessions' => null,
                'paid_cpl' => null, 'contacts' => null, 'sales' => null,
            ],
            'quality_signals' => [],
        ];

        if ($period['days'] === 0) {
            // Dia 1 do mês: ainda não há dias completos.
            return $out;
        }

        // Escalão das fontes internas (tracking do site): a base só serve se o
        // tracking já existia no início da janela.
        $trackingUsable = fn (array $w) => $trackingStart !== null && $trackingStart->lte(CarbonImmutable::parse($w['start']));
        $trackingTier = MonthComparison::firstTier($windows, $trackingUsable);
        $base = fn (string $tier) => $windows[$tier];
        // Sem escalão porque o tracking começou a meio das janelas: a razão vai no
        // payload ("sem comparação: o registo de visitas começou a DD/MM").
        $trackingCompare = function (float|int $current, float|int|null $baseValue) use ($trackingTier, $trackingStart, $windows) {
            if ($trackingTier === null && $trackingStart !== null) {
                return ['tier' => null, 'reason' => 'tracking_started', 'tracking_since' => $trackingStart->toDateString()];
            }

            return MonthComparison::compare($current, $trackingTier, $baseValue, $windows);
        };

        // ── Leads (total e pagas) ──────────────────────────────────────────────
        $leads = $this->dailyLeads($companyId, $period['start'], $period['end']);
        $leadsTotal = array_sum(array_column($leads, 'total'));
        $paidTotal = array_sum(array_column($leads, 'paid'));
        $leadsBase = $trackingTier ? $this->dailyLeads($companyId, $base($trackingTier)['start'], $base($trackingTier)['end']) : null;

        $out['metrics']['leads'] = [
            'unit' => 'leads',
            'total' => $leadsTotal,
            'paid' => $paidTotal,
            'series' => array_map(fn ($d) => ['date' => $d, 'total' => $leads[$d]['total'] ?? 0, 'paid' => $leads[$d]['paid'] ?? 0], MonthComparison::dates($period)),
            'comparison' => $trackingCompare($leadsTotal, $leadsBase !== null ? array_sum(array_column($leadsBase, 'total')) : null),
            'paid_comparison' => $trackingCompare($paidTotal, $leadsBase !== null ? array_sum(array_column($leadsBase, 'paid')) : null),
        ];

        // ── Contactos diretos ──────────────────────────────────────────────────
        $contacts = $this->dailyContacts($companyId, $period['start'], $period['end']);
        $contactsTotal = array_sum(array_map(fn ($d) => $d['total'], $contacts));
        $contactsBase = $trackingTier ? $this->dailyContacts($companyId, $base($trackingTier)['start'], $base($trackingTier)['end']) : null;
        $byType = ['whatsapp' => 0, 'call' => 0, 'phone_reveal' => 0];
        foreach ($contacts as $d) {
            foreach ($byType as $k => $_) {
                $byType[$k] += $d[$k];
            }
        }
        $out['metrics']['contacts'] = [
            'unit' => 'contacts',
            'total' => $contactsTotal,
            'by_type' => $byType,
            'series' => MonthComparison::series(array_map(fn ($d) => $d['total'], $contacts), $period),
            'comparison' => $trackingCompare($contactsTotal, $contactsBase !== null ? array_sum(array_map(fn ($d) => $d['total'], $contactsBase)) : null),
        ];

        // ── Investimento Meta + cliques (conta) e divisão do mês ──────────────
        $metaSpendTotal = null;
        $metaTier = null;
        $metaUsable = fn (array $w) => false;
        if (in_array($metaState, ['ok', 'sync_failed', 'token_expired'], true)
            && $metaIntegration && $metaIntegration->insights_backfilled_at !== null
            && ($accountId = MetaAccountInsightsService::normalizeAccountId($metaIntegration->account_id)) !== null) {
            $coverage = MetaAccountInsightsService::coverageStart($metaIntegration);
            $syncedUntil = $metaIntegration->insights_synced_until
                ? CarbonImmutable::parse($metaIntegration->insights_synced_until->toDateString())
                : null;
            $metaUsable = fn (array $w) => $coverage !== null
                && CarbonImmutable::parse($w['start'])->gte($coverage)
                && ($syncedUntil === null || CarbonImmutable::parse($w['end'])->lte($syncedUntil));
            $metaTier = MonthComparison::firstTier($windows, $metaUsable);

            $meta = $this->dailyMeta($companyId, $accountId, $period['start'], $period['end']);
            $metaBase = $metaTier ? $this->dailyMeta($companyId, $accountId, $base($metaTier)['start'], $base($metaTier)['end']) : null;
            $metaSpendTotal = round(array_sum(array_column($meta, 'spend')), 2);
            $clicksTotal = (int) array_sum(array_column($meta, 'clicks'));

            $adLevel = $this->spend->hasAdLevelData($companyId);
            $byCar = $this->spend->companyTotals($companyId, $period['start'], $period['end'])['spend'];
            $byCarTag = round((float) DB::table('meta_ad_car_spend_daily')->where('company_id', $companyId)
                ->whereBetween('date', [$period['start'], $period['end']])->sum('spend_allocated'), 2);

            $out['metrics']['meta_spend'] = [
                'unit' => 'EUR',
                'total' => $metaSpendTotal,
                'clicks' => $clicksTotal,
                'series' => array_map(fn ($d) => ['date' => $d, 'spend' => $meta[$d]['spend'] ?? 0, 'clicks' => $meta[$d]['clicks'] ?? 0], MonthComparison::dates($period)),
                'comparison' => MonthComparison::compare($metaSpendTotal, $metaTier, $metaBase !== null ? round(array_sum(array_column($metaBase, 'spend')), 2) : null, $windows),
                'clicks_comparison' => MonthComparison::compare($clicksTotal, $metaTier, $metaBase !== null ? (int) array_sum(array_column($metaBase, 'clicks')) : null, $windows),
                // Divisão do mês (sem comparação): para onde foi o investimento.
                'breakdown' => [
                    'ad_level_available' => $adLevel,
                    'by_car' => $byCar,
                    'by_car_tag' => $byCarTag,
                    'by_car_manual_mapping' => round(max(0, $byCar - $byCarTag), 2),
                    'general_stock' => $adLevel ? $this->spend->adLevelSpendByTagStatus($companyId, $period['start'], $period['end'], [MetaAd::TAG_UNTAGGED]) : null,
                    'unattributed' => $adLevel ? $this->spend->adLevelSpendByTagStatus($companyId, $period['start'], $period['end'], [MetaAd::TAG_INVALID]) : null,
                ],
            ];

            // ── Custo por lead pago (nunca divide por zero) ────────────────────
            $cplTier = MonthComparison::firstTier($windows, fn ($w) => $trackingUsable($w) && $metaUsable($w));
            $baseCpl = null;
            if ($cplTier) {
                $bSpend = array_sum(array_column($this->dailyMeta($companyId, $accountId, $base($cplTier)['start'], $base($cplTier)['end']), 'spend'));
                $bPaid = array_sum(array_column($this->dailyLeads($companyId, $base($cplTier)['start'], $base($cplTier)['end']), 'paid'));
                $baseCpl = ($bSpend > 0 && $bPaid > 0) ? round($bSpend / $bPaid, 2) : null;
            }
            $cpl = self::cpl($metaSpendTotal, $paidTotal);
            $out['metrics']['paid_cpl'] = [
                'unit' => 'EUR',
                'spend' => $metaSpendTotal,
                'paid_leads' => $paidTotal,
            ] + $cpl + [
                'comparison' => $cpl['value'] !== null
                    ? MonthComparison::compare($cpl['value'], $baseCpl !== null ? $cplTier : null, $baseCpl, $windows)
                    : MonthComparison::noComparison(),
            ];
        }

        // ── Visitas ao site por origem (GA4) ───────────────────────────────────
        if ($this->ga4Connected($ga4Integration)) {
            $propertyId = (int) $ga4Integration->property_id;
            try {
                $cur = $this->ga4->sessionsByChannel($companyId, $propertyId, $period['start'], $period['end']);
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
                $curGroups = MonthComparison::groupChannels($cur['by_channel']);
                $baseGroups = $ga4Base ? MonthComparison::groupChannels($ga4Base['by_channel']) : null;

                $byGroup = [];
                foreach ($curGroups as $g => $v) {
                    $byGroup[$g] = ['total' => $v, 'comparison' => MonthComparison::compare($v, $ga4Tier, $baseGroups[$g] ?? null, $windows)];
                }
                $out['metrics']['ga4_sessions'] = [
                    'unit' => 'sessions',
                    'total' => $cur['total'],
                    'by_group' => $byGroup,
                    'series' => array_map(fn ($d) => ['date' => $d] + MonthComparison::groupChannels($cur['series'][$d] ?? []), MonthComparison::dates($period)),
                    'comparison' => MonthComparison::compare($cur['total'], $ga4Tier, $ga4Base['total'] ?? null, $windows),
                ];
            } catch (\Throwable $e) {
                Log::warning('[AutomotiveMarketing] GA4 indisponível', [
                    'company_id' => $companyId,
                    'error' => \App\Services\Ga4\Ga4Redact::message($e->getMessage()),
                ]);
                $out['sources']['ga4'] = [
                    'state' => 'error',
                    'error' => 'Não foi possível ler os dados do GA4. Confirme o acesso da Service Account à propriedade.',
                ];
            }
        }

        // ── Vendas do mês: só contexto (sem delta nem comparação) ─────────────
        $sales = DB::table('car_sales')
            ->where('company_id', $companyId)
            ->whereBetween('sold_at', [$period['start'] . ' 00:00:00', $period['end'] . ' 23:59:59'])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(sale_price), 0) as revenue, SUM(CASE WHEN sale_price IS NULL THEN 1 ELSE 0 END) as without_value')
            ->first();
        $out['metrics']['sales'] = [
            'context_only' => true,
            'count' => (int) ($sales->n ?? 0),
            'revenue' => round((float) ($sales->revenue ?? 0), 2),
            'without_value' => (int) ($sales->without_value ?? 0),
        ];

        // ── Aviso de qualidade: possíveis anúncios sem parâmetros UTM ─────────
        $organicSocial = $this->leadsByChannel($companyId, $period['start'], $period['end'], 'organic_social');
        if ($metaSpendTotal !== null && $metaSpendTotal > 0 && $paidTotal === 0 && $organicSocial > 0) {
            $out['quality_signals'][] = [
                'code' => 'possible_missing_utm',
                'meta_spend' => $metaSpendTotal,
                'paid_leads' => 0,
                'organic_social_leads' => $organicSocial,
            ];
        }

        return $out;
    }

    /**
     * Custo por lead pago. Nunca divide por zero:
     *   · gasto e leads pagas → value, state 'ok';
     *   · gasto sem lead paga → value null, state 'spend_without_lead';
     *   · sem gasto           → value null, state 'no_spend'.
     *
     * @return array{value: ?float, state: string}
     */
    public static function cpl(?float $spend, int $paidLeads): array
    {
        if ($spend === null || $spend <= 0) {
            return ['value' => null, 'state' => 'no_spend'];
        }
        if ($paidLeads <= 0) {
            return ['value' => null, 'state' => 'spend_without_lead'];
        }

        return ['value' => round($spend / $paidLeads, 2), 'state' => 'ok'];
    }

    // ── Fontes de dados ───────────────────────────────────────────────────────

    /** Primeiro dia com tracking do site (vistas, interações ou leads) da empresa. */
    /**
     * Estado do registo de visitas no período:
     *   · no_data → nunca houve registos;
     *   · stale   → o último registo é mais antigo do que TRACKING_STALE_DAYS antes do
     *               fim do período (o registo parou; os zeros podem não ser reais).
     *               days_without = dias sem registos até hoje (mês em curso) ou até ao
     *               fim do período (mês passado);
     *   · ok      → com registos recentes.
     */
    private function trackingState(?CarbonImmutable $start, ?CarbonImmutable $last, array $period, bool $isCurrent, CarbonImmutable $today): array
    {
        if ($start === null || $last === null) {
            return ['state' => 'no_data', 'since' => null, 'last_seen' => null, 'days_without' => null];
        }

        $periodEnd = CarbonImmutable::parse($period['end']);
        $reference = $isCurrent ? $today : $periodEnd;
        $stale = $last->lt($periodEnd->subDays(self::TRACKING_STALE_DAYS));

        return [
            'state' => $stale ? 'stale' : 'ok',
            'since' => $start->toDateString(),
            'last_seen' => $last->toDateString(),
            'days_without' => $stale ? (int) $last->diffInDays($reference) : null,
            'stale_after_days' => self::TRACKING_STALE_DAYS,
        ];
    }

    /** Último dia com registo do site (vistas, interações ou leads) da empresa. */
    private function trackingLast(int $companyId): ?CarbonImmutable
    {
        $dates = array_filter([
            DB::table('car_views')->where('company_id', $companyId)->max('created_at'),
            DB::table('car_interactions')->where('company_id', $companyId)->max('created_at'),
            DB::table('car_leads')->where('company_id', $companyId)->max('created_at'),
        ]);

        return $dates === [] ? null : CarbonImmutable::parse(max($dates))->startOfDay();
    }

    private function trackingStart(int $companyId): ?CarbonImmutable
    {
        $dates = array_filter([
            DB::table('car_views')->where('company_id', $companyId)->min('created_at'),
            DB::table('car_interactions')->where('company_id', $companyId)->min('created_at'),
            DB::table('car_leads')->where('company_id', $companyId)->min('created_at'),
        ]);

        return $dates === [] ? null : CarbonImmutable::parse(min($dates))->startOfDay();
    }

    /** Leads por dia: [data => [total, paid]]. */
    private function dailyLeads(int $companyId, string $from, string $to): array
    {
        return DB::table('car_leads')
            ->where('company_id', $companyId)
            ->whereRaw('DATE(created_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw("DATE(created_at) as d, COUNT(*) as total, SUM(CASE WHEN channel = 'paid' THEN 1 ELSE 0 END) as paid")
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => ['total' => (int) $r->total, 'paid' => (int) $r->paid]])
            ->all();
    }

    private function leadsByChannel(int $companyId, string $from, string $to, string $channel): int
    {
        return DB::table('car_leads')
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->whereRaw('DATE(created_at) BETWEEN ? AND ?', [$from, $to])
            ->count();
    }

    /** Contactos diretos por dia: [data => [total, whatsapp, call, phone_reveal]]. */
    private function dailyContacts(int $companyId, string $from, string $to): array
    {
        return DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->whereIn('interaction_type', self::CONTACT_TYPES)
            ->whereRaw('DATE(created_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw("DATE(created_at) as d, COUNT(*) as total,
                SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) as whatsapp,
                SUM(CASE WHEN interaction_type = 'call_click' THEN 1 ELSE 0 END) as call_clicks,
                SUM(CASE WHEN interaction_type IN ('show_phone', 'copy_phone') THEN 1 ELSE 0 END) as phone_reveal")
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->d => [
                'total' => (int) $r->total,
                'whatsapp' => (int) $r->whatsapp,
                'call' => (int) $r->call_clicks,
                'phone_reveal' => (int) $r->phone_reveal,
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
}
