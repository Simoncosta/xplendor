<?php

declare(strict_types=1);

namespace App\Services\Ga4;

use App\Models\CompanyConnectionEvent;
use App\Models\CompanyIntegration;
use App\Services\GoogleAnalyticsService;

/**
 * Ligar e desligar a propriedade GA4 de uma empresa (conta de serviço da XPLENDOR: só o ID da
 * propriedade, sem token por cliente), pela equipa ou pelo link de configuração do cliente.
 */
class Ga4ConnectionService
{
    public function __construct(private readonly GoogleAnalyticsService $ga) {}

    public function connect(int $companyId, string $propertyId, ?int $userId, ?int $setupLinkId = null): CompanyIntegration
    {
        $integration = CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'google'],
            [
                'property_id' => $propertyId,
                'access_token' => '',
                'status' => 'active',
                'error_message' => null,
                'connected_by_user_id' => $setupLinkId ? null : $userId,
                'setup_link_id' => $setupLinkId,
            ]
        );
        foreach ([7, 28, 90] as $days) {
            $this->ga->forget($companyId, (int) $propertyId, $days);
        }
        CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_GA4, CompanyConnectionEvent::CONNECTED, $setupLinkId ? null : $userId, $setupLinkId, ['property_id' => $propertyId]);

        return $integration;
    }

    public function disconnect(int $companyId, ?int $userId): void
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'google')->first();
        if (! $integration) {
            return;
        }
        if ($integration->status !== 'revoked' && $integration->property_id) {
            CompanyConnectionEvent::record($companyId, CompanyConnectionEvent::KIND_GA4, CompanyConnectionEvent::DISCONNECTED, $userId, null,
                ['property_id' => $integration->property_id, 'was_setup_link_id' => $integration->setup_link_id]);
        }
        $integration->update(['status' => 'revoked', 'property_id' => null]);
    }
}
