<?php

declare(strict_types=1);

namespace App\Recommendations\Contracts;

use App\Recommendations\RecommendationContext;
use App\Recommendations\RuleResult;

/**
 * XPLENDOR — Uma regra de especialista EXPLICÁVEL do motor de recomendações.
 * Cada regra diz o porquê (factos) e o que fazer (ação). Sem machine learning.
 */
interface RecommendationRule
{
    /** Identificador estável (é a chave da configuração por empresa). */
    public function key(): string;

    /** Ramos a que se aplica: 'restaurant', 'automotive'. */
    public function verticals(): array;

    /** Parâmetros por omissão (ex.: ['max_days' => 45]). */
    public function defaultParams(): array;

    /** Normaliza/valida os parâmetros (ex.: limita max_days a 30–60). */
    public function normalizeParams(array $params): array;

    /** Avalia a regra para a empresa; devolve 0+ recomendações e/ou um aviso. */
    public function evaluate(RecommendationContext $context, array $params): RuleResult;
}
