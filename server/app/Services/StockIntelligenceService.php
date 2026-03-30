<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;

class StockIntelligenceService
{
    public function __construct(
        protected CarMarketSnapshotRepositoryInterface $repository
    ) {}

    public function getOpportunityForSegment(array $filters): array
    {
        $stats = $this->repository->getSegmentSnapshotStats($filters);
        $internalDemand = $this->getInternalDemandSignal($filters);

        $missingSignals = $this->buildMissingSignals($stats, $filters);
        $missingAttributes = $this->buildMissingAttributes($stats, $filters);
        $scarcityScore = $this->calculateScarcityScore($stats, $missingSignals);
        $opportunityScore = $this->calculateOpportunityScore($stats, $internalDemand, $scarcityScore);

        return [
            'segment' => $this->buildSegmentLabel($filters),
            'total_listings' => (int) ($stats['total_listings'] ?? 0),
            'avg_price' => $stats['avg_price'] ?? null,
            'color_distribution' => $stats['color_distribution'] ?? [],
            'fuel_distribution' => $stats['fuel_distribution'] ?? [],
            'gearbox_distribution' => $stats['gearbox_distribution'] ?? [],
            'missing_signals' => $missingSignals,
            'missing_attributes' => $missingAttributes,
            'scarcity_score' => $scarcityScore,
            'opportunity_score' => $opportunityScore,
            'internal_demand_signal' => $internalDemand,
            'insight' => $this->buildInsight($stats, $opportunityScore, $missingSignals),
        ];
    }

    private function getInternalDemandSignal(array $filters): array
    {
        $companyId = isset($filters['company_id']) ? (int) $filters['company_id'] : null;

        if (!$companyId) {
            return [
                'views' => 0,
                'leads' => 0,
                'interactions' => 0,
                'score' => 10,
            ];
        }

        $query = Car::query()
            ->where('company_id', $companyId)
            ->withCount(['views', 'leads', 'interactions']);

        if (!empty($filters['brand'])) {
            $brand = trim((string) $filters['brand']);
            $query->whereHas('brand', fn($q) => $q->where('name', $brand));
        }

        if (!empty($filters['model'])) {
            $model = trim((string) $filters['model']);
            $query->whereHas('model', fn($q) => $q->where('name', $model));
        }

        if (!empty($filters['fuel'])) {
            $query->where('fuel_type', $filters['fuel']);
        }

        if (!empty($filters['gearbox'])) {
            $query->where('transmission', $filters['gearbox']);
        }

        $yearFrom = isset($filters['year_range']['from']) ? (int) $filters['year_range']['from'] : ($filters['year_from'] ?? null);
        $yearTo = isset($filters['year_range']['to']) ? (int) $filters['year_range']['to'] : ($filters['year_to'] ?? null);

        if ($yearFrom !== null) {
            $query->where('registration_year', '>=', (int) $yearFrom);
        }

        if ($yearTo !== null) {
            $query->where('registration_year', '<=', (int) $yearTo);
        }

        $cars = $query->get();

        $views = (int) $cars->sum('views_count');
        $leads = (int) $cars->sum('leads_count');
        $interactions = (int) $cars->sum('interactions_count');

        $score = 10;
        if ($views >= 150 || $interactions >= 20) {
            $score += 12;
        } elseif ($views >= 60 || $interactions >= 8) {
            $score += 7;
        }

        if ($leads >= 5) {
            $score += 12;
        } elseif ($leads >= 2) {
            $score += 7;
        }

        return [
            'views' => $views,
            'leads' => $leads,
            'interactions' => $interactions,
            'score' => max(0, min(30, $score)),
        ];
    }

    private function buildMissingSignals(array $stats, array $filters): array
    {
        $signals = [];
        $totalListings = (int) ($stats['total_listings'] ?? 0);

        if ($totalListings === 0) {
            $signals[] = 'no_inventory';
        } elseif ($totalListings <= 8) {
            $signals[] = 'low_inventory';
        } elseif ($totalListings >= 30) {
            $signals[] = 'high_inventory';
        }

        if (!empty($filters['color']) && !$this->distributionHasValue($stats['color_distribution'] ?? [], (string) $filters['color'])) {
            $signals[] = 'no_' . $this->normalizeCode((string) $filters['color']) . '_color';
        }

        return $signals;
    }

