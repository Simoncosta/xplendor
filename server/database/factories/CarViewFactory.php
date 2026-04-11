<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarView;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarView>
 */
class CarViewFactory extends Factory
{
    protected $model = CarView::class;

    public function definition(): array
    {
        $company = Company::query()->first();
        $car = Car::query()->when($company, fn ($query) => $query->where('company_id', $company->id))->first();

        return [
            'company_id' => $company?->id,
            'car_id' => $car?->id,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'view_duration_seconds' => $this->faker->numberBetween(15, 130),
            'session_id' => $this->faker->uuid(),
            'visitor_id' => $this->faker->uuid(),
            'channel' => 'paid',
            'landing_path' => '/cars/'.$car?->id.'/ficha',
            'created_at' => now()->subDays($this->faker->numberBetween(0, 6)),
            'updated_at' => now(),
        ];
    }
}
