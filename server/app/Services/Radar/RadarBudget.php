<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\AiRequest;
use App\Models\Alert;
use App\Services\AlertService;
use App\Services\Billing\ChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * O teto de custo da IA do Radar (Bússola 1): o gasto do dia (dia civil de Lisboa) é a soma do
 * cost_usd em ai_requests das funções do Radar, no global e por empresa (config radar.budget).
 * Verifica-se antes de cada pedido; um pedido já feito nunca é cortado a meio, por isso o gasto
 * pode passar o teto no máximo um pedido. Ao bater no teto, o root recebe um aviso no sino, uma
 * vez por dia e por teto.
 */
class RadarBudget
{
    public const FUNCTIONS = [AiRequest::MODE_RADAR_RELEVANCIA, AiRequest::MODE_RADAR_PORQUE];

    public function __construct(private readonly AlertService $alerts) {}

    public static function globalCap(): float
    {
        return (float) config('radar.budget.global_daily_usd', 2.0);
    }

    public static function companyCap(): float
    {
        return (float) config('radar.budget.company_daily_usd', 0.3);
    }

    /** O gasto de hoje: no global (sem empresa) ou de uma empresa. */
    public static function spentToday(?int $companyId = null, ?CarbonImmutable $now = null): float
    {
        return (float) AiRequest::whereIn('mode', self::FUNCTIONS)
            ->where('created_at', '>=', self::startOfDay($now))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('cost_usd');
    }

    /**
     * Pode pedir à IA? Sem empresa (a relevância) só conta o global; com empresa (o "porque"),
     * também o da empresa. Ao bater num teto, avisa o root (uma vez por dia) e devolve false.
     */
    public function allows(?int $companyId = null, ?CarbonImmutable $now = null): bool
    {
        $global = self::spentToday(null, $now);
        if ($global >= self::globalCap()) {
            $this->reached('Radar: teto diário de custo atingido',
                sprintf('A IA do Radar gastou hoje %s USD (teto de %s USD por dia). Até à meia-noite de Lisboa, a relevância fica para o dia seguinte e o "porque lhe interessa" usa a frase de recurso.',
                    self::usd($global), self::usd(self::globalCap())), $now);

            return false;
        }
        if ($companyId !== null) {
            $company = self::spentToday($companyId, $now);
            if ($company >= self::companyCap()) {
                $this->reached("Radar: teto diário de custo atingido na empresa {$companyId}",
                    sprintf('O "porque lhe interessa" da empresa %d gastou hoje %s USD (teto de %s USD por empresa e por dia). Até à meia-noite de Lisboa, os sinais desta empresa usam a frase de recurso.',
                        $companyId, self::usd($company), self::usd(self::companyCap())), $now);

                return false;
            }
        }

        return true;
    }

    private function reached(string $title, string $message, ?CarbonImmutable $now): void
    {
        Log::warning('[Radar] Teto de custo atingido', ['aviso' => $title]);
        $team = ChargeService::teamCompanyId();
        if (! $team || Alert::where('company_id', $team)->where('title', $title)->where('created_at', '>=', self::startOfDay($now))->exists()) {
            return;
        }
        $this->alerts->createSystemAlert($team, 'warning', $title, $message, 'medium', '/admin/radar');
    }

    private static function startOfDay(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone('Europe/Lisbon')->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
    }

    private static function usd(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }
}
