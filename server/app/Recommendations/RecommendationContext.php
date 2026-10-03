<?php

declare(strict_types=1);

namespace App\Recommendations;

use App\Models\Company;
use Carbon\CarbonImmutable;

/** Contexto de avaliação (empresa, ramo, "agora" injetável para testes). */
final class RecommendationContext
{
    public function __construct(
        public readonly Company $company,
        public readonly string $vertical,
        public readonly CarbonImmutable $now,
    ) {}
}
