<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Support\AutomotivePriceComparison;

/**
 * Preço acima do mercado (migrada do CarIssueEngine, mesmos critérios).
 * Salvaguarda do hub: uma comparação APROXIMADA (aggregate de um degrau de
 * recurso) nunca gera esta recomendação.
 *
 * FUNDIDA com "Acima do mercado e parada" (2D): se a viatura também está em stock
 * há pelo menos o limiar do seu tipo (StockThresholds), sai UMA recomendação com as
 * duas causas (issue_type price_above_market_stale) e a regra de stock parado não
 * repete a viatura. Prioridade: a maior das duas regras de origem.
 */
class PriceAboveMarketRule extends AutomotiveCarRule
{
    public const KEY = 'automotive_price_above_market';

    public function key(): string
    {
        return self::KEY;
    }

    public function issueType(): string
    {
        return 'price_above_market';
    }

    /**
     * A regra dispara para esta viatura? Posição acima do mercado (já com confiança
     * média/alta, pelo PricePosition), mais de 5% acima da mediana e comparação EXATA.
     */
    public static function fires(Car $car): bool
    {
        $market = $car->market ?? [];
        $delta = $market['car_price_vs_median_pct'] ?? null;

        return ($market['market_position'] ?? null) === 'above_market'
            && $delta !== null && $delta > 5
            && ! AutomotivePriceComparison::isApproximate($car);
    }

    protected function analyze(Car $car, array $context): ?array
    {
        if (! self::fires($car)) {
            return null;
        }

        $market = $car->market;
        $delta = $market['car_price_vs_median_pct'];

        $price = self::effective($car);
        $recommended = $market['recommended_price'] ?? $price;
        $priority = 68 + min(22, (int) round(max(0, $delta - 5) * 1.8));

        $days = (int) $car->days_in_stock;
        $stale = StockThresholds::isStale($car->vehicle_type, $days);
        if ($stale) {
            $priority += 8;
        }
        if ((int) $car->leads_count === 0) {
            $priority += 6;
        }

        $why = sprintf(
            'O preço de %s está %s acima da mediana de %d anúncios comparáveis (%s).',
            self::money($price),
            self::pct((float) $delta),
            (int) ($market['competitors_count'] ?? 0),
            self::money($market['market_median_price'] ?? null)
        );

        $merged = [];
        if ($stale) {
            // Caso fundido "Acima do mercado e parada": a prioridade é a maior das regras
            // de origem que se aplicam (a de stock parado só com até 1 lead na janela,
            // como no motor antigo), com o bónus de acima do mercado.
            $threshold = StockThresholds::ageThresholdFor($car->vehicle_type);
            if ((int) $car->leads_count <= 1) {
                $deadPriority = 72 + min(18, (int) floor(($days - $threshold) / 10) * 3) + 8
                    + ((int) $car->views_count < 80 ? 4 : 0);
                $priority = max($priority, $deadPriority);
            }
            $why = sprintf(
                'O preço de %s está %s acima da mediana de %d anúncios comparáveis (%s) e a viatura está em stock há %d dias, acima do limiar de %d dias para este tipo de viatura.',
                self::money($price),
                self::pct((float) $delta),
                (int) ($market['competitors_count'] ?? 0),
                self::money($market['market_median_price'] ?? null),
                $days,
                $threshold
            );
            $merged = [
                'issue_type' => 'price_above_market_stale',
                'title' => 'Acima do mercado e parada',
                'threshold_days' => $threshold,
            ];
        }

        return [
            'priority' => $priority,
            'issue_type' => $merged['issue_type'] ?? 'price_above_market',
            'title' => $merged['title'] ?? 'Preço acima do mercado',
            'why' => $why,
            'action_label' => 'Rever preço',
            'suggestion' => $recommended
                ? 'Preço de referência: cerca de ' . self::money($recommended) . '.'
                : 'Rever o preço face aos anúncios comparáveis.',
            'url' => "/cars/{$car->id}/intelligence",
            'evidence' => [
                'difference_pct' => (float) $delta,
                'market_median' => $market['market_median_price'] ?? null,
                'comparables_count' => (int) ($market['competitors_count'] ?? 0),
                'recommended_price' => $recommended,
                'comparison' => AutomotivePriceComparison::EXACT,
                'confidence' => $car->latestPricedMarketAggregate?->confidence,
            ] + (isset($merged['threshold_days']) ? ['threshold_days' => $merged['threshold_days']] : []),
        ];
    }

    private static function effective(Car $car): ?float
    {
        return \App\Support\PricePosition::effectivePriceForCar($car);
    }
}
