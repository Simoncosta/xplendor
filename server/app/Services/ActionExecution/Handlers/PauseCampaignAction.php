<?php

namespace App\Services\ActionExecution\Handlers;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Services\ActionExecution\Contracts\ActionExecutionHandlerInterface;
use App\Services\MetaAdsService;
use App\Services\MetaAdsTargetResolver;
use Illuminate\Support\Facades\Log;

class PauseCampaignAction implements ActionExecutionHandlerInterface
{
    public function __construct(
        protected MetaAdsService $metaAdsService,
        protected MetaAdsTargetResolver $targetResolver,
    ) {}

    public function execute(Car $car, array $context = []): array
    {
        $mappings = CarAdCampaign::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where('platform', 'meta')
            ->where('is_active', true)
            ->when(
                !empty($context['mapping_id']),
                fn($query) => $query->where('id', (int) $context['mapping_id'])
            )
            ->when(
                !empty($context['ad_id']),
                fn($query) => $query->where('ad_id', (string) $context['ad_id'])
            )
            ->when(
                !empty($context['adset_id']),
                fn($query) => $query->where('adset_id', (string) $context['adset_id'])
            )
            ->when(
                !empty($context['campaign_id']),
                fn($query) => $query->where(function ($subQuery) use ($context) {
                    $subQuery
                        ->where('campaign_id', (string) $context['campaign_id'])
                        ->orWhere('id', (int) $context['campaign_id']);
                })
            )
            ->orderByDesc('created_at')
            ->get();

        if ($mappings->isEmpty()) {
            throw new \DomainException('Não existe campanha ativa compatível para pausar.');
        }

        if (empty($context['mapping_id']) && $mappings->count() > 1) {
            return [
                'action' => 'pause_campaign',
                'success' => false,
                'code' => 'selection_required',
                'status' => 'selection_required',
                'message' => 'Existem vários anúncios ou conjuntos associados a esta viatura. Escolhe qual queres pausar.',
                'data' => [
                    'action' => 'pause_campaign',
                    'status' => 'selection_required',
                    'options' => $mappings
                        ->map(fn(CarAdCampaign $mapping) => $this->targetResolver->describeMapping($mapping))
                        ->values()
                        ->all(),
                ],
            ];
        }

        $campaign = $mappings->first();

        Log::info('[MetaPause] START', [
            'company_id' => $car->company_id,
            'car_id' => $car->id,
            'mapping_id' => $campaign->id,
        ]);

        $target = $this->targetResolver->resolveMetaTarget($campaign);

        if (empty($target['id']) || empty($target['level'])) {
            throw new \DomainException('Não foi possível resolver o target Meta Ads para esta ação.');
        }

        Log::info('[MetaPause] TARGET RESOLVED', [
            'company_id' => $car->company_id,
            'car_id' => $car->id,
            'mapping_id' => $campaign->id,
            'target_level' => $target['level'],
            'target_id' => $target['id'],
        ]);

        try {
            $metaResponse = match ($target['level']) {
                'ad' => $this->metaAdsService->pauseAd($car->company_id, $target['id']),
                'adset' => $this->metaAdsService->pauseAdSet($car->company_id, $target['id']),
                'campaign' => $this->metaAdsService->pauseCampaign($car->company_id, $target['id']),
                default => throw new \DomainException('Nível inválido para pausa na Meta.'),
            };

            $campaign->update(['is_active' => false]);

            Log::info('PauseCampaignAction: pause executed remotely', [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'platform' => 'meta',
                'target_level' => $target['level'],
                'target_id' => $target['id'],
                'mapping_id' => $campaign->id,
            ]);

            return [
                'action' => 'pause_campaign',
                'status' => 'executed',
                'message' => 'Campanha pausada na Meta com sucesso.',
                'data' => [
                    'action' => 'pause_campaign',
                    'status' => 'executed',
                    'execution_mode' => 'remote_meta',
                    'target_level' => $target['level'],
                    'target_id' => $target['id'],
                    'meta_response' => $metaResponse['meta_response'] ?? $metaResponse,
                    'campaign' => [
                        'id' => $campaign->id,
                        'campaign_id' => $campaign->campaign_id,
                        'campaign_name' => $campaign->campaign_name,
                        'is_active' => false,
                    ],
                    'meta' => $metaResponse,
                ],
            ];
        } catch (\Throwable $exception) {
            $campaign->update(['is_active' => false]);

            Log::warning('[MetaPause] FALLBACK LOCAL', [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'mapping_id' => $campaign->id,
                'target_level' => $target['level'],
                'target_id' => $target['id'],
            ]);

            Log::error('PauseCampaignAction: remote pause failed, local fallback applied', [
                'company_id' => $car->company_id,
                'car_id' => $car->id,
                'platform' => 'meta',
                'target_level' => $target['level'],
                'target_id' => $target['id'],
                'mapping_id' => $campaign->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'action' => 'pause_campaign',
                'status' => 'partial',
                'message' => 'Falha ao pausar na Meta. Aplicado localmente.',
                'warning' => $exception->getMessage(),
                'data' => [
                    'action' => 'pause_campaign',
                    'status' => 'partial',
                    'execution_mode' => 'local_fallback',
                    'target_level' => $target['level'],
                    'target_id' => $target['id'],
                    'meta_response' => method_exists($exception, 'getResponse')
                        ? $exception->getResponse()
                        : null,
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
}
