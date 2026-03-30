<?php

namespace App\Services;

use App\Models\Car;

class CarIssueEngine
{
    public function __construct(
        protected CarMarketIntelligenceService $marketIntelligenceService
    ) {}

    public function getImmediateActions(int $companyId, int $limit = 5): array
    {
        $cars = Car::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_resume', 0)
            ->select([
                'id',
                'car_brand_id',
                'car_model_id',
                'version',
                'price_gross',
                'promo_price_gross',
                'description_website_pt',
                'created_at',
                'registration_year',
                'fuel_type',
                'transmission',
                'power_hp',
            ])
            ->with([
                'brand:id,name',
                'model:id,name',
            ])
            ->withCount([
                'views',
                'leads',
                'interactions',
                'images',
                'externalImages',
            ])
            ->get()
            ->map(function (Car $car) {
                $car->days_in_stock = (int) $car->created_at->diffInDays(now());
                return $car;
            });

        if ($cars->isEmpty()) {
            return [];
        }

        $context = [
            'avg_views' => (float) $cars->avg('views_count'),
            'avg_interactions' => (float) $cars->avg('interactions_count'),
            'avg_leads' => (float) $cars->avg('leads_count'),
        ];

        return $cars
            ->map(fn(Car $car) => $this->analyzeCar($car, $context))
            ->filter()
            ->sortByDesc('priority_score')
            ->take($limit)
            ->values()
            ->all();
    }

    private function analyzeCar(Car $car, array $context): ?array
    {
        $market = $this->marketIntelligenceService->analyze($car);
        $issues = collect([
            $this->priceAboveMarketIssue($car, $market),
            $this->deadStockIssue($car, $market),
            $this->lowDemandIssue($car, $context),
            $this->poorListingIssue($car, $context),
        ])->filter();

        if ($issues->isEmpty()) {
            return null;
        }

        $topIssue = $issues->sortByDesc('priority_score')->first();

        return [
            'id' => $car->id,
            'title' => trim(sprintf(
                '%s %s %s',
                $car->brand?->name ?? '',
                $car->model?->name ?? '',
                $car->version ?? ''
            )),
            'price' => $this->resolveEffectivePrice($car),
            'signals' => [
                'days_in_stock' => (int) ($car->days_in_stock ?? 0),
                'views' => (int) ($car->views_count ?? 0),
                'leads' => (int) ($car->leads_count ?? 0),
                'interactions' => (int) ($car->interactions_count ?? 0),
            ],
            'issue_type' => $topIssue['issue_type'],
            'problem' => $topIssue['problem'],
            'diagnosis' => $topIssue['diagnosis'],
            'action' => [
                'label' => $topIssue['action_label'],
                'suggestion' => $topIssue['action_suggestion'],
            ],
            'priority_score' => $topIssue['priority_score'],
        ];
    }

    private function priceAboveMarketIssue(Car $car, array $market): ?array
    {
        $delta = $market['car_price_vs_median_pct'] ?? null;

        if (($market['market_position'] ?? null) !== 'above_market' || $delta === null || $delta <= 5) {
            return null;
        }

        $recommendedPrice = $market['recommended_price'] ?? $this->resolveEffectivePrice($car);
        $priority = 68 + min(22, (int) round(max(0, $delta - 5) * 1.8));

        if ((int) ($car->days_in_stock ?? 0) >= 45) {
            $priority += 8;
        }

        if ((int) ($car->leads_count ?? 0) === 0) {
            $priority += 6;
        }

        return [
            'issue_type' => 'price_above_market',
            'problem' => 'Preco acima do mercado',
            'diagnosis' => sprintf(
                'Esta %.1f%% acima da mediana e isso pode estar a travar contacto.',
                $delta
            ),
            'action_label' => 'Rever preco',
            'action_suggestion' => $recommendedPrice
                ? 'Apontar para cerca de ' . $this->formatMoney($recommendedPrice)
                : 'Ajustar preco para recuperar competitividade',
            'priority_score' => min(100, $priority),
        ];
    }

