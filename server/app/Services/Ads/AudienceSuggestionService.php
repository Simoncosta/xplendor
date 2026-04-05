<?php

namespace App\Services\Ads;

use App\Models\Car;
use App\Services\Meta\MetaTargetingService;

class AudienceSuggestionService
{
    public function __construct(
        protected MetaTargetingService $metaTargetingService,
        protected InterestFilteringService $interestFilteringService,
    ) {}

    public function suggestForCar(Car $car): array
    {
        $car->loadMissing(['brand', 'model']);

        $resolved = $this->metaTargetingService
            ->forCompany($car->company_id)
            ->bulkResolve($this->buildBaseSuggestions($car));

        return $this->interestFilteringService->filterAndRank($resolved, $car);
    }

    protected function buildBaseSuggestions(Car $car): array
    {
        $brand = trim((string) ($car->brand?->name ?? ''));
        $model = trim((string) ($car->model?->name ?? ''));
        $fuel = $this->mapFuelSuggestion((string) ($car->fuel_type ?? ''));
        $segment = $this->mapSegmentSuggestion((string) ($car->segment ?? ''));

        return collect([
            $brand ?: null,
            ($brand && $model) ? "{$brand} {$model}" : null,
            $fuel,
            $segment,
        ])
            ->filter(fn($item) => is_string($item) && trim($item) !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function mapFuelSuggestion(string $fuelType): ?string
    {
        return match (mb_strtolower(trim($fuelType))) {
            'electric', 'eléctrico', 'eletrico' => 'Carros elétricos',
            'hybrid', 'híbrido', 'hibrido', 'plug-in hybrid', 'phev' => 'Carros híbridos',
            'diesel' => 'Carros diesel',
            'gasoline', 'petrol', 'gasolina' => 'Carros a gasolina',
            default => null,
        };
    }

    protected function mapSegmentSuggestion(string $segment): ?string
    {
        return match (mb_strtolower(trim($segment))) {
            'suv', 'suv_tt' => 'SUV',
            'station_wagon', 'family' => 'Carros familiares',
            'city_car', 'city', 'urban' => 'Mobilidade urbana',
            'executive', 'sedan' => 'Carros executivos',
            default => null,
        };
    }
}
