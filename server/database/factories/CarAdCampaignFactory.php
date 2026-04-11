<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarAdCampaign>
 */
class CarAdCampaignFactory extends Factory
{
    protected $model = CarAdCampaign::class;

    public function definition(): array
    {
        $company = Company::query()->first();
        $car = Car::query()->when($company, fn ($query) => $query->where('company_id', $company->id))->first();
        $suffix = strtoupper($this->faker->bothify('??##'));

        return [
            'company_id' => $company?->id,
            'car_id' => $car?->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_'.$this->faker->unique()->numerify('######'),
            'campaign_name' => 'Campaign '.$suffix,
            'adset_id' => 'adset_'.$this->faker->unique()->numerify('######'),
            'adset_name' => 'Adset '.$suffix,
            'level' => 'adset',
            'spend_split_pct' => 100,
            'is_active' => true,
        ];
    }
}
