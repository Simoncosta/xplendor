<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarAiAnalysis;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarSalePotentialScore;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CarSpecsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $user;
    private Car     $car;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000010',
            'fiscal_name'         => 'Test Specs Company Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $brand = CarBrand::create([
            'name'         => 'Volkswagen',
            'slug'         => 'volkswagen',
            'vehicle_type' => 'car',
        ]);

        $model = CarModel::create([
            'name'         => 'Golf',
            'car_brand_id' => $brand->id,
        ]);

        $this->car = Car::factory()->create([
            'company_id'        => $this->company->id,
            'car_brand_id'      => $brand->id,
            'car_model_id'      => $model->id,
            'vehicle_type'      => 'car',
            'fuel_type'         => 'Gasolina',
            'transmission'      => 'Manual',
            'power_hp'          => 110,
            'engine_capacity_cc' => 1498,
            'doors'             => 5,
            'seats'             => 5,
            'exterior_color'    => 'Branco',
            'segment'           => 'Compacto',
            'condition'         => 'used',
            'mileage_km'        => 85000,
            'has_spare_key'     => true,
            'has_manuals'       => false,
            'origin'            => 'Nacional',
            'registration_year' => 2019,
            'registration_month' => 6,
            'price_gross'       => 15500,
            'license_plate'     => 'AA-00-BB',
        ]);
    }

    private function url(): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/specs";
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_specs_shapes_response_correctly(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'status',
                    'created_at',
                    'brand'   => ['id', 'name'],
                    'model'   => ['id', 'name'],
                    'version',
                    'specs'   => ['fuel_type', 'transmission', 'power_hp', 'engine_capacity_cc', 'doors', 'seats', 'segment', 'exterior_color'],
                    'state'   => ['condition', 'origin', 'mileage_km', 'has_spare_key', 'has_manuals', 'is_trade_in'],
                    'price'   => ['gross', 'promo_gross', 'promo_discount_pct'],
                    'registration' => ['year', 'month'],
                    'identification' => ['license_plate', 'vin'],
                    'description',
                    'images',
                    'header_meta' => ['potential_score', 'analyses'],
                ],
            ])
            ->assertJsonPath('data.specs.fuel_type', 'Gasolina')
            ->assertJsonPath('data.specs.power_hp', 110)
            ->assertJsonPath('data.state.has_spare_key', true)
            ->assertJsonPath('data.state.has_manuals', false)
            ->assertJsonPath('data.price.gross', 15500)
            ->assertJsonPath('data.registration.year', 2019)
            ->assertJsonPath('data.registration.month', 6)
            ->assertJsonPath('data.identification.license_plate', 'AA-00-BB');
    }

    public function test_specs_returns_404_for_unknown_car(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/99999/specs")
            ->assertStatus(404);
    }

    public function test_specs_returns_403_for_other_company_car(): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Other Plan',
            'price'      => 0,
            'car_limit'  => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherCompany = Company::create([
            'nipc'                => '500000011',
            'fiscal_name'         => 'Other Company Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $otherCar = Car::factory()->create([
            'company_id'        => $otherCompany->id,
            'vehicle_type'      => 'car',
            'registration_year' => 2020,
            'price_gross'       => 10000,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/{$otherCar->id}/specs")
            ->assertStatus(404);
    }

    public function test_specs_requires_authentication(): void
    {
        $this->getJson($this->url())
            ->assertStatus(401);
    }

    public function test_specs_header_meta_includes_potential_score_and_analyses(): void
    {
        CarSalePotentialScore::create([
            'car_id'               => $this->car->id,
            'company_id'           => $this->company->id,
            'score'                => 72,
            'classification'       => 'hot',
            'score_breakdown'      => [],
            'days_in_stock_at_calc' => 30,
            'calculated_at'        => now(),
            'triggered_by'         => 'manual',
        ]);

        CarAiAnalysis::create([
            'car_id'        => $this->car->id,
            'company_id'    => $this->company->id,
            'urgency_level' => 'Alta',
            'price_alert'   => true,
            'status'        => 'completed',
            'analysis'      => ['summary' => 'Test analysis'],
            'input_data'    => [],
            'analysis_raw'  => '{}',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonPath('data.header_meta.potential_score.score', 72)
            ->assertJsonPath('data.header_meta.potential_score.classification', 'hot')
            ->assertJsonPath('data.header_meta.analyses.urgency_level', 'Alta')
            ->assertJsonPath('data.header_meta.analyses.price_alert', true);
    }
}
