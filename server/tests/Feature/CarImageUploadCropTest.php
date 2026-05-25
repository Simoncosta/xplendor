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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CarImageUploadCropTest extends TestCase
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
        Queue::fake();

        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000098',
            'fiscal_name'         => 'Crop Test Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $this->brand = CarBrand::create([
            'name'         => 'Fiat',
            'slug'         => 'fiat-crop-test',
            'vehicle_type' => 'car',
        ]);

        $this->model = CarModel::create([
            'name'         => 'Punto',
            'car_brand_id' => $this->brand->id,
        ]);

        $this->car = Car::create([
            'company_id'         => $this->company->id,
            'status'             => 'active',
            'origin'             => 'national',
            'registration_year'  => 2020,
            'registration_month' => 1,
            'vehicle_type'       => 'car',
            'car_brand_id'       => $this->brand->id,
            'car_model_id'       => $this->model->id,
            'version'            => '1.2 Fire',
            'fuel_type'          => 'gasoline',
            'power_hp'           => 69,
            'engine_capacity_cc' => 1242,
            'doors'              => 5,
            'transmission'       => 'manual',
            'segment'            => 'city',
            'seats'              => 5,
            'exterior_color'     => 'white',
            'condition'          => 'used',
            'price_gross'        => 8500,
        ]);
    }

    private function basePayload(): array
    {
        return [
            'status'             => 'active',
            'origin'             => 'national',
            'registration_year'  => 2020,
            'vehicle_type'       => 'car',
            'car_brand_id'       => $this->brand->id,
            'car_model_id'       => $this->model->id,
            'version'            => '1.2 Fire',
            'fuel_type'          => 'gasoline',
            'power_hp'           => 69,
            'engine_capacity_cc' => 1242,
            'doors'              => 5,
            'transmission'       => 'manual',
            'segment'            => 'city',
            'seats'              => 5,
            'exterior_color'     => 'white',
            'condition'          => 'used',
            '_method'            => 'PUT',
        ];
    }

    /** @test */
    public function upload_with_crop_params_stores_original_and_sets_original_path(): void
    {
        $file = UploadedFile::fake()->image('foto.jpg', 800, 600);

        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                array_merge($this->basePayload(), [
                    'existing_images_present' => '1',
                    'images'                  => [$file],
                    'images_meta'             => [
                        [
                            'order'      => 1,
                            'is_primary' => 1,
                            'crop'       => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 450],
                        ],
                    ],
                ])
            );

        $response->assertOk();

        $image = $this->car->fresh()->images()->first();
        $this->assertNotNull($image, 'Devia ter criado um registo de imagem.');
        $this->assertNotNull($image->original_path, 'original_path deve ser preenchido após upload com crop.');
        $this->assertNotEquals($image->image, $image->original_path, 'original_path deve ser diferente do path da imagem cortada.');
        $this->assertStringContainsString('/originals/', $image->original_path, 'original_path deve estar na pasta originals/.');
        $this->assertStringContainsString('/images/', $image->image, 'image deve estar na pasta images/.');
    }

    /** @test */
    public function upload_without_crop_params_still_stores_original(): void
    {
        $file = UploadedFile::fake()->image('foto.png', 400, 300);

        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                array_merge($this->basePayload(), [
                    'existing_images_present' => '1',
                    'images'                  => [$file],
                    'images_meta'             => [
                        ['order' => 1, 'is_primary' => 1],
                    ],
                ])
            );

        $response->assertOk();

        $image = $this->car->fresh()->images()->first();
        $this->assertNotNull($image->original_path, 'original_path deve ser preenchido mesmo sem crop params.');
        $this->assertStringContainsString('/originals/', $image->original_path);
    }

    /** @test */
    public function create_car_with_image_stores_original_path(): void
    {
        $file = UploadedFile::fake()->image('capa.jpg', 1920, 1080);

        $response = $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars",
                [
                    'status'             => 'active',
                    'origin'             => 'national',
                    'registration_year'  => 2022,
                    'vehicle_type'       => 'car',
                    'car_brand_id'       => $this->brand->id,
                    'car_model_id'       => $this->model->id,
                    'version'            => '1.4 Turbo',
                    'fuel_type'          => 'gasoline',
                    'power_hp'           => 120,
                    'engine_capacity_cc' => 1368,
                    'doors'              => 5,
                    'transmission'       => 'manual',
                    'segment'            => 'city',
                    'seats'              => 5,
                    'exterior_color'     => 'red',
                    'condition'          => 'new',
                    'images'             => [$file],
                    'images_meta'        => [
                        [
                            'order'      => 1,
                            'is_primary' => 1,
                            'crop'       => ['x' => 0, 'y' => 0, 'width' => 1920, 'height' => 1080],
                        ],
                    ],
                ]
            );

        $response->assertOk();

        $newCarId = $response->json('data.id');
        $image    = Car::find($newCarId)?->images()->first();

        $this->assertNotNull($image);
        $this->assertNotNull($image->original_path);
        $this->assertStringContainsString('/originals/', $image->original_path);
    }

    // ─── Re-crop endpoint tests ────────────────────────────────────────────────

    private function makeImageWithOriginal(): CarImage
    {
        $file = UploadedFile::fake()->image('base.jpg', 800, 600);

        $this->actingAs($this->user)
            ->post(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}",
                array_merge($this->basePayload(), [
                    'existing_images_present' => '1',
                    'images'                  => [$file],
                    'images_meta'             => [
                        ['order' => 1, 'is_primary' => 1, 'crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 450]],
                    ],
                ])
            );

        return $this->car->fresh()->images()->firstOrFail();
    }

    /** @test */
    public function recrop_with_valid_original_returns_200(): void
    {
        $image = $this->makeImageWithOriginal();

        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/images/{$image->id}/recrop",
                ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 225]
            );

        $response->assertOk();
        $response->assertJsonPath('data.id', $image->id);
        $response->assertJsonPath('data.original_path', $image->original_path);
    }

    /** @test */
    public function recrop_without_original_path_returns_422(): void
    {
        // Image without original_path (legacy, pre-Z2.a)
        $image = CarImage::create([
            'car_id'     => $this->car->id,
            'company_id' => $this->company->id,
            'image'      => '/storage/img/legacy.webp',
            'order'      => 1,
            'is_primary' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/images/{$image->id}/recrop",
                ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 225]
            );

        $response->assertStatus(422);
        $this->assertStringContainsString('original', strtolower($response->json('message')));
    }

    /** @test */
    public function recrop_of_image_belonging_to_another_car_returns_404(): void
    {
        $image = $this->makeImageWithOriginal();

        // Create a different car
        $otherCar = Car::create([
            'company_id'         => $this->company->id,
            'status'             => 'active',
            'origin'             => 'national',
            'registration_year'  => 2021,
            'vehicle_type'       => 'car',
            'car_brand_id'       => $this->brand->id,
            'car_model_id'       => $this->model->id,
            'version'            => '1.0',
            'fuel_type'          => 'gasoline',
            'power_hp'           => 65,
            'engine_capacity_cc' => 999,
            'doors'              => 3,
            'transmission'       => 'manual',
            'segment'            => 'city',
            'seats'              => 4,
            'exterior_color'     => 'black',
            'condition'          => 'used',
            'price_gross'        => 5000,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(
                // image belongs to $this->car, not $otherCar
                "/api/v1/companies/{$this->company->id}/cars/{$otherCar->id}/images/{$image->id}/recrop",
                ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 225]
            );

        $response->assertStatus(404);
    }

    /** @test */
    public function recrop_of_image_belonging_to_another_company_returns_404(): void
    {
        $image = $this->makeImageWithOriginal();

        // Create a different company + user
        $otherPlanId = DB::table('plans')->insertGetId([
            'name'       => 'Other Plan',
            'price'      => 0,
            'car_limit'  => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherCompany = Company::create([
            'nipc'                => '500000097',
            'fiscal_name'         => 'Other Company Lda',
            'plan_id'             => $otherPlanId,
            'subscription_status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role'       => 'admin',
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson(
                "/api/v1/companies/{$otherCompany->id}/cars/{$this->car->id}/images/{$image->id}/recrop",
                ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 225]
            );

        // Either 403 (company access denied) or 404 (image not found for company)
        $this->assertContains($response->status(), [403, 404]);
    }
}
