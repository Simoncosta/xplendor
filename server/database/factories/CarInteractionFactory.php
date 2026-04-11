<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarInteraction;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarInteraction>
 */
class CarInteractionFactory extends Factory
{
    protected $model = CarInteraction::class;

    public function definition(): array
    {
        $company = Company::query()->first();
        $car = Car::query()->when($company, fn ($query) => $query->where('company_id', $company->id))->first();

        return [
            'company_id' => $company?->id,
            'car_id' => $car?->id,
            'interaction_type' => $this->faker->randomElement(['scroll', 'form_open', 'whatsapp_click']),
            'interaction_target' => 'car_detail',
            'page_type' => 'car_detail',
            'page_context' => 'action_center_demo',
            'page_url' => 'https://xplendor.local/cars/'.$car?->id.'/ficha',
            'channel' => 'paid',
            'visitor_id' => $this->faker->uuid(),
            'session_id' => $this->faker->uuid(),
            'meta' => ['scroll_pct' => $this->faker->numberBetween(25, 95)],
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'created_at' => now()->subDays($this->faker->numberBetween(0, 6)),
            'updated_at' => now(),
        ];
    }
}
