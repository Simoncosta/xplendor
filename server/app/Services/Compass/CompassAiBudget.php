<?php

declare(strict_types=1);

namespace App\Services\Compass;

use App\Models\AiRequest;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Bússola: o limite mensal de pedidos à IA das frases "O quê" (bussola_jogadas), por empresa (decisão de
 * 23/10: 60 por mês). Conta os pedidos registados em ai_requests no mês civil de Lisboa, de todos os
 * ramos (restauração, stand e imobiliário). Esgotado (decisão de 24/10), as frases da IA já geradas nesse
 * mês ficam até ao mês seguinte; só as jogadas sem frase gerada recebem o modelo de frase (a frase de
 * recurso). O gasto aparece no painel do root, ao lado do teto do Radar.
 */
final class CompassAiBudget
{
    public const MODE = CompassTexts::AI_FUNCTION;

    public static function monthlyLimit(): int
    {
        return max(0, (int) config('radar.compass.monthly_requests_per_company', 60));
    }

    /**
     * Com o limite esgotado: ficam as frases geradas neste mês (as de meses anteriores saem). Devolve
     * quantas saíram. $query: as frases da empresa no ramo.
     */
    public static function keepThisMonthOnly(\Illuminate\Database\Eloquent\Builder $query, ?CarbonImmutable $now = null): int
    {
        return $query->where(fn ($q) => $q->whereNull('generated_at')->orWhere('generated_at', '<', self::monthStart($now)))->delete();
    }

    /** O início do mês civil de Lisboa (em UTC, como o created_at). */
    private static function monthStart(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone('Europe/Lisbon')->startOfMonth()->utc();
    }

    /** Os pedidos da empresa neste mês. */
    public static function used(int $companyId, ?CarbonImmutable $now = null): int
    {
        return AiRequest::where('mode', self::MODE)->where('company_id', $companyId)->where('created_at', '>=', self::monthStart($now))->count();
    }

    /** Ainda pode pedir à IA neste mês? Esgotado, regista no log (sem dados da empresa além do id). */
    public static function allows(int $companyId, ?CarbonImmutable $now = null): bool
    {
        $used = self::used($companyId, $now);
        if ($used < self::monthlyLimit()) {
            return true;
        }
        Log::info('[Bússola] Limite mensal das frases da IA atingido; ficam os modelos de frase.', ['company_id' => $companyId, 'pedidos' => $used, 'limite' => self::monthlyLimit()]);

        return false;
    }

    /** Para o painel do root: o limite, o gasto do mês e cada empresa (pedidos, custo, se esgotou). */
    public static function summary(?CarbonImmutable $now = null): array
    {
        $month = AiRequest::where('mode', self::MODE)->where('created_at', '>=', self::monthStart($now));
        $byCompany = (clone $month)->whereNotNull('company_id')->selectRaw('company_id, count(*) as requests, coalesce(sum(cost_usd), 0) as cost')
            ->groupBy('company_id')->orderByDesc('requests')->get();
        $names = Company::whereIn('id', $byCompany->pluck('company_id'))->get()->mapWithKeys(fn ($c) => [$c->id => $c->trade_name ?: $c->fiscal_name]);
        $limit = self::monthlyLimit();

        return [
            'function' => self::MODE,
            'monthly_limit' => $limit,
            'month' => self::monthStart($now)->setTimezone('Europe/Lisbon')->format('Y-m'),
            'requests_month' => (clone $month)->count(),
            'cost_month_usd' => round((float) (clone $month)->sum('cost_usd'), 4),
            'companies' => $byCompany->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'company' => $names[$r->company_id] ?? null, 'requests' => (int) $r->requests,
                'cost_usd' => round((float) $r->cost, 4), 'reached' => (int) $r->requests >= $limit,
            ])->values()->all(),
        ];
    }
}