    private function buildMissingAttributes(array $stats, array $filters): array
    {
        $missing = [];

        if (!empty($filters['color']) && !$this->distributionHasValue($stats['color_distribution'] ?? [], (string) $filters['color'])) {
            $missing[] = [
                'type' => 'color',
                'value' => $filters['color'],
                'status' => 'missing',
            ];
        }

        foreach (($stats['color_distribution'] ?? []) as $item) {
            if ((int) ($item['total'] ?? 0) <= 1) {
                $missing[] = [
                    'type' => 'rare_color',
                    'value' => $item['value'] ?? null,
                    'status' => 'scarce',
                ];
            }
        }

        foreach (($stats['gearbox_distribution'] ?? []) as $item) {
            if ((int) ($item['total'] ?? 0) <= 2) {
                $missing[] = [
                    'type' => 'rare_gearbox',
                    'value' => $item['value'] ?? null,
                    'status' => 'scarce',
                ];
            }
        }

        return collect($missing)
            ->filter(fn($item) => !empty($item['value']))
            ->unique(fn($item) => $item['type'] . ':' . $item['value'])
            ->values()
            ->all();
    }

    private function calculateScarcityScore(array $stats, array $missingSignals): int
    {
        $totalListings = (int) ($stats['total_listings'] ?? 0);
        $score = match (true) {
            $totalListings === 0 => 40,
            $totalListings <= 3 => 35,
            $totalListings <= 8 => 28,
            $totalListings <= 15 => 18,
            $totalListings <= 25 => 10,
            default => 4,
        };

        if (in_array('no_inventory', $missingSignals, true)) {
            $score += 15;
        }

        if (in_array('low_inventory', $missingSignals, true)) {
            $score += 8;
        }

        return max(0, min(60, $score));
    }

    private function calculateOpportunityScore(array $stats, array $internalDemand, int $scarcityScore): int
    {
        $avgPrice = (float) ($stats['avg_price'] ?? 0);
        $priceBoost = match (true) {
            $avgPrice >= 35000 => 10,
            $avgPrice >= 20000 => 7,
            $avgPrice >= 12000 => 4,
            default => 2,
        };

        $score = $scarcityScore + (int) ($internalDemand['score'] ?? 10) + $priceBoost;

        return max(0, min(100, $score));
    }

    private function buildInsight(array $stats, int $opportunityScore, array $missingSignals): string
    {
        $totalListings = (int) ($stats['total_listings'] ?? 0);

        if (in_array('no_inventory', $missingSignals, true)) {
            return 'Sem oferta ativa neste segmento neste momento — oportunidade forte de entrada.';
        }

        if ($opportunityScore >= 75) {
            return 'Oferta baixa neste segmento e sinais internos favoráveis — oportunidade clara de stock.';
        }

        if ($totalListings >= 30) {
            return 'Oferta elevada neste segmento — zona mais saturada e menos prioritária para entrada.';
        }

        if (in_array('low_inventory', $missingSignals, true)) {
            return 'Oferta curta neste segmento — pode existir espaço para entrada seletiva.';
        }

        return 'Segmento com oferta equilibrada — acompanhar antes de aumentar exposição de stock.';
    }

    private function buildSegmentLabel(array $filters): string
    {
        $parts = array_filter([
            $filters['brand'] ?? null,
            $filters['model'] ?? null,
            $this->formatYearRange($filters),
            $filters['fuel'] ?? null,
            $filters['gearbox'] ?? null,
        ]);

        return implode(' ', array_map(fn($part) => trim((string) $part), $parts));
    }

    private function formatYearRange(array $filters): ?string
    {
        $from = isset($filters['year_range']['from']) ? (int) $filters['year_range']['from'] : ($filters['year_from'] ?? null);
        $to = isset($filters['year_range']['to']) ? (int) $filters['year_range']['to'] : ($filters['year_to'] ?? null);

        if ($from && $to) {
            return $from === $to ? (string) $from : "{$from}-{$to}";
        }

        if ($from) {
            return (string) $from;
        }

        if ($to) {
            return (string) $to;
        }

        return null;
    }

    private function distributionHasValue(array $distribution, string $value): bool
    {
        $needle = $this->normalizeCode($value);

        foreach ($distribution as $item) {
            if ($this->normalizeCode((string) ($item['value'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCode(string $value): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = $normalized !== false ? $normalized : $value;
        $normalized = strtolower(trim($normalized));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim((string) $normalized, '_');
    }
}