    private function deadStockIssue(Car $car, array $market): ?array
    {
        $days = (int) ($car->days_in_stock ?? 0);
        $views = (int) ($car->views_count ?? 0);
        $leads = (int) ($car->leads_count ?? 0);

        if ($days < 60 || $leads > 1) {
            return null;
        }

        $priority = 72 + min(18, (int) floor(($days - 60) / 10) * 3);

        if (($market['market_position'] ?? null) === 'above_market') {
            $priority += 8;
        }

        if ($views < 80) {
            $priority += 4;
        }

        return [
            'issue_type' => 'dead_stock',
            'problem' => 'Stock parado',
            'diagnosis' => sprintf(
                'Tem %d dias em stock e continua sem tracao comercial suficiente.',
                $days
            ),
            'action_label' => 'Desbloquear rotacao',
            'action_suggestion' => ($market['market_position'] ?? null) === 'above_market'
                ? 'Rever preco e relancar o anuncio'
                : 'Criar novo destaque e refresh ao anuncio',
            'priority_score' => min(100, $priority),
        ];
    }

    private function lowDemandIssue(Car $car, array $context): ?array
    {
        $days = (int) ($car->days_in_stock ?? 0);
        $views = (int) ($car->views_count ?? 0);
        $avgViews = max(1.0, (float) ($context['avg_views'] ?? 1));

        if ($days < 14 || $views >= max(40, ($avgViews * 0.55))) {
            return null;
        }

        $priority = 48 + min(18, max(0, (int) round(($avgViews - $views) / 6)));

        if ($days > 30) {
            $priority += 8;
        }

        return [
            'issue_type' => 'low_demand',
            'problem' => 'Procura baixa',
            'diagnosis' => 'Esta a gerar pouca procura face ao resto do stock ativo.',
            'action_label' => 'Ganhar visibilidade',
            'action_suggestion' => 'Melhorar destaque e reforcar distribuicao do anuncio',
            'priority_score' => min(100, $priority),
        ];
    }

    private function poorListingIssue(Car $car, array $context): ?array
    {
        $views = (int) ($car->views_count ?? 0);
        $interactions = (int) ($car->interactions_count ?? 0);
        $imageCount = (int) (($car->images_count ?? 0) + ($car->external_images_count ?? 0));
        $descriptionLength = mb_strlen(trim((string) ($car->description_website_pt ?? '')));
        $avgInteractions = max(1.0, (float) ($context['avg_interactions'] ?? 1));

        if ($views < 40) {
            return null;
        }

        $weakListing = $imageCount < 6 || $descriptionLength < 180 || $interactions < max(1, (int) floor($avgInteractions * 0.45));

        if (! $weakListing) {
            return null;
        }

        $issues = [];

        if ($imageCount < 6) {
            $issues[] = 'poucas fotos';
        }

        if ($descriptionLength < 180) {
            $issues[] = 'descricao fraca';
        }

        if ($interactions < max(1, (int) floor($avgInteractions * 0.45))) {
            $issues[] = 'pouco envolvimento';
        }

        return [
            'issue_type' => 'poor_listing',
            'problem' => 'Anuncio pouco convincente',
            'diagnosis' => ucfirst(implode(', ', array_slice($issues, 0, 2))) . '.',
            'action_label' => 'Melhorar anuncio',
            'action_suggestion' => 'Atualizar fotos, titulo e descricao para aumentar resposta',
            'priority_score' => min(100, 44 + (count($issues) * 8)),
        ];
    }

    private function resolveEffectivePrice(Car $car): ?float
    {
        if (
            $car->promo_price_gross !== null
            && (float) $car->promo_price_gross > 0
            && $car->price_gross !== null
            && (float) $car->promo_price_gross < (float) $car->price_gross
        ) {
            return round((float) $car->promo_price_gross, 2);
        }

        return $car->price_gross !== null ? round((float) $car->price_gross, 2) : null;
    }

    private function formatMoney(?float $value): string
    {
        return number_format((float) $value, 0, ',', '.') . ' EUR';
    }
}
