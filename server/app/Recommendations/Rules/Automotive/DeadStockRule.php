<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Support\AutomotivePriceComparison;

/**
 * Stock parado (migrada do CarIssueEngine, mesmos critérios): dias em stock ≥
 * limiar do TIPO (StockThresholds) e no máximo 1 lead na janela.
 *
 * Sem duplicados (2D): a viatura acima do mercado (comparação exata) e parada sai
 * pela regra de preço, já fundida ("Acima do mercado e parada"). "Acima do mercado"
 * só conta com comparação EXATA: uma aproximada não sobe a prioridade nem sugere
 * rever o preço.
 */
class DeadStockRule extends AutomotiveCarRule
{
    public const KEY = 'automotive_dead_stock';

    public function key(): string
    {
        return self::KEY;
    }

    public function issueType(): string
    {
        return 'dead_stock';
    }

    protected function analyze(Car $car, array $context): ?array
    {
        $days = (int) $car->days_in_stock;
        $views = (int) $car->views_count;
        $leads = (int) $car->leads_count;

        $threshold = StockThresholds::ageThresholdFor($car->vehicle_type);
        if ($days < $threshold || $leads > 1) {
            return null;
        }

        if (PriceAboveMarketRule::fires($car)) {
            return null;   // coberta pela recomendação fundida da regra de preço
        }

        $aboveMarket = ($car->market['market_position'] ?? null) === 'above_market'
            && ! AutomotivePriceComparison::isApproximate($car);
        $priority = 72 + min(18, (int) floor(($days - $threshold) / 10) * 3);
        if ($aboveMarket) {
            $priority += 8;
        }
        if ($views < 80) {
            $priority += 4;
        }

        return [
            'priority' => $priority,
            'title' => 'Stock parado',
            'why' => sprintf(
                'Está em stock há %d dias, acima do limiar de %d dias para este tipo de viatura, com %d %s nos últimos %d dias.',
                $days,
                $threshold,
                $leads,
                $leads === 1 ? 'lead' : 'leads',
                (int) $context['window_days']
            ),
            'action_label' => 'Desbloquear rotação',
            'suggestion' => $aboveMarket
                ? 'Rever o preço e relançar o anúncio.'
                : 'Criar um novo destaque e atualizar o anúncio.',
            'url' => "/cars/{$car->id}",
            'evidence' => [
                'threshold_days' => $threshold,
            ],
        ];
    }
}
