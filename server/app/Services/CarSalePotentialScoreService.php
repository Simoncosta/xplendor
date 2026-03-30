<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarSalePotentialScore;
use App\Repositories\Contracts\CarSalePotentialScoreRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CarSalePotentialScoreService
{
    public function __construct(
        private CarSalePotentialScoreRepositoryInterface $repository,
        private CarMarketIntelligenceService $carMarketIntelligenceService,
    ) {}

    // ── Entrada pública ───────────────────────────────────────────────────────

    public function calculate(int $carId, int $companyId, string $triggeredBy): CarSalePotentialScore
    {
        $car = Car::where('id', $carId)
            ->where('company_id', $companyId)
            ->firstOrFail();
        $pricing = $this->resolvePricingContext($car);
        $marketIntelligence = $this->carMarketIntelligenceService->analyze($car);
        $priceVsMarket = $this->getPriceVsMarketPercent($marketIntelligence);

        $breakdown = [
            'price_vs_market' => $this->scorePriceVsMarket($marketIntelligence),
            'promo_effect'    => $this->scorePromoEffect($car),
            'engagement_rate' => $this->scoreEngagementRate($car),
            'days_in_stock'   => $this->scoreDaysInStock($car),
            'segment_demand'  => $this->scoreSegmentDemand($car),
            'listing_quality' => $this->scoreListingQuality($car),
            'model_history'   => $this->scoreModelHistory($car),
            'pricing'         => [
                'effective_price_gross' => $pricing['effective_price_gross'],
                'has_promo_price' => $pricing['has_promo_price'],
                'promo_discount_value' => $pricing['promo_discount_value'],
                'promo_discount_pct' => $pricing['promo_discount_pct'],
                'market_position' => $marketIntelligence['market_position'] ?? 'insufficient_data',
                'pricing_signal' => $marketIntelligence['pricing_signal'] ?? 'neutral',
                'competitors_count' => $marketIntelligence['competitors_count'] ?? 0,
                'market_median_price' => $marketIntelligence['market_median_price'] ?? null,
                'market_p25_price' => $marketIntelligence['market_p25_price'] ?? null,
                'market_p75_price' => $marketIntelligence['market_p75_price'] ?? null,
                'recommended_price' => $marketIntelligence['recommended_price'] ?? $pricing['effective_price_gross'],
                'car_price_vs_median_pct' => $priceVsMarket,
            ],
        ];

        $score          = (int) collect($breakdown)
            ->filter(fn ($value) => is_numeric($value))
            ->sum();
        $score          = max(0, min(100, $score)); // garantir 0–100
        $daysInStock    = (int) Carbon::parse($car->created_at)->diffInDays(now());

        Log::info('[IPS] Carro calculado', [
            'car_id'      => $carId,
            'score'       => $score,
            'breakdown'   => $breakdown,
            'triggered_by' => $triggeredBy,
        ]);

        return $this->repository->create([
            'car_id'                => $carId,
            'company_id'            => $companyId,
            'score'                 => $score,
            'classification'        => CarSalePotentialScore::classify($score),
            'score_breakdown'       => $breakdown,
            'price_vs_market'       => $priceVsMarket,
            'days_in_stock_at_calc' => $daysInStock,
            'calculated_at'         => now(),
            'triggered_by'          => $triggeredBy,
        ]);
    }

    public function getLatestWithHistory(int $carId, int $companyId): array
    {
        $latest  = $this->repository->getLatest($carId, $companyId);
        $history = $this->repository->getHistory($carId, $companyId, 90);

        return [
            'score'          => $latest?->score,
            'classification' => $latest?->classification,
            'calculated_at'  => $latest?->calculated_at,
            'price_vs_market' => $latest?->price_vs_market,
            'effective_price_gross' => $latest?->score_breakdown['pricing']['effective_price_gross'] ?? null,
            'has_promo_price' => $latest?->score_breakdown['pricing']['has_promo_price'] ?? false,
            'promo_discount_value' => $latest?->score_breakdown['pricing']['promo_discount_value'] ?? null,
            'promo_discount_pct' => $latest?->score_breakdown['pricing']['promo_discount_pct'] ?? null,
            'breakdown'      => $latest?->score_breakdown,
            'history'        => $history->map(fn($h) => [
                'score' => $h->score,
                'classification' => $h->classification,
                'date'  => $h->calculated_at,
                'triggered_by' => $h->triggered_by,
            ]),
        ];
    }

    // ── Fator 1: Preço vs mercado (25 pts) ───────────────────────────────────
    // Usa inteligência de mercado externa para ajustar o potencial comercial
    // da viatura sem dominar o score total.

    private function scorePriceVsMarket(array $marketIntelligence): int
    {
        $marketPosition = $marketIntelligence['market_position'] ?? 'insufficient_data';
        $deviation = $this->getPriceVsMarketPercent($marketIntelligence);

        if ($marketPosition === 'insufficient_data' || $deviation === null) {
            return 15; // sem dados de mercado → valor neutro
        }

        if ($marketPosition === 'below_market') {
            if ($deviation <= -10) return 25;
            if ($deviation <= -5)  return 22;
            return 18;
        }

        if ($marketPosition === 'aligned_market') {
            if ($deviation < 0) return 17;
            return 15;
        }

        if ($marketPosition === 'above_market') {
            if ($deviation > 10) return 4;
            if ($deviation > 5)  return 7;
            return 10;
        }

        return 15;
    }

    private function getPriceVsMarketPercent(array $marketIntelligence): ?float
    {
        $deviation = $marketIntelligence['car_price_vs_median_pct'] ?? null;

        return $deviation === null ? null : round((float) $deviation, 2);
    }

    // ── Fator 2: Velocidade de engajamento (20 pts) ───────────────────────────
    // Combina taxa de leads/views COM sinais de intenção (interações)
    // Interações de contacto (WhatsApp, telefone) pesam mais que views passivas

    private function scoreEngagementRate(Car $car): int
    {
        $views = DB::table('car_views')
            ->where('car_id', $car->id)
            ->count();

        $leads = DB::table('car_leads')
            ->where('car_id', $car->id)
            ->count();

        // Interações de intenção alta (WhatsApp + chamadas + mostrar telefone)
        $intentInteractions = DB::table('car_interactions')
            ->where('car_id', $car->id)
            ->whereIn('interaction_type', [
                'whatsapp_click',
                'call_click',
                'show_phone',
                'copy_phone',
                'form_start',
            ])
            ->count();

        // Interações de intenção média (favorito, partilha, form_open)
        $softInteractions = DB::table('car_interactions')
            ->where('car_id', $car->id)
            ->whereIn('interaction_type', [
                'favorite',
                'share',
                'form_open',
                'location_view',
            ])
            ->count();

        if ($views === 0) return 0;

        // Score combinado: lead vale 3x, intent interaction vale 1.5x, soft vale 0.5x
        // Normalizado para criar uma "taxa de engajamento ponderada"
        $weightedEngagement = ($leads * 3) + ($intentInteractions * 1.5) + ($softInteractions * 0.5);
        $engagementRate     = $weightedEngagement / $views;

        // Benchmark do stand com o mesmo método ponderado
        $benchmark = $this->getStandEngagementBenchmark($car->company_id);

        if ($benchmark === 0.0) {
            // Sem benchmark ainda — avaliar em absoluto
            if ($engagementRate >= 0.05)  return 20; // 5%+ de engajamento ponderado
            if ($engagementRate >= 0.02)  return 14;
            if ($engagementRate > 0)      return 7;
            return 0;
        }

        $ratio = $engagementRate / $benchmark;

        if ($ratio >= 1.5) return 20;
        if ($ratio >= 1.0) return 14;
        if ($ratio >= 0.5) return 7;
        return 0;
    }

    private function getStandEngagementBenchmark(int $companyId): float
    {
        $cars = DB::table('cars')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->pluck('id');

        if ($cars->isEmpty()) return 0.0;

        $rates = $cars->map(function ($carId) {
            $views  = DB::table('car_views')->where('car_id', $carId)->count();
            $leads  = DB::table('car_leads')->where('car_id', $carId)->count();
            $intent = DB::table('car_interactions')
                ->where('car_id', $carId)
                ->whereIn('interaction_type', ['whatsapp_click', 'call_click', 'show_phone', 'copy_phone', 'form_start'])
                ->count();
            $soft = DB::table('car_interactions')
                ->where('car_id', $carId)
                ->whereIn('interaction_type', ['favorite', 'share', 'form_open', 'location_view'])
                ->count();

            if ($views === 0) return 0;

            return (($leads * 3) + ($intent * 1.5) + ($soft * 0.5)) / $views;
        });

        return $rates->avg() ?? 0.0;
    }

    // ── Fator 3: Dias em stock (20 pts) ──────────────────────────────────────

    private function scoreDaysInStock(Car $car): int
    {
        $days = (int) Carbon::parse($car->created_at)->diffInDays(now());

        if ($days <= 15) return 20;
        if ($days <= 30) return 12;
        if ($days <= 60) return 5;
        return 0;
    }

    // ── Fator 4: Procura do segmento (15 pts) ────────────────────────────────
    // Quantas viaturas do mesmo segmento/combustível foram vendidas nos últimos 90 dias

    private function scoreSegmentDemand(Car $car): int
    {
        $soldCount = DB::table('cars')
            ->where('company_id', $car->company_id)
            ->where('segment', $car->segment)
            ->where('fuel_type', $car->fuel_type)
            ->where('status', 'sold')
            ->where('updated_at', '>=', now()->subDays(90))
            ->count();

        if ($soldCount >= 3) return 15; // alta rotação
        if ($soldCount >= 1) return 8;  // média
        return 3;                        // baixa (mas não zero — pode ser nicho)
    }

    // ── Fator 5: Qualidade do anúncio (10 pts) ───────────────────────────────

    private function scoreListingQuality(Car $car): int
    {
        $pts = 0;
        $pricing = $this->resolvePricingContext($car);

        // Fotos: ≥8 → 4pts, ≥4 → 2pts
        $photoCount = DB::table('car_images')
            ->where('car_id', $car->id)
            ->count();

        if ($photoCount >= 8)      $pts += 4;
        elseif ($photoCount >= 4)  $pts += 2;

        // Descrição preenchida
        if (! empty($car->description_website_pt)) $pts += 2;

        // Preço visível
        if (! $car->hide_price_online && ($pricing['effective_price_gross'] ?? 0) > 0) $pts += 2;

        // Extras preenchidos (pelo menos um grupo com items)
        $hasExtras = collect($car->extras ?? [])
            ->contains(fn($group) => ! empty($group['items']));

        if ($hasExtras) $pts += 2;

        return min(10, $pts);
    }

    private function scorePromoEffect(Car $car): int
    {
        $pricing = $this->resolvePricingContext($car);

        if (! $pricing['has_promo_price']) {
            return 0;
        }

        $discountPct = $pricing['promo_discount_pct'] ?? 0.0;
        if ($discountPct < 3) {
            return 0;
        }

        $daysInStock = (int) Carbon::parse($car->created_at)->diffInDays(now());
        $views = DB::table('car_views')->where('car_id', $car->id)->count();
        $leads = DB::table('car_leads')->where('car_id', $car->id)->count();
        $interactions = DB::table('car_interactions')->where('car_id', $car->id)->count();

        $baseBoost = match (true) {
            $discountPct >= 10 => 8,
            $discountPct >= 5 => 5,
            $discountPct >= 3 => 3,
            default => 0,
        };

        $engagementSignal = $views >= 120 || $interactions >= 10 || $leads >= 2;
        if ($engagementSignal) {
            $baseBoost += 2;
        }

        $promoNotWorking = $daysInStock >= 21 && $leads === 0 && $interactions < 5;
        if ($promoNotWorking) {
            $baseBoost -= match (true) {
                $discountPct >= 10 => 5,
                $discountPct >= 5 => 3,
                default => 2,
            };
        }

        return max(0, min(10, $baseBoost));
    }

    // ── Fator 6: Histórico de conversão do modelo (10 pts) ───────────────────

    private function scoreModelHistory(Car $car): int
    {
        // Tempo médio (dias) de venda de carros do mesmo modelo no stand
        $avgDays = DB::table('cars')
            ->where('company_id', $car->company_id)
            ->where('car_model_id', $car->car_model_id)
            ->where('status', 'sold')
            ->whereNotNull('updated_at')
            ->selectRaw('AVG(DATEDIFF(updated_at, created_at)) as avg_days')
            ->value('avg_days');

        if ($avgDays === null) return 5; // sem histórico → neutro

        $daysInStock = (int) Carbon::parse($car->created_at)->diffInDays(now());

        // Se está a vender mais rápido que a média histórica → bom sinal
        if ($daysInStock < $avgDays * 0.7) return 10;
        if ($daysInStock <= $avgDays)      return 5;
        return 2; // já passou a média histórica
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolvePricingContext(Car $car): array
    {
        $effectivePrice = $this->resolveEffectivePrice($car->price_gross, $car->promo_price_gross);
        $hasPromoPrice = $car->promo_price_gross !== null
            && (float) $car->promo_price_gross > 0
            && $car->price_gross !== null
            && (float) $car->promo_price_gross < (float) $car->price_gross;

        $promoDiscountValue = $hasPromoPrice
            ? round((float) $car->price_gross - (float) $car->promo_price_gross, 2)
            : 0.0;

        $promoDiscountPct = $hasPromoPrice && (float) $car->price_gross > 0
            ? round(($promoDiscountValue / (float) $car->price_gross) * 100, 2)
            : 0.0;

        return [
            'effective_price_gross' => $effectivePrice,
            'has_promo_price' => $hasPromoPrice,
            'promo_discount_value' => $promoDiscountValue,
            'promo_discount_pct' => $promoDiscountPct,
        ];
    }

    private function resolveEffectivePrice(mixed $priceGross, mixed $promoPriceGross): ?float
    {
        if ($priceGross === null) {
            return null;
        }

        $basePrice = (float) $priceGross;
        $promoPrice = $promoPriceGross !== null ? (float) $promoPriceGross : null;

        if ($promoPrice !== null && $promoPrice > 0 && $promoPrice < $basePrice) {
            return $promoPrice;
        }

        return $basePrice > 0 ? $basePrice : null;
    }
}
