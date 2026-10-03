<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Recommendations\Contracts\RecommendationRule;
use App\Recommendations\Recommendation;
use App\Recommendations\RecommendationContext;
use App\Recommendations\RuleResult;
use App\Services\Automotive\AutomotiveStockSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * "Muitas vistas, zero contactos" (janela de 14 dias por omissão).
 *
 * "Muitas" é relativo à empresa: vistas ≥ percentil 75 do stock ativo E ≥ min_views
 * (30 por omissão). Contactos = whatsapp_click + call_click + show_phone +
 * copy_phone (coluna contacts do view car_funnel_metrics_daily).
 */
class HighViewsNoContactsRule implements RecommendationRule
{
    public const KEY = 'automotive_high_views_no_contacts';

    public function key(): string
    {
        return self::KEY;
    }

    public function verticals(): array
    {
        return ['automotive'];
    }

    public function defaultParams(): array
    {
        return ['window_days' => 14, 'min_views' => 30];
    }

    public function normalizeParams(array $params): array
    {
        return [
            'window_days' => max(7, min(30, (int) ($params['window_days'] ?? 14))),
            'min_views' => max(10, min(500, (int) ($params['min_views'] ?? 30))),
        ];
    }

    /** Percentil por interpolação linear (R7). */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $h = (count($values) - 1) * $p;
        $lo = (int) floor($h);
        $hi = min($lo + 1, count($values) - 1);

        return (float) $values[$lo] + ($h - $lo) * ((float) $values[$hi] - (float) $values[$lo]);
    }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        $companyId = $context->company->id;
        $snapshot = app(AutomotiveStockSnapshot::class)->for($companyId, AutomotiveCarRule::DEFAULT_WINDOW, $context->now);
        $cars = $snapshot['cars'];
        if ($cars->isEmpty()) {
            return RuleResult::none();
        }

        $from = $context->now->subDays($params['window_days'] - 1)->toDateString();
        $to = $context->now->toDateString();

        $funnel = DB::table('car_funnel_metrics_daily')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $cars->pluck('id')->all())
            ->whereBetween('date', [$from, $to])
            ->selectRaw('car_id, COALESCE(SUM(views), 0) as views, COALESCE(SUM(contacts), 0) as contacts')
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $views = $cars->map(fn ($car) => (int) ($funnel->get($car->id)->views ?? 0))->all();
        $p75 = self::percentile($views, 0.75);
        $bar = max($p75, (float) $params['min_views']);

        $recommendations = [];
        foreach ($cars as $car) {
            $v = (int) ($funnel->get($car->id)->views ?? 0);
            $c = (int) ($funnel->get($car->id)->contacts ?? 0);
            if ($v < $bar || $c > 0) {
                continue;
            }

            $priority = 55 + min(20, max(0, (int) round(($v / max(1.0, $p75) - 1) * 20)));

            $recommendations[] = new Recommendation(
                ruleKey: self::KEY,
                priority: $priority,
                title: 'Muitas vistas, nenhum contacto',
                why: $p75 >= $params['min_views']
                    ? sprintf(
                        'Teve %d vistas nos últimos %d dias, acima do percentil 75 do stock (%s vistas), e nenhum contacto por WhatsApp, chamada ou telefone no mesmo período.',
                        $v,
                        $params['window_days'],
                        number_format($p75, 0, ',', ' ')
                    )
                    : sprintf(
                        'Teve %d vistas nos últimos %d dias, acima do mínimo de %d vistas desta verificação (o percentil 75 do stock é de %s vistas), e nenhum contacto por WhatsApp, chamada ou telefone no mesmo período.',
                        $v,
                        $params['window_days'],
                        $params['min_views'],
                        number_format($p75, 0, ',', ' ')
                    ),
                evidence: [
                    'car_id' => $car->id,
                    'car_title' => AutomotiveStockSnapshot::carTitle($car),
                    'issue_type' => 'high_views_no_contacts',
                    'views' => $v,
                    'contacts' => 0,
                    'p75_views' => round($p75, 1),
                    'min_views' => $params['min_views'],
                    'window_days' => $params['window_days'],
                ],
                action: [
                    'label' => 'Rever o anúncio',
                    'suggestion' => 'Confirmar que o WhatsApp e o telefone estão visíveis no anúncio e que o preço e as fotografias correspondem à viatura.',
                    'url' => "/cars/{$car->id}/analytics",
                ],
                generatedAt: $context->now,
            );
        }

        return new RuleResult($recommendations);
    }
}
