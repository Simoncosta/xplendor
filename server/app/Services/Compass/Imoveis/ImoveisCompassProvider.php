<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Models\EditorialPost;
use App\Models\User;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\CompassProvider;

/** Bússola comum: o fornecedor do imobiliário (módulo imoveis; o interruptor é o próprio módulo). */
final class ImoveisCompassProvider implements CompassProvider
{
    public function __construct(private readonly ImoveisCompassService $compass, private readonly CompassAccess $access) {}

    public function key(): string
    {
        return 'imobiliario';
    }

    public function module(): string
    {
        return 'imoveis';
    }

    public function isEnabled(int $companyId): bool
    {
        return ImoveisSignalService::isEnabled($companyId);
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
