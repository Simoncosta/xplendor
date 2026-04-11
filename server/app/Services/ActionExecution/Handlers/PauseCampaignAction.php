<?php

namespace App\Services\ActionExecution\Handlers;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Services\ActionExecution\Contracts\ActionExecutionHandlerInterface;

class PauseCampaignAction implements ActionExecutionHandlerInterface
{
    public function execute(Car $car, array $context = []): array
    {
        $campaign = CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where('is_active', true)
            ->when(
                !empty($context['campaign_id']),
                fn($query) => $query->where(function ($subQuery) use ($context) {
                    $subQuery
                        ->where('campaign_id', (string) $context['campaign_id'])
                        ->orWhere('id', (int) $context['campaign_id']);
                })
            )
            ->orderByDesc('created_at')
            ->first();

        if (!$campaign) {
            throw new \DomainException('Não existe campanha ativa compatível para pausar.');
        }

        $campaign->update(['is_active' => false]);

        return [
            'action' => 'pause_campaign',
            'status' => 'partial',
            'message' => 'Campanha pausada localmente.',
            'warning' => 'A campanha ainda pode estar ativa na Meta Ads. Integração remota não implementada.',
            'data' => [
                'action' => 'pause_campaign',
                'status' => 'partial',
                'execution_mode' => 'local_mapping',
                'campaign' => [
                    'id' => $campaign->id,
                    'campaign_id' => $campaign->campaign_id,
                    'campaign_name' => $campaign->campaign_name,
                    'is_active' => false,
                ],
            ],
        ];
    }
}
