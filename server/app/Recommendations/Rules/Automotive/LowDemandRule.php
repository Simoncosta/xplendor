<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Models\Car;

/**
 * Procura baixa (migrada do CarIssueEngine, mesmos critérios): pelo menos 14 dias
 * em stock e vistas abaixo de max(40, 55% da média do stock ativo), na janela.
 */
class LowDemandRule extends AutomotiveCarRule
{
    public const KEY = 'automotive_low_demand';

    public function key(): string
    {
        return self::KEY;
    }

    public function issueType(): string
    {
        return 'low_demand';
    }

    protected function analyze(Car $car, array $context): ?array
    {
        $days = (int) $car->days_in_stock;
        $views = (int) $car->views_count;
        $avgViews = max(1.0, (float) ($context['avg_views'] ?? 1));

        if ($days < 14 || $views >= max(40, ($avgViews * 0.55))) {
            return null;
        }

        $priority = 48 + min(18, max(0, (int) round(($avgViews - $views) / 6)));
        if ($days > 30) {
            $priority += 8;
        }

        return [
            'priority' => $priority,
            'title' => 'Procura baixa',
            'why' => sprintf(
                'Teve %d %s nos últimos %d dias. A média do stock ativo no mesmo período é de %s.',
                $views,
                $views === 1 ? 'vista' : 'vistas',
                (int) $context['window_days'],
                number_format($avgViews, 0, ',', ' ')
            ),
            'action_label' => 'Ganhar visibilidade',
            'suggestion' => 'Melhorar o destaque e reforçar a divulgação do anúncio.',
            'url' => "/cars/{$car->id}/analytics",
            'evidence' => [
                'avg_views' => round($avgViews, 1),
            ],
        ];
    }
}
