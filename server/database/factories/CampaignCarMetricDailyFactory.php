<?php

namespace Database\Factories;

use App\Models\CampaignCarMetricDaily;
use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignCarMetricDaily>
 */
class CampaignCarMetricDailyFactory extends Factory
{
    protected $model = CampaignCarMetricDaily::class;

    public function definition(): array
    {
        $company = Company::query()->first();
        $car = Car::query()->when($company, fn ($query) => $query->where('company_id', $company->id))->first();
        $mapping = CarAdCampaign::query()
            ->when($car, fn ($query) => $query->where('car_id', $car->id))
            ->first();

        $impressions = $this->faker->numberBetween(200, 1800);
        $clicks = $this->faker->numberBetween(0, max(1, (int) round($impressions * 0.03)));
        $spend = $this->faker->randomFloat(2, 8, 60);

        return [
            'company_id' => $company?->id,
            'car_id' => $car?->id,
            'mapping_id' => $mapping?->id,
            'campaign_id' => $mapping?->campaign_id ?? 'cmp_'.$this->faker->numerify('######'),
            'adset_id' => $mapping?->adset_id ?? 'adset_'.$this->faker->numerify('######'),
            'date' => now()->subDays($this->faker->numberBetween(0, 6))->toDateString(),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend_normalized' => $spend,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            'allocation_factor' => 1,
        ];
    }
}
