<?php

declare(strict_types=1);

namespace App\Recommendations;

use Carbon\CarbonImmutable;

/**
 * Uma recomendação explicável: prioridade (0–100) e nível, título, PORQUÊ (factos
 * em linguagem simples), evidências (números estruturados) e ação sugerida.
 */
final class Recommendation
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly int $priority,
        public readonly string $title,
        public readonly string $why,
        public readonly array $evidence,
        public readonly array $action, // ['label' => string, 'url' => string]
        public readonly CarbonImmutable $generatedAt,
    ) {}

    /** Nível a partir da prioridade: Alta ≥ 70, Média ≥ 40, Baixa < 40. */
    public function level(): string
    {
        return match (true) {
            $this->priority >= 70 => 'high',
            $this->priority >= 40 => 'medium',
            default => 'low',
        };
    }

    public function toArray(): array
    {
        return [
            'rule_key' => $this->ruleKey,
            'priority' => $this->priority,
            'level' => $this->level(),
            'title' => $this->title,
            'why' => $this->why,
            'evidence' => $this->evidence,
            'action' => $this->action,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
