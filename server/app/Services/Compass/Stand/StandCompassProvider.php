<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Models\EditorialPost;
use App\Models\User;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\CompassProvider;

/**
 * Bússola comum: o fornecedor do stand (veículos: carros e autocaravanas). Sem Finanças, sem o
 * valor do stock, o gasto em anúncios e o custo por contacto (o servidor não os envia).
 */
final class StandCompassProvider implements CompassProvider
{
    public function __construct(private readonly StandCompassService $compass, private readonly CompassAccess $access) {}

    public function key(): string
    {
        return 'stand';
    }

    public function module(): string
    {
        return 'stock';
    }

    public function isEnabled(int $companyId): bool
    {
        return StandSignalService::isEnabled($companyId);
    }

    public function pageFor(User $user, int $companyId, array $options = []): array
    {
        $fin = CompassAccess::seesFinance($user, $companyId);
        [$can, $reason] = $this->access->canAct($user, $companyId);

        return $this->compass->payload($companyId, $user, (string) ($options['type'] ?? 'all'), (bool) ($options['plays_only'] ?? false), $fin)
            + ['financial' => $fin ? ['visible' => true, 'note' => null] : ['visible' => false, 'note' => CompassAccess::NO_FINANCE_NOTE],
                'can_act' => $can, 'can_act_reason' => $reason, 'formats' => EditorialPost::FORMATS];
    }
}
