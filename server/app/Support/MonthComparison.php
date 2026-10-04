<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Comparação de UM mês com escalões (padrão do bloco de marketing).
 *
 * Mesmas regras do bloco da restauração (RestaurantMarketingService, que mantém
 * a sua cópia por agora; DÍVIDA TÉCNICA registada em documents/DIVIDA-TECNICA.md:
 * a restauração passar a usar esta classe):
 *   1. 'same_month_last_year' — mesmo mês do ano anterior, SÓ se a base estiver
 *      completa (quem chama diz o que é "completo" para cada fonte);
 *   2. 'previous_month'       — senão, mês anterior, com seasonality_warning=true;
 *   3. sem escalão            — {tier: null, reason: 'no_history'}.
 * Mês em curso: só dias completos (até ontem) e os MESMOS DIAS (1..N contra 1..N).
 * Mês passado: mês inteiro contra mês inteiro. Nunca inventa %: base ≤ 0 → null.
 */
final class MonthComparison
{
    public const TIER_SAME_MONTH_LAST_YEAR = 'same_month_last_year';
    public const TIER_PREVIOUS_MONTH = 'previous_month';

    /** sessionDefaultChannelGroup do GA4 → grupos do dashboard. O resto → 'other'. */
    public const GA4_GROUPS = [
        'paid' => ['Paid Search', 'Paid Social', 'Display', 'Paid Video', 'Paid Shopping', 'Paid Other', 'Cross-network'],
        'organic_social' => ['Organic Social'],
        'search' => ['Organic Search'],
        'direct' => ['Direct'],
    ];

    /**
     * Período do mês e janelas de comparação.
     *
     * @return array{month: string, is_current: bool, period: array{start: string, end: string, days: int}, windows: array}
     */
    public static function periodFor(?string $month, CarbonImmutable $today): array
    {
        $monthStart = $month
            ? CarbonImmutable::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : $today->startOfMonth();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $isCurrent = $monthStart->equalTo($today->startOfMonth());

        // Só dias COMPLETOS: no mês em curso, até ontem.
        $periodEnd = $isCurrent ? $today->subDay() : $monthEnd;
        $days = $periodEnd->lt($monthStart) ? 0 : (int) $monthStart->diffInDays($periodEnd) + 1;

        $windows = $days > 0 ? [
            self::TIER_SAME_MONTH_LAST_YEAR => self::window($monthStart->subYear(), $days, true),
            self::TIER_PREVIOUS_MONTH => self::window($monthStart->subMonth(), $days, $isCurrent),
        ] : [];

        return [
            'month' => $monthStart->format('Y-m'),
            'is_current' => $isCurrent,
            'period' => ['start' => $monthStart->toDateString(), 'end' => $periodEnd->toDateString(), 'days' => $days],
            'windows' => $windows,
        ];
    }

    /** Janela de N dias a começar em $start, sem passar do fim desse mês. */
    public static function window(CarbonImmutable $start, int $days, bool $sameDays): array
    {
        $monthEnd = $start->endOfMonth()->startOfDay();
        $end = $sameDays ? $start->addDays($days - 1) : $monthEnd;
        if ($end->gt($monthEnd)) {
            $end = $monthEnd;
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'reference_month' => $start->format('Y-m')];
    }

    /** 1.º escalão (por ordem) cuja janela cumpre $usable. */
    public static function firstTier(array $windows, callable $usable): ?string
    {
        foreach ($windows as $tier => $w) {
            if ($usable($w)) {
                return $tier;
            }
        }

        return null;
    }

    public static function noComparison(): array
    {
        return ['tier' => null, 'reason' => 'no_history'];
    }

    /** Delta de um total (em valor e %). */
    public static function compare(float|int $current, ?string $tier, float|int|null $base, array $windows): array
    {
        if ($tier === null || $base === null) {
            return self::noComparison();
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

    /** Agrupa canais GA4 nos grupos do dashboard (sempre com as 5 chaves). */
    public static function groupChannels(array $byChannel): array
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

    /** Todas as datas do período (série contínua). */
    public static function dates(array $period): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($period['start']); $d->lte(CarbonImmutable::parse($period['end'])); $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** [data => valor] → [{date, value}] contínuo (dias sem dados = 0). */
    public static function series(array $byDate, array $period): array
    {
        return array_map(fn ($date) => ['date' => $date, 'value' => $byDate[$date] ?? 0], self::dates($period));
    }
}
