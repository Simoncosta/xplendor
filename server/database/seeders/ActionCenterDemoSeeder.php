<?php

namespace Database\Seeders;

use App\Models\CampaignCarMetricDaily;
use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarInteraction;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\CarView;
use App\Models\Company;
use App\Services\CarDecisionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ActionCenterDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrFail();
        $brandModelPairs = $this->resolveBrandModelPairs();
        $category = CarCategory::query()->first();

        $existingCars = Car::query()
            ->where('company_id', $company->id)
            ->whereIn('version', ['Action Scale', 'Action Keep', 'Action Fix', 'Action Stop'])
            ->pluck('id');

        if ($existingCars->isNotEmpty()) {
            CampaignCarMetricDaily::query()->whereIn('car_id', $existingCars)->delete();
            CarAdCampaign::query()->whereIn('car_id', $existingCars)->delete();
            CarLead::query()->whereIn('car_id', $existingCars)->delete();
            CarInteraction::query()->whereIn('car_id', $existingCars)->delete();
            CarView::query()->whereIn('car_id', $existingCars)->delete();
            Car::query()->whereIn('id', $existingCars)->delete();
        }

        $scenarios = [
            [
                'decision' => 'ESCALAR',
                'version' => 'Action Scale',
                'label' => 'BMW Serie 3 2018',
                'registration_year' => 2018,
                'campaign' => [
                    [2200, 46, 24.50],
                    [2400, 50, 25.50],
                    [2100, 44, 23.00],
                    [2500, 55, 27.20],
                    [2300, 49, 24.80],
                    [2600, 56, 28.00],
                    [2450, 53, 26.40],
                ],
                'views' => [7, 7, 6, 6, 6, 7, 6],
                'durations' => [78, 75, 80, 72, 76, 81, 79],
                'scroll' => [72, 74, 75, 70, 73, 77, 74],
                'formOpens' => [1, 1, 1, 1, 1, 1, 1],
                'whatsapp' => [1, 1, 0, 1, 1, 1, 0],
                'leads' => [1, 0, 0, 1, 0, 1, 0],
            ],
            [
                'decision' => 'MANTER',
                'version' => 'Action Keep',
                'label' => 'Peugeot 3008 2020',
                'registration_year' => 2020,
                'campaign' => [
                    [620, 3, 2.80],
                    [680, 4, 3.20],
                    [590, 3, 2.90],
                    [710, 4, 3.10],
                    [640, 3, 3.00],
                    [720, 4, 3.30],
                    [660, 3, 3.00],
                ],
                'views' => [2, 3, 2, 3, 2, 2, 3],
                'durations' => [46, 42, 48, 45, 44, 47, 46],
                'scroll' => [52, 50, 54, 53, 51, 55, 52],
                'formOpens' => [0, 1, 0, 0, 0, 0, 1],
                'whatsapp' => [0, 0, 0, 0, 0, 0, 0],
                'leads' => [0, 0, 0, 0, 0, 0, 0],
            ],
            [
                'decision' => 'CORRIGIR',
                'version' => 'Action Fix',
                'label' => 'Audi A4 Avant 2019',
                'registration_year' => 2019,
                'campaign' => [
                    [1850, 25, 22.10],
                    [1900, 24, 21.80],
                    [1750, 23, 20.90],
                    [2000, 28, 23.40],
                    [1880, 26, 22.30],
                    [1930, 27, 22.70],
                    [1810, 25, 21.90],
                ],
                'views' => [6, 7, 6, 7, 6, 7, 6],
                'durations' => [68, 66, 70, 67, 69, 71, 68],
                'scroll' => [69, 70, 71, 68, 72, 73, 70],
                'formOpens' => [0, 0, 0, 0, 0, 0, 0],
                'whatsapp' => [0, 0, 0, 0, 0, 0, 0],
                'leads' => [0, 0, 0, 0, 0, 0, 0],
            ],
            [
                'decision' => 'PARAR',
                'version' => 'Action Stop',
                'label' => 'Ford Focus 2017',
                'registration_year' => 2017,
                'campaign' => [
                    [95, 0, 7.90],
                    [110, 1, 8.10],
                    [100, 0, 8.00],
                    [120, 1, 8.20],
                    [90, 0, 7.80],
                    [105, 0, 8.00],
                    [115, 1, 8.30],
                ],
                'views' => [0, 0, 1, 0, 0, 0, 0],
                'durations' => [18, 18, 22, 16, 17, 19, 18],
                'scroll' => [20, 18, 24, 16, 18, 20, 19],
                'formOpens' => [0, 0, 0, 0, 0, 0, 0],
                'whatsapp' => [0, 0, 0, 0, 0, 0, 0],
                'leads' => [0, 0, 0, 0, 0, 0, 0],
            ],
        ];

        $decisionService = app(CarDecisionService::class);
        $summaries = [];

        foreach ($scenarios as $index => $scenario) {
            $pair = $brandModelPairs[$index % $brandModelPairs->count()];
            $car = Car::factory()->create([
                'company_id' => $company->id,
                'car_brand_id' => $pair['brand']->id,
                'car_model_id' => $pair['model']->id,
                'car_category_id' => $category?->id,
                'version' => $scenario['version'],
                'registration_year' => $scenario['registration_year'],
                'price_gross' => match ($scenario['decision']) {
                    'ESCALAR' => 32900,
                    'MANTER' => 27900,
                    'CORRIGIR' => 31400,
                    default => 14900,
                },
                'segment' => $index === 1 ? 'SUV' : ($index === 3 ? 'Compacto' : 'Sedan'),
                'created_at' => now()->subDays(45 + ($index * 12)),
                'updated_at' => now(),
            ]);

            $mapping = CarAdCampaign::factory()->create([
                'company_id' => $company->id,
                'car_id' => $car->id,
                'campaign_name' => 'Action Center '.$scenario['decision'],
                'adset_name' => 'Scenario '.$scenario['decision'],
            ]);

            foreach ($scenario['campaign'] as $offset => [$impressions, $clicks, $spend]) {
                $date = now()->subDays(6 - $offset)->toDateString();

                CampaignCarMetricDaily::factory()->create([
                    'company_id' => $company->id,
                    'car_id' => $car->id,
                    'mapping_id' => $mapping->id,
                    'campaign_id' => $mapping->campaign_id,
                    'adset_id' => $mapping->adset_id,
                    'date' => $date,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'spend_normalized' => $spend,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                    'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
                    'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
                ]);

                $this->seedFunnelDay(
                    $company->id,
                    $car->id,
                    $date,
                    $scenario['views'][$offset],
                    $scenario['durations'][$offset],
                    $scenario['scroll'][$offset],
                    $scenario['formOpens'][$offset],
                    $scenario['whatsapp'][$offset],
                    $scenario['leads'][$offset]
                );
            }

            $decision = $decisionService->resolve($car);

            $summaries[] = [
                'car' => $scenario['label'],
                'expected' => $scenario['decision'],
                'generated' => $decision['decision'],
                'confidence' => $decision['confidence'],
                'reason' => $decision['reason'],
            ];
        }

        $this->command?->table(
            ['Carro', 'Esperado', 'Gerado', 'Confiança', 'Motivo'],
            $summaries
        );
    }

    private function resolveBrandModelPairs(): Collection
    {
        $preferredBrands = ['BMW', 'Peugeot', 'Audi', 'Ford'];

        $pairs = collect($preferredBrands)
            ->map(function (string $brandName) {
                $brand = CarBrand::query()->where('name', $brandName)->first();

                if (!$brand) {
                    return null;
                }

                $model = CarModel::query()->where('car_brand_id', $brand->id)->first();

                if (!$model) {
                    return null;
                }

                return compact('brand', 'model');
            })
            ->filter()
            ->values();

        if ($pairs->isNotEmpty()) {
            return $pairs;
        }

        return CarBrand::query()
            ->with('models')
            ->where('vehicle_type', 'car')
            ->get()
            ->map(function (CarBrand $brand) {
                $model = $brand->models->first();

                return $model ? ['brand' => $brand, 'model' => $model] : null;
            })
            ->filter()
            ->values();
    }

    private function seedFunnelDay(
        int $companyId,
        int $carId,
        string $date,
        int $views,
        int $duration,
        int $scroll,
        int $formOpens,
        int $whatsapp,
        int $leads
    ): void {
        $baseDate = now()->parse($date)->setTime(10, 0);

        for ($i = 0; $i < $views; $i++) {
            $sessionId = 'sess-'.$carId.'-'.$date.'-view-'.$i;

            CarView::factory()->create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'session_id' => $sessionId,
                'visitor_id' => 'visitor-'.$carId.'-'.$date.'-view-'.$i,
                'view_duration_seconds' => $duration,
                'created_at' => $baseDate->copy()->addMinutes($i),
                'updated_at' => $baseDate->copy()->addMinutes($i),
            ]);

            CarInteraction::factory()->create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'interaction_type' => 'scroll',
                'session_id' => $sessionId,
                'visitor_id' => 'visitor-'.$carId.'-'.$date.'-view-'.$i,
                'meta' => ['scroll_pct' => $scroll],
                'created_at' => $baseDate->copy()->addMinutes($i)->addSeconds(30),
                'updated_at' => $baseDate->copy()->addMinutes($i)->addSeconds(30),
            ]);
        }

        for ($i = 0; $i < $formOpens; $i++) {
            CarInteraction::factory()->create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'interaction_type' => 'form_open',
                'session_id' => 'sess-'.$carId.'-'.$date.'-form-'.$i,
                'visitor_id' => 'visitor-'.$carId.'-'.$date.'-form-'.$i,
                'meta' => null,
                'created_at' => $baseDate->copy()->addHours(3)->addMinutes($i),
                'updated_at' => $baseDate->copy()->addHours(3)->addMinutes($i),
            ]);
        }

        for ($i = 0; $i < $whatsapp; $i++) {
            CarInteraction::factory()->create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'interaction_type' => 'whatsapp_click',
                'session_id' => 'sess-'.$carId.'-'.$date.'-wa-'.$i,
                'visitor_id' => 'visitor-'.$carId.'-'.$date.'-wa-'.$i,
                'meta' => null,
                'created_at' => $baseDate->copy()->addHours(4)->addMinutes($i),
                'updated_at' => $baseDate->copy()->addHours(4)->addMinutes($i),
            ]);
        }

        for ($i = 0; $i < $leads; $i++) {
            CarLead::factory()->create([
                'company_id' => $companyId,
                'car_id' => $carId,
                'session_id' => 'sess-'.$carId.'-'.$date.'-lead-'.$i,
                'visitor_id' => 'visitor-'.$carId.'-'.$date.'-lead-'.$i,
                'created_at' => $baseDate->copy()->addHours(5)->addMinutes($i),
                'updated_at' => $baseDate->copy()->addHours(5)->addMinutes($i),
            ]);
        }
    }
}
