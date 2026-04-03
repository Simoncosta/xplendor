<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\MetaAudienceInsight;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Repositories\Contracts\CarInteractionRepositoryInterface;
use App\Repositories\Contracts\CarLeadRepositoryInterface;
use App\Repositories\Contracts\CarMarketingIdeaRepositoryInterface;
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
        protected CarMarketingIdeaRepositoryInterface         $carMarketingIdeaRepository,
        protected CarPerformanceMetricRepositoryInterface     $performanceRepository,
        protected CarSalePotentialScoreRepositoryInterface    $potentialScoreRepository,
        protected SmartAdsRecommendationService               $smartAdsRecommendationService,
        protected SilentBuyerDetectionService                 $silentBuyerDetectionService,
        protected CarMarketIntelligenceService                $carMarketIntelligenceService,
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
        $promoDiscountValue = $car->promo_discount_value;
        $promoDiscountPct = $car->promo_discount_pct;
        $marketIntelligence = $this->carMarketIntelligenceService->analyze($car);

        return [
            'car' => [
                'id'                     => $car->id,
                'price_gross'            => $car->price_gross,
                'promo_price_gross'      => $car->promo_price_gross,
                'effective_price_gross'  => $car->promo_price_gross && $car->price_gross && $car->promo_price_gross < $car->price_gross
                    ? $car->promo_price_gross
                    : $car->price_gross,
                'has_promo_price'        => $car->promo_price_gross && $car->price_gross && $car->promo_price_gross < $car->price_gross,
                'promo_discount_value'   => $promoDiscountValue,
                'promo_discount_pct'     => $promoDiscountPct,
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
            'market_intelligence' => $marketIntelligence,
            'meta_ads_targeting_status' => $this->resolveMetaAdsTargetingStatus($car),
            'potential_score' => $latestScore ? [
                'score'            => $latestScore->score,
                'classification'   => $latestScore->classification,
                'calculated_at'    => $latestScore->calculated_at,
                'price_vs_market'  => $latestScore->price_vs_market,
                'effective_price_gross' => $latestScore->score_breakdown['pricing']['effective_price_gross'] ?? null,
                'has_promo_price' => $latestScore->score_breakdown['pricing']['has_promo_price'] ?? false,
                'promo_discount_value' => $latestScore->score_breakdown['pricing']['promo_discount_value'] ?? null,
                'promo_discount_pct' => $latestScore->score_breakdown['pricing']['promo_discount_pct'] ?? null,
                'breakdown'        => $latestScore->score_breakdown,
                'triggered_by'     => $latestScore->triggered_by,
                'history'          => $scoreHistory->map(fn($h) => [
                    'score'          => $h->score,
                    'classification' => $h->classification,
                    'date'           => $h->calculated_at,
                    'triggered_by'   => $h->triggered_by,
                ])->values(),
            ] : null,
            'silent_buyers' => $this->silentBuyerDetectionService->getCarSummary($car->company_id, $car->id),
        ];
    }

    protected function resolveMetaAdsTargetingStatus(Car $car): array
    {
        $mapping = CarAdCampaign::query()
            ->active()
            ->platform('meta')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->orderByDesc('updated_at')
            ->first();

        $resolvedAdsetId = $this->resolveAdsetId($mapping);

        if (!$mapping || !$resolvedAdsetId) {
            return [
                'status' => 'campaign_without_adset',
                'label' => 'Campanha sem ad set resolvido',
                'has_targeting' => false,
                'has_metrics' => false,
                'has_breakdown' => false,
                'mapping_level' => $mapping?->level,
                'resolved_adset_id' => null,
            ];
        }

        $hasMetrics = \App\Models\CarPerformanceMetric::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where('channel', 'paid')
            ->exists();

        $latestInsight = MetaAudienceInsight::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        $hasTargeting = !empty($latestInsight?->campaign_targeting_json);
        $hasBreakdown = MetaAudienceInsight::query()
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->where(function ($query) {
                $query->where('age_range', '!=', 'unknown')
                    ->orWhere('gender', '!=', 'unknown');
            })
            ->exists();

        return [
            'status' => $hasTargeting ? 'available' : 'unavailable',
            'label' => $hasTargeting ? 'Targeting disponível' : 'Targeting indisponível',
            'has_targeting' => $hasTargeting,
            'has_metrics' => $hasMetrics,
            'has_breakdown' => $hasBreakdown,
            'mapping_level' => $mapping->level,
            'resolved_adset_id' => $resolvedAdsetId,
        ];
    }

    protected function resolveAdsetId(?CarAdCampaign $mapping): ?string
    {
        if (!$mapping) {
            return null;
        }

        if (!empty($mapping->adset_id)) {
            return $mapping->adset_id;
        }

        if ($mapping->level === 'adset' && !empty($mapping->external_id)) {
            return $mapping->external_id;
        }

        return null;
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
        $action = $smartAdsRecommendation['action'] ?? null;

        if (!$smartAdsRecommendation || $action === 'do_not_invest') {
            return null;
        }

        $ideas = $this->carMarketingIdeaRepository
            ->getIdeasForCar($car->company_id, $car->id)
            ->filter(function ($idea) {
                if (!$idea) {
                    return false;
                }

                if (isset($idea->status) && $idea->status === 'failed') {
                    return false;
                }

                return !empty($idea->title)
                    || !empty($idea->caption)
                    || !empty($idea->cta)
                    || !empty($idea->hooks)
                    || !empty($idea->formats);
            })
            ->values();

        if ($ideas->isEmpty()) {
            return null;
        }

        $preferredTypes = match ($action) {
            'scale_ads' => ['sale', 'engagement', 'authority'],
            'test_campaign' => ['sale', 'engagement', 'authority'],
            'test_campaign_seed' => ['sale', 'engagement', 'authority'],
            'review_campaign' => ['engagement', 'sale', 'authority'],
            default => [],
        };

        foreach ($preferredTypes as $contentType) {
            $idea = $ideas->firstWhere('content_type', $contentType);
            if ($idea) {
                return $this->mapCreative($idea, $action);
            }
        }

        return null;
    }

    protected function mapCreative($idea, string $action): array
    {
        $firstHook = collect($idea->hooks ?? [])
            ->first(fn($hook) => is_string($hook) && trim($hook) !== '');

        $firstFormat = collect($idea->formats ?? [])
            ->first(fn($format) => is_string($format) && trim($format) !== '');

        $reason = match ($action) {
            'scale_ads' => 'Criativo alinhado com a acao scale_ads e orientado para conversao.',
            'test_campaign' => 'Criativo alinhado com a acao test_campaign e pensado para gerar conversao inicial.',
            'test_campaign_seed' => 'Criativo alinhado com a acao test_campaign_seed e pensado para gerar procura inicial e aprendizagem.',
            'review_campaign' => 'Criativo alinhado com a acao review_campaign e orientado para recuperar atencao e interacao.',
            default => 'Criativo alinhado com a decisao de investimento atual.',
        };

        return [
            'source_idea_id' => $idea->id,
            'content_type' => $idea->content_type,
            'title' => $idea->title,
            'hook' => $firstHook,
            'primary_texts' => collect($idea->primary_texts ?? [])
                ->filter(fn($item) => is_string($item) && trim($item) !== '')
                ->values()
                ->take(3)
                ->all(),
            'headlines' => collect($idea->headlines ?? [])
                ->filter(fn($item) => is_string($item) && trim($item) !== '')
                ->values()
                ->take(3)
                ->all(),
            'descriptions' => collect($idea->descriptions ?? [])
                ->filter(fn($item) => is_string($item) && trim($item) !== '')
                ->values()
                ->take(2)
                ->all(),
            'caption' => $idea->caption,
            'cta' => $idea->cta,
            'format' => $firstFormat,
            'angle' => $idea->angle,
            'goal' => $idea->goal,
            'why_now' => $idea->why_now,
            'reason' => $reason,
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
