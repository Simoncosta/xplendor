<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Repositories\Contracts\CarInteractionRepositoryInterface;
use App\Repositories\Contracts\CarLeadRepositoryInterface;
use App\Repositories\Contracts\CarPerformanceMetricRepositoryInterface;
use App\Repositories\Contracts\CarSalePotentialScoreRepositoryInterface;
use App\Repositories\Contracts\CarViewRepositoryInterface;

class CarAnalyticsService
{
    public function __construct(
        protected CarRepositoryInterface                      $carRepository,
        protected CarViewRepositoryInterface                  $viewRepository,
        protected CarInteractionRepositoryInterface           $interactionRepository,
        protected CarLeadRepositoryInterface                  $leadRepository,
        protected CarPerformanceMetricRepositoryInterface     $performanceRepository,
        protected CarSalePotentialScoreRepositoryInterface    $potentialScoreRepository,
        protected SmartAdsRecommendationService               $smartAdsRecommendationService,
    ) {}

    public function show(Car $car): array
    {
        $views        = $this->viewRepository->countByCar($car->id);
        $interactions = $this->interactionRepository->countByCar($car->id);
        $leads        = $this->leadRepository->countByCar($car->id);

        $views24h = $this->viewRepository->countByCarSince($car->id, now()->subDay());
        $views7d  = $this->viewRepository->countByCarSince($car->id, now()->subDays(7));

        $interactionsBreakdown = $this->interactionRepository->groupByType($car->id);
        $trafficSources        = $this->viewRepository->groupByChannel($car->id);

        // ── Performance metrics (XPLDR-5) ─────────────────────────────────────
        $performanceSummary   = $this->performanceRepository->getSummary($car->id, $car->company_id);
        $performanceByChannel = $this->performanceRepository->getSummaryByChannel($car->id, $car->company_id);

        // ── Índice de Potencial de Venda (XPLDR-6) ────────────────────────────
        $latestScore   = $this->potentialScoreRepository->getLatest($car->id, $car->company_id);
        $scoreHistory  = $this->potentialScoreRepository->getHistory($car->id, $car->company_id, 90);
        $aiAnalysis = $this->carRepository->getAiAnalysisData($car->id, $car->company_id);
        $smartAdsRecommendation = $this->resolveRecommendation($car, $aiAnalysis);
        $recommendedCreative = $this->resolveRecommendedCreative($car, $smartAdsRecommendation);

        return [
            'car' => [
                'id'                     => $car->id,
                'price_gross'            => $car->price_gross,
                'price'                  => $car->price_gross,
                'brand'                  => $car->brand,
                'model'                  => $car->model,
                'version'                => $car->version,
                'created_at'             => $car->created_at,
                'analyses'               => $car->analyses,
                'license_plate'          => $car->license_plate,
                'fuel_type'              => $car->fuel_type,
                'transmission'           => $car->transmission,
                'power_hp'               => $car->power_hp,
                'engine_capacity_cc'     => $car->engine_capacity_cc,
                'doors'                  => $car->doors,
                'seats'                  => $car->seats,
                'exterior_color'         => $car->exterior_color,
                'segment'                => $car->segment,
                'registration_month'     => $car->registration_month,
                'registration_year'      => $car->registration_year,
                'condition'              => $car->condition,
                'has_spare_key'          => $car->has_spare_key,
                'has_manuals'            => $car->has_manuals,
                'origin'                 => $car->origin,
                'mileage_km'             => $car->mileage_km,
                'description_website_pt' => $car->description_website_pt,
                'images'                 => $car->images,
            ],
            'metrics' => [
                'views'         => $views,
                'interactions'  => $interactions,
                'leads'         => $leads,
                'views_24h'     => $views24h,
                'views_7d'      => $views7d,
                'interest_rate' => $views > 0
                    ? round(($interactions / $views) * 100, 2)
                    : 0,
            ],
            'interactions_breakdown' => $interactionsBreakdown,
            'traffic_sources'        => $trafficSources,
            'timeline'               => $this->buildTimeline($car),

            // ── Novos blocos ──────────────────────────────────────────────────
            'ai_analysis' => $aiAnalysis,
            'performance' => [
                'totals'     => $performanceSummary,
                'by_channel' => $performanceByChannel,
            ],
            'smart_ads_recommendation' => $smartAdsRecommendation,
            'recommended_creative' => $recommendedCreative,
            'potential_score' => $latestScore ? [
                'score'            => $latestScore->score,
                'classification'   => $latestScore->classification,
                'calculated_at'    => $latestScore->calculated_at,
                'price_vs_market'  => $latestScore->price_vs_market,
                'breakdown'        => $latestScore->score_breakdown,
                'triggered_by'     => $latestScore->triggered_by,
                'history'          => $scoreHistory->map(fn($h) => [
                    'score'          => $h->score,
                    'classification' => $h->classification,
                    'date'           => $h->calculated_at,
                    'triggered_by'   => $h->triggered_by,
                ])->values(),
            ] : null,
        ];
    }

