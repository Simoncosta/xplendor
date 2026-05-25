<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarImage;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CarImageUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $user;
    private Car     $car;
    private CarBrand $brand;
    private CarModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Prevent CarImageObserver from dispatching CalculateCarSalePotentialScoreJob,
        // which uses DATEDIFF (MariaDB-only) and fails under SQLite in tests.
        Queue::fake();

        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000099',
            'fiscal_name'         => 'Test Company Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $this->brand = CarBrand::create([
            'name'         => 'Fiat',
            'slug'         => 'fiat-test',
            'vehicle_type' => 'car',
        ]);

        $this->model = CarModel::create([
            'name'         => 'Punto',
            'car_brand_id' => $this->brand->id,
        ]);

        $this->car = Car::create([
            'company_id'        => $this->company->id,
            'status'            => 'active',
            'origin'            => 'national',
            'registration_year' => 2020,
            'registration_month'=> 1,
            'vehicle_type'      => 'car',
            'car_brand_id'      => $this->brand->id,
            'car_model_id'      => $this->model->id,
            'version'           => '1.2 Fire',
            'fuel_type'         => 'gasoline',
            'power_hp'          => 69,
            'engine_capacity_cc'=> 1242,
            'doors'             => 5,
            'transmission'      => 'manual',
            'segment'           => 'city',
            'seats'             => 5,
            'exterior_color'    => 'white',
            'condition'         => 'used',
            'price_gross'       => 8500,
        ]);
    }

    /** Minimum valid payload that passes CarRequest validation. */
    private function basePayload(): array
    {
        return [
            'status'              => 'active',
            'origin'              => 'national',
            'registration_year'   => 2020,
            'vehicle_type'        => 'car',
            'car_brand_id'        => $this->brand->id,
            'car_model_id'        => $this->model->id,
            'version'             => '1.2 Fire',
            'fuel_type'           => 'gasoline',
            'power_hp'            => 69,
            'engine_capacity_cc'  => 1242,
            'doors'               => 5,
            'transmission'        => 'manual',
            'segment'             => 'city',
            'seats'               => 5,
            'exterior_color'      => 'white',
            'condition'           => 'used',
            '_method'             => 'PUT',
        ];
    }

    /** @test */
    public function update_with_sentinel_and_empty_list_deletes_all_images(): void
    {
        // Arrange — 3 imagens existentes
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/a.jpg', 'order' => 1, 'is_primary' => true]);
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/b.jpg', 'order' => 2, 'is_primary' => false]);
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/c.jpg', 'order' => 3, 'is_primary' => false]);

        $this->assertEquals(3, $this->car->images()->count());

        // Act — envia sentinel + lista vazia (zero existing_images[*])
        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                array_merge($this->basePayload(), [
                    'existing_images_present' => '1',
                    // existing_images[] vazio — nada adicionado ao FormData
                ])
            );

        // Assert
        $response->assertOk();
        $this->assertEquals(0, $this->car->fresh()->images()->count());
    }

    /** @test */
    public function update_without_sentinel_preserves_all_images(): void
    {
        // Arrange — 2 imagens existentes
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/a.jpg', 'order' => 1, 'is_primary' => true]);
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/b.jpg', 'order' => 2, 'is_primary' => false]);

        $this->assertEquals(2, $this->car->images()->count());

        // Act — sem sentinel nem existing_images (simula request sem tocar em imagens)
        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                $this->basePayload()
            );

        // Assert — imagens intactas
        $response->assertOk();
        $this->assertEquals(2, $this->car->fresh()->images()->count());
    }

    /** @test */
    public function update_with_sentinel_and_partial_list_deletes_only_removed_images(): void
    {
        // Arrange — 3 imagens
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/a.jpg', 'order' => 1, 'is_primary' => true]);
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/b.jpg', 'order' => 2, 'is_primary' => false]);
        CarImage::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'image' => '/storage/img/c.jpg', 'order' => 3, 'is_primary' => false]);

        // Act — mantém a e b, remove c
        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                array_merge($this->basePayload(), [
                    'existing_images_present' => '1',
                    'existing_images'         => ['/storage/img/a.jpg', '/storage/img/b.jpg'],
                    'existing_images_meta'    => [
                        ['order' => 1, 'is_primary' => 1],
                        ['order' => 2, 'is_primary' => 0],
                    ],
                ])
            );

        $response->assertOk();
        $remaining = $this->car->fresh()->images()->pluck('image');
        $this->assertCount(2, $remaining);
        $this->assertContains('/storage/img/a.jpg', $remaining);
        $this->assertContains('/storage/img/b.jpg', $remaining);
        $this->assertNotContains('/storage/img/c.jpg', $remaining);
    }
}
