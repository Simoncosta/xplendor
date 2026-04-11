<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarLead>
 */
class CarLeadFactory extends Factory
{
    protected $model = CarLead::class;

    public function definition(): array
    {
        $company = Company::query()->first();
        $car = Car::query()->when($company, fn($query) => $query->where('company_id', $company->id))->first();

        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('9########'),
            'message' => 'Pretendo saber mais sobre esta viatura.',
            'status' => 'new',
            'source' => 'website_form', // ou 'whatsapp'
            'utm_source' => 'meta_ads',
            'utm_medium' => 'paid',
            'utm_campaign' => 'bmw_series_3_test',
            'channel' => 'paid',
            'visitor_id' => $this->faker->uuid(),
            'session_id' => $this->faker->uuid(),
            'car_id' => $car?->id,
            'company_id' => $company?->id,
            'created_at' => now()->subDays($this->faker->numberBetween(0, 6)),
            'updated_at' => now(),
        ];
    }
}
