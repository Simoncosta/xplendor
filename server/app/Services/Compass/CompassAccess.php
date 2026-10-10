<?php

declare(strict_types=1);

namespace App\Services\Compass;

use App\Access\Access;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Editorial\EditorialWorkflowService;

/**
 * Quem pode agir a partir da Bússola (criar publicação, sugerir texto, ignorar): a Linha
 * Editorial ativa e a pessoa produzir nela. E quem vê os valores financeiros (financas.ver).
 */
final class CompassAccess
{
    public const NO_FINANCE_NOTE = 'Sem acesso aos valores financeiros';

    public function __construct(private readonly CompanyModuleService $modules) {}

    /** @return array{0: bool, 1: ?string} [pode, motivo se não pode] */
    public function canAct(User $user, int $companyId): array
    {
        if (! $this->modules->isEnabled($companyId, 'linha_editorial')) {
            return [false, 'A Linha Editorial não está ativa nesta empresa.'];
        }
        if (! EditorialWorkflowService::isProducer($user, $companyId)) {
            return [false, 'Só quem produz na Linha Editorial pode criar publicações ou ignorar sugestões.'];
        }

        return [true, null];
    }

    public static function seesFinance(?User $user, int $companyId): bool
    {
        return $user !== null && app(Access::class)->can($user, $companyId, 'financas.ver')->allowed;
    }
}
