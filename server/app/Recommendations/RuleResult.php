<?php

declare(strict_types=1);

namespace App\Recommendations;

/**
 * Resultado de uma regra: as recomendações e, opcionalmente, um AVISO honesto
 * quando a regra não consegue avaliar (ex.: sem permissão para ler os públicos).
 */
final class RuleResult
{
    /** @param Recommendation[] $recommendations */
    public function __construct(
        public readonly array $recommendations = [],
        public readonly ?array $notice = null, // ['code' => string, 'message' => string]
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