    protected function resolveRecommendation(Car $car, ?array $aiAnalysis = null): ?array
    {
        $smartAds = $this->smartAdsRecommendationService->generate($car);

        if ($smartAds) {
            return [
                ...$smartAds,
                'platform' => $aiAnalysis['recommended_channel'] ?? null,
            ];
        }

        if (!$aiAnalysis) {
            return null;
        }

        $urgencyLevel = strtolower((string) ($aiAnalysis['urgency_level'] ?? ''));
        $action = match (true) {
            in_array($urgencyLevel, ['imediata', 'alta'], true) => 'test_campaign',
            ($aiAnalysis['recommended_channel'] ?? null) === null => 'do_not_invest',
            default => 'review_campaign',
        };

        $probability30d = (int) ($aiAnalysis['probability_30d'] ?? 0);
        $expectedLeads = $probability30d >= 70
            ? '6 a 10 leads'
            : ($probability30d >= 40 ? '3 a 6 leads' : '1 a 3 leads');

        return [
            'action' => $action,
            'platform' => $aiAnalysis['recommended_channel'] ?? null,
            'total_budget' => 0,
            'daily_budget' => 0,
            'duration_days' => 0,
            'confidence_score' => max(45, min(78, $probability30d)),
            'expected_leads' => $expectedLeads,
            'title' => 'Decisão suportada pela análise de inteligência',
            'summary' => $aiAnalysis['recommended_action']
                ?? 'A recomendação foi construída com base na análise estratégica atual desta viatura.',
            'reason' => $aiAnalysis['recommended_channel_reason']
                ?? ($aiAnalysis['score_justification'] ?? 'A análise identificou sinais relevantes para orientar a decisão desta viatura.'),
            'next_step' => $aiAnalysis['recommended_action']
                ?? 'Rever o contexto da viatura e decidir o próximo passo operacional.',
            'creative_direction' => null,
            'cta_primary_label' => 'Ver detalhe',
            'cta_secondary_label' => 'Analisar contexto',
            'source' => 'ai_analysis',
            'is_fallback' => true,
        ];
    }

    protected function resolveRecommendedCreative(Car $car, ?array $smartAdsRecommendation): ?array
    {
        if (!$smartAdsRecommendation || ($smartAdsRecommendation['action'] ?? null) === 'do_not_invest') {
            return null;
        }

        $ideas = $this->carRepository->getMarketingIdeasData($car->id, $car->company_id);
        if ($ideas->isEmpty()) {
            return null;
        }

        $preferredTypes = match ($smartAdsRecommendation['action']) {
            'scale_ads' => ['sale', 'engagement'],
            'test_campaign' => ['engagement', 'sale'],
            'review_campaign' => ['engagement', 'authority'],
            default => [],
        };

        foreach ($preferredTypes as $contentType) {
            $idea = $ideas->firstWhere('content_type', $contentType);
            if ($idea) {
                return $this->mapCreative($idea);
            }
        }

        $fallbackIdea = $ideas->first();
        return $fallbackIdea ? $this->mapCreative($fallbackIdea) : null;
    }

    protected function mapCreative($idea): array
    {
        return [
            'content_type' => $idea->content_type,
            'title' => $idea->title,
            'hook' => $idea->hooks[0] ?? null,
            'caption' => $idea->caption,
            'cta' => $idea->cta,
            'format' => $idea->formats[0] ?? null,
        ];
    }

    protected function buildTimeline(Car $car): array
    {
        $timeline = [];

        $timeline[] = [
            'type'       => 'car_created',
            'label'      => 'Carro publicado',
            'created_at' => $car->created_at,
            'icon'       => 'ri-add-circle-line',
            'color'      => 'success',
        ];

        foreach ($this->viewRepository->getGroupedTimelineByCar($car->id) as $viewGroup) {
            $total      = (int) $viewGroup->total;
            $timeline[] = [
                'type'            => 'view_group',
                'label'           => $total === 1
                    ? '1 visualização do anúncio'
                    : "{$total} visualizações do anúncio",
                'created_at'      => $viewGroup->grouped_at,
                'icon'            => 'ri-eye-line',
                'color'           => 'primary',
                'count'           => $total,
                'unique_visitors' => (int) $viewGroup->unique_visitors,
            ];
        }

        foreach ($this->interactionRepository->getTimelineByCar($car->id) as $interaction) {
            $timeline[] = [
                'type'       => 'interaction',
                'label'      => $this->mapInteractionLabel($interaction->interaction_type),
                'created_at' => $interaction->created_at,
                'icon'       => $this->mapInteractionIcon($interaction->interaction_type),
                'color'      => $this->mapInteractionColor($interaction->interaction_type),
            ];
        }

        foreach ($this->leadRepository->getTimelineByCar($car->id) as $lead) {
            $timeline[] = [
                'type'       => 'lead',
                'label'      => 'Lead registada',
                'created_at' => $lead->created_at,
                'icon'       => 'ri-user-follow-line',
                'color'      => 'warning',
            ];
        }

        usort($timeline, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

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
