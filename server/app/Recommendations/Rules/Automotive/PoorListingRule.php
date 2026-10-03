<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Models\Car;

/**
 * Anúncio pouco convincente (migrada do CarIssueEngine, mesmos critérios): com pelo
 * menos 40 vistas na janela, menos de 6 fotos, descrição com menos de 180
 * caracteres, ou interações abaixo de 45% da média do stock ativo.
 */
class PoorListingRule extends AutomotiveCarRule
{
    public const KEY = 'automotive_poor_listing';

    public function key(): string
    {
        return self::KEY;
    }

    public function issueType(): string
    {
        return 'poor_listing';
    }

    protected function analyze(Car $car, array $context): ?array
    {
        $views = (int) $car->views_count;
        $interactions = (int) $car->interactions_count;
        $images = (int) (($car->images_count ?? 0) + ($car->external_images_count ?? 0));
        $descriptionLength = mb_strlen(trim((string) ($car->description_website_pt ?? '')));
        $avgInteractions = max(1.0, (float) ($context['avg_interactions'] ?? 1));
        $minInteractions = max(1, (int) floor($avgInteractions * 0.45));

        if ($views < 40) {
            return null;
        }

        $facts = [];
        if ($images < 6) {
            $facts[] = sprintf('tem %d %s (recomendado: 6 ou mais)', $images, $images === 1 ? 'fotografia' : 'fotografias');
        }
        if ($descriptionLength < 180) {
            $facts[] = sprintf('a descrição tem %d caracteres (recomendado: 180 ou mais)', $descriptionLength);
        }
        if ($interactions < $minInteractions) {
            $facts[] = sprintf('teve %d %s em %d vistas nos últimos %d dias', $interactions, $interactions === 1 ? 'interação' : 'interações', $views, (int) $context['window_days']);
        }

        if ($facts === []) {
            return null;
        }

        return [
            'priority' => 44 + (count($facts) * 8),
            'title' => 'Anúncio pouco convincente',
            'why' => 'O anúncio ' . implode('; ', $facts) . '.',
            'action_label' => 'Melhorar anúncio',
            'suggestion' => 'Atualizar as fotografias, o título e a descrição.',
            'url' => "/cars/{$car->id}",
            'evidence' => [
                'images_count' => $images,
                'description_length' => $descriptionLength,
                'avg_interactions' => round($avgInteractions, 1),
            ],
        ];
    }
}
