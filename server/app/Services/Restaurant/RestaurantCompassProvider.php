<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\EditorialPost;
use App\Models\User;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\CompassProvider;
use App\Services\PingwinItemSalesService;

/**
 * Bússola comum: o fornecedor da restauração (os sinais das vendas do PingWin e das reservas
 * do CoverManager). A página continua a ser a de RestaurantCompassService, com a regra sem
 * Finanças de CompassFinancials; o interruptor é o da leitura das vendas por artigo.
 */
final class RestaurantCompassProvider implements CompassProvider
{
    public function __construct(private readonly RestaurantCompassService $compass, private readonly CompassAccess $access) {}

    public function key(): string
    {
        return 'restauracao';
    }

    public function module(): string
    {
        return 'pingwin';
    }

    public function isEnabled(int $companyId): bool
    {
        return PingwinItemSalesService::isEnabled($companyId);
    }

    public function pageFor(User $user, int $companyId, array $options = []): array
    {
        [$can, $reason] = $this->access->canAct($user, $companyId);
        $payload = CompassFinancials::forUser(
            $this->compass->payload($companyId, isset($options['location_id']) ? (int) $options['location_id'] : null, (bool) ($options['summary'] ?? false), (bool) ($options['plays_only'] ?? false)),
            $user, $companyId);

        return $payload + ['can_act' => $can, 'can_act_reason' => $reason, 'formats' => EditorialPost::FORMATS];
    }
}
