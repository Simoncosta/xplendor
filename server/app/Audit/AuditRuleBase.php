<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\AuditRule;

/** Valores por omissão das regras (cada regra declara o código, a área, a entidade e o título). */
abstract class AuditRuleBase implements AuditRule
{
    public function severity(): string
    {
        return 'media';
    }

    public function screens(): array
    {
        return [];
    }

    public function appliesTo(int $companyId): bool
    {
        return true;
    }

    public function description(): string
    {
        return '';
    }
}
