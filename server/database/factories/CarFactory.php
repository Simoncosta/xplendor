<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarModel;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    protected $model = Car::class;

    public function definition(): array
    {
        $brand = CarBrand::query()->where('vehicle_type', 'car')->first() ?? CarBrand::query()->first();
        $model = CarModel::query()
            ->when($brand, fn ($query) => $query->where('car_brand_id', $brand->id))
            ->first() ?? CarModel::query()->first();
        $category = CarCategory::query()->first();
        $company = Company::query()->first();

        return [
            'status' => 'active',
            'origin' => 'national',
            'vehicle_type' => 'car',
            'subsegment' => null,
            'car_brand_id' => $brand?->id,
            'car_model_id' => $model?->id,
            'car_category_id' => $category?->id,
            'registration_month' => $this->faker->numberBetween(1, 12),
            'registration_year' => $this->faker->numberBetween(2016, 2023),
            'version' => strtoupper($this->faker->bothify('##?')),
            'fuel_type' => $this->faker->randomElement(['Diesel', 'Gasolina', 'Hibrido']),
            'power_hp' => $this->faker->numberBetween(110, 280),
            'engine_capacity_cc' => $this->faker->randomElement([1499, 1598, 1968, 1995, 2143]),
            'doors' => $this->faker->randomElement([3, 5]),
            'transmission' => $this->faker->randomElement(['Manual', 'Automatica']),
            'segment' => $this->faker->randomElement(['Sedan', 'SUV', 'Carrinha']),
            'seats' => $this->faker->numberBetween(4, 7),
            'exterior_color' => $this->faker->randomElement(['Preto', 'Branco', 'Cinza', 'Azul']),
            'interior_color' => $this->faker->randomElement(['Preto', 'Bege']),
            'condition' => 'used',
            'mileage_km' => $this->faker->numberBetween(15000, 120000),
            'price_gross' => $this->faker->randomFloat(2, 14990, 48990),
            'price_net' => null,
            'hide_price_online' => false,
            'extras' => [],
            'company_id' => $company?->id,
            'created_at' => now()->subDays($this->faker->numberBetween(20, 140)),
            'updated_at' => now(),
        ];
    }
}
