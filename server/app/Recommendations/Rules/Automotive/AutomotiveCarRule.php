<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Models\Car;
use App\Recommendations\Contracts\RecommendationRule;
use App\Recommendations\Recommendation;
use App\Recommendations\RecommendationContext;
use App\Recommendations\RuleResult;
use App\Services\Automotive\AutomotiveStockSnapshot;

/**
 * Base das regras por viatura do ramo Automóvel (migradas do CarIssueEngine).
 *
 * Mesmos critérios e prioridades do motor antigo; a diferença é a janela dos
 * sinais (vistas, leads, interações): window_days, 30 por omissão, entre 7 e 90.
 * Cada viatura que cumpre a regra gera uma recomendação com o porquê em números.
 */
abstract class AutomotiveCarRule implements RecommendationRule
{
    public const MIN_WINDOW = 7;
    public const MAX_WINDOW = 90;
    public const DEFAULT_WINDOW = 30;

    /** Tipo de problema (chave estável, igual à do motor antigo). */
    abstract public function issueType(): string;

    /**
     * Avalia uma viatura. Devolve null (não se aplica) ou
     * ['priority' => int, 'title' => string, 'why' => string, 'action_label' => string,
     *  'suggestion' => string, 'url' => string, 'evidence' => array, 'issue_type'?: string].
     * 'issue_type' substitui o da regra quando a mesma regra cobre um caso fundido.
     */
    abstract protected function analyze(Car $car, array $context): ?array;

    public function verticals(): array
    {
        return ['automotive'];
    }

    public function defaultParams(): array
    {
        return ['window_days' => self::DEFAULT_WINDOW];
    }

    public function normalizeParams(array $params): array
    {
        $days = (int) ($params['window_days'] ?? self::DEFAULT_WINDOW);

        return ['window_days' => max(self::MIN_WINDOW, min(self::MAX_WINDOW, $days))];
    }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        $snapshot = app(AutomotiveStockSnapshot::class)->for($context->company->id, $params['window_days'], $context->now);

        $recommendations = [];
        foreach ($snapshot['cars'] as $car) {
            $issue = $this->analyze($car, $snapshot['context']);
            if ($issue === null) {
                continue;
            }

            $recommendations[] = new Recommendation(
                ruleKey: $this->key(),
                priority: min(100, (int) $issue['priority']),
                title: $issue['title'],
                why: $issue['why'],
                evidence: [
                    'car_id' => $car->id,
                    'car_title' => AutomotiveStockSnapshot::carTitle($car),
                    'price' => AutomotiveStockSnapshot::effectivePrice($car),
                    'issue_type' => $issue['issue_type'] ?? $this->issueType(),
                    'signals' => [
                        'days_in_stock' => (int) $car->days_in_stock,
                        'views' => (int) $car->views_count,
                        'leads' => (int) $car->leads_count,
                        'interactions' => (int) $car->interactions_count,
                        'window_days' => (int) $snapshot['context']['window_days'],
                    ],
                ] + $issue['evidence'],
                action: [
                    'label' => $issue['action_label'],
                    'suggestion' => $issue['suggestion'],
                    'url' => $issue['url'],
                ],
                generatedAt: $context->now,
            );
        }

        return new RuleResult($recommendations);
    }

    protected static function money(?float $value): string
    {
        return number_format((float) $value, 0, ',', ' ') . ' €';
    }

    protected static function pct(float $value): string
    {
        return number_format($value, 1, ',', ' ') . '%';
    }
}
