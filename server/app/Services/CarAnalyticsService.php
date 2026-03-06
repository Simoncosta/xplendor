<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarInteractionRepositoryInterface;
use App\Repositories\Contracts\CarLeadRepositoryInterface;
use App\Repositories\Contracts\CarViewRepositoryInterface;

class CarAnalyticsService
{
    public function __construct(
        protected CarViewRepositoryInterface $viewRepository,
        protected CarInteractionRepositoryInterface $interactionRepository,
        protected CarLeadRepositoryInterface $leadRepository,
    ) {}

    public function show(Car $car): array
    {
        $views = $this->viewRepository->countByCar($car->id);
        $interactions = $this->interactionRepository->countByCar($car->id);
        $leads = $this->leadRepository->countByCar($car->id);

        $views24h = $this->viewRepository->countByCarSince($car->id, now()->subDay());
        $views7d = $this->viewRepository->countByCarSince($car->id, now()->subDays(7));

        $interactionsBreakdown = $this->interactionRepository->groupByType($car->id);
        $trafficSources = $this->viewRepository->groupByChannel($car->id);

        return [
            'car' => [
                'id' => $car->id,
                'brand' => $car->brand,
                'model' => $car->model,
                'version' => $car->version,
                'price' => $car->price_gross,
                'created_at' => $car->created_at,
            ],
            'metrics' => [
                'views' => $views,
                'interactions' => $interactions,
                'leads' => $leads,
                'views_24h' => $views24h,
                'views_7d' => $views7d,
                'interest_rate' => $views > 0
                    ? round(($interactions / $views) * 100, 2)
                    : 0,
            ],
            'interactions_breakdown' => $interactionsBreakdown,
            'traffic_sources' => $trafficSources,
            'timeline' => $this->buildTimeline($car),
        ];
    }

    protected function buildTimeline(Car $car): array
    {
        $timeline = [];

        $timeline[] = [
            'type' => 'car_created',
            'label' => 'Carro publicado',
            'created_at' => $car->created_at,
            'icon' => 'ri-add-circle-line',
            'color' => 'success',
        ];

        foreach ($this->viewRepository->getGroupedTimelineByCar($car->id) as $viewGroup) {
            $total = (int) $viewGroup->total;

            $timeline[] = [
                'type' => 'view_group',
                'label' => $total === 1
                    ? '1 visualização do anúncio'
                    : "{$total} visualizações do anúncio",
                'created_at' => $viewGroup->grouped_at,
                'icon' => 'ri-eye-line',
                'color' => 'primary',
                'count' => $total,
                'unique_visitors' => (int) $viewGroup->unique_visitors,
            ];
        }

        foreach ($this->interactionRepository->getTimelineByCar($car->id) as $interaction) {
            $timeline[] = [
                'type' => 'interaction',
                'label' => $this->mapInteractionLabel($interaction->interaction_type),
                'created_at' => $interaction->created_at,
                'icon' => $this->mapInteractionIcon($interaction->interaction_type),
                'color' => $this->mapInteractionColor($interaction->interaction_type),
            ];
        }

        foreach ($this->leadRepository->getTimelineByCar($car->id) as $lead) {
            $timeline[] = [
                'type' => 'lead',
                'label' => 'Lead registada',
                'created_at' => $lead->created_at,
                'icon' => 'ri-user-follow-line',
                'color' => 'warning',
            ];
        }

        usort($timeline, function ($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });

        return $timeline;
    }

    protected function mapInteractionLabel(string $interactionType): string
    {
        return match ($interactionType) {
            'whatsapp_click' => 'Clique em WhatsApp',
            'call_click' => 'Clique em chamada',
            'show_phone' => 'Visualização do telefone',
            'copy_phone' => 'Cópia do telefone',
            'favorite' => 'Carro adicionado aos favoritos',
            'share' => 'Partilha do anúncio',
            'form_open' => 'Abertura do formulário',
            'form_start' => 'Início de preenchimento do formulário',
            'location_view' => 'Visualização da localização',
            default => 'Interação registada',
        };
    }

    protected function mapInteractionIcon(string $interactionType): string
    {
        return match ($interactionType) {
            'whatsapp_click' => 'ri-whatsapp-line',
            'call_click' => 'ri-phone-line',
            'show_phone' => 'ri-smartphone-line',
            'copy_phone' => 'ri-file-copy-line',
            'favorite' => 'ri-heart-line',
            'share' => 'ri-share-line',
            'form_open' => 'ri-file-list-line',
            'form_start' => 'ri-edit-line',
            'location_view' => 'ri-map-pin-line',
            default => 'ri-cursor-line',
        };
    }

    protected function mapInteractionColor(string $interactionType): string
    {
        return match ($interactionType) {
            'whatsapp_click' => 'success',
            'call_click' => 'info',
            'show_phone' => 'warning',
            'copy_phone' => 'secondary',
            'favorite' => 'danger',
            'share' => 'primary',
            'form_open' => 'dark',
            'form_start' => 'secondary',
            'location_view' => 'primary',
            default => 'info',
        };
    }
}
