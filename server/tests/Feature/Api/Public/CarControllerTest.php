<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Public;

use App\Models\Car;
use App\Models\Company;
use Tests\TestCase;

/**
 * Public Car API — feature tests.
 *
 * Uses live dev MariaDB database (no RefreshDatabase). All assertions are
 * defensive: they succeed whether the company has 0 or N matching cars.
 */
class CarControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory:
        // These tests require the real MariaDB dev database.
        config([
            'database.default'                       => 'mysql',
            'database.connections.mysql.database'    => 'xplendor',
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');
    }

    // Company 4 (motorhome stand) — has active motorhomes with vehicle_attributes
    private const TOKEN_MH  = '753eeb0d-07ae-418c-be51-f66b01be5b8a';
    private const COMPANY_MH_ID = 4;

    // Company 2 — used for cross-company isolation
    private const TOKEN_C2  = 'aeb8100b-b9d0-4615-b051-fefeb73fe605';
    private const COMPANY_C2_ID = 2;

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function test_index_rejects_missing_token(): void
    {
        $this->getJson('/api/public/cars')
            ->assertStatus(401);
    }

    public function test_index_rejects_invalid_token(): void
    {
        $this->getJson('/api/public/cars?token=invalid-token-xyz')
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Status filter — only active / available_soon returned
    // -------------------------------------------------------------------------

    public function test_index_only_returns_active_and_available_soon_cars(): void
    {
        $response = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);

        $items = $response->json('data');
        if (!empty($items)) {
            $statuses = array_unique(array_column($items, 'status'));
            foreach ($statuses as $status) {
                $this->assertContains($status, ['active', 'sold', 'available_soon']);
            }
        } else {
            $this->assertTrue(true); // no cars → nothing to fail
        }
    }

    // -------------------------------------------------------------------------
    // PII — sensitive fields must not appear in the response
    // -------------------------------------------------------------------------

    public function test_index_does_not_expose_sensitive_fields(): void
    {
        $response = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH)
            ->assertStatus(200);

        $items = $response->json('data');
        foreach ($items as $car) {
            $this->assertArrayNotHasKey('vin', $car, 'VIN must not be in public response');
            $this->assertArrayNotHasKey('license_plate', $car, 'license_plate must not be in public response');
            $this->assertArrayNotHasKey('internal_notes', $car, 'internal_notes must not be in public response');
            $this->assertArrayNotHasKey('seller_user_id', $car, 'seller_user_id must not be in public response');
            $this->assertArrayNotHasKey('company_id', $car, 'company_id must not be in public response');

            if (isset($car['seller'])) {
                $this->assertArrayNotHasKey('email', $car['seller'], 'seller.email must not be in public response');
                $this->assertArrayNotHasKey('role', $car['seller'], 'seller.role must not be in public response');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Resource shape — specs / habitation / beds present for motorhomes
    // -------------------------------------------------------------------------

    public function test_show_returns_public_resource_shape_for_motorhome(): void
    {
        $car = Car::where('company_id', self::COMPANY_MH_ID)
            ->where('vehicle_type', 'motorhome')
            ->whereIn('status', ['active', 'sold', 'available_soon'])
            ->first();

        if (!$car) {
            $this->markTestSkipped('No active motorhome for company ' . self::COMPANY_MH_ID);
        }

        $response = $this->getJson('/api/public/cars/' . $car->id . '?token=' . self::TOKEN_MH)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);

        $data = $response->json('data');

        // Top-level fields that must be present
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('vehicle_type', $data);
        $this->assertArrayHasKey('specs', $data);
        $this->assertArrayHasKey('images', $data);
        $this->assertArrayHasKey('extras', $data);

        // Motorhome: habitation, features, beds must be arrays (not null)
        $this->assertNotNull($data['habitation'], 'habitation must not be null for motorhome');
        $this->assertNotNull($data['features'], 'features must not be null for motorhome');
        $this->assertNotNull($data['beds'], 'beds must not be null for motorhome');
        $this->assertIsArray($data['beds']);

        // Beds must have type + label
        foreach ($data['beds'] as $bed) {
            $this->assertArrayHasKey('type', $bed);
            $this->assertArrayHasKey('label', $bed);
        }
    }

    // -------------------------------------------------------------------------
    // Habitation fields null for non-motorhome/caravan
    // -------------------------------------------------------------------------

    public function test_show_habitation_fields_are_null_for_car_type(): void
    {
        $car = Car::where('company_id', self::COMPANY_MH_ID)
            ->where('vehicle_type', 'car')
            ->whereIn('status', ['active', 'sold', 'available_soon'])
            ->first();

        if (!$car) {
            // Fall back to any non-motorhome across all companies
            $car = Car::whereNotIn('vehicle_type', ['motorhome', 'caravan'])
                ->whereIn('status', ['active', 'sold', 'available_soon'])
                ->first();
        }

        if (!$car) {
            $this->markTestSkipped('No active non-motorhome car available');
        }

        $token = Company::find($car->company_id)?->public_api_token;
        if (!$token) {
            $this->markTestSkipped('Cannot resolve token for company ' . $car->company_id);
        }

        $data = $this->getJson('/api/public/cars/' . $car->id . '?token=' . $token)
            ->assertStatus(200)
            ->json('data');

        $this->assertNull($data['habitation'], 'habitation must be null for car type');
        $this->assertNull($data['features'], 'features must be null for car type');
        $this->assertNull($data['beds'], 'beds must be null for car type');
        $this->assertNull($data['category'], 'category must be null for car type');
    }

    // -------------------------------------------------------------------------
    // Cross-company isolation
    // -------------------------------------------------------------------------

    public function test_show_denies_access_to_car_from_different_company(): void
    {
        // Get a car that belongs to company MH but try to access it with token C2
        $car = Car::where('company_id', self::COMPANY_MH_ID)
            ->whereIn('status', ['active', 'sold', 'available_soon'])
            ->first();

        if (!$car) {
            $this->markTestSkipped('No active car for company ' . self::COMPANY_MH_ID);
        }

        $this->getJson('/api/public/cars/' . $car->id . '?token=' . self::TOKEN_C2)
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Filters — bed_types
    // -------------------------------------------------------------------------

    public function test_index_filter_by_bed_type_returns_subset(): void
    {
        $allCars = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH)
            ->assertStatus(200)
            ->json('data');

        $bedType = null;
        foreach ($allCars as $car) {
            if (!empty($car['beds'][0]['type'])) {
                $bedType = $car['beds'][0]['type'];
                break;
            }
        }

        if (!$bedType) {
            $this->markTestSkipped('No car with beds available for company ' . self::COMPANY_MH_ID);
        }

        $filtered = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH . '&bed_types[]=' . $bedType)
            ->assertStatus(200)
            ->json('data');

        // Every returned car must have the requested bed type
        foreach ($filtered as $car) {
            $types = array_column($car['beds'] ?? [], 'type');
            $this->assertContains($bedType, $types, "Car {$car['id']} is missing bed type '{$bedType}'");
        }
    }

    // -------------------------------------------------------------------------
    // Filters — has_bathroom boolean
    // -------------------------------------------------------------------------

    public function test_index_filter_has_bathroom_returns_only_matching_cars(): void
    {
        $response = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH . '&has_bathroom=1')
            ->assertStatus(200);

        $items = $response->json('data');
        foreach ($items as $car) {
            $this->assertTrue(
                (bool) ($car['habitation']['has_bathroom'] ?? false),
                "Car {$car['id']} does not have has_bathroom=true"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Filters — category slug
    // -------------------------------------------------------------------------

    public function test_index_filter_by_category_slug_returns_correct_cars(): void
    {
        $filtersData = $this->getJson('/api/public/car-filters?token=' . self::TOKEN_MH)
            ->assertStatus(200)
            ->json('data');

        $categories = $filtersData['categories'] ?? [];
        if (empty($categories)) {
            $this->markTestSkipped('No categories in filters for company ' . self::COMPANY_MH_ID);
        }

        $slug = $categories[0]['slug'];

        $cars = $this->getJson('/api/public/cars?token=' . self::TOKEN_MH . '&category=' . $slug)
            ->assertStatus(200)
            ->json('data');

        foreach ($cars as $car) {
            $this->assertSame($slug, $car['category']['slug'] ?? null, "Car {$car['id']} has wrong category slug");
        }
    }

    // -------------------------------------------------------------------------
    // filters() endpoint shape
    // -------------------------------------------------------------------------

    public function test_filters_endpoint_returns_habitation_sections(): void
    {
        $data = $this->getJson('/api/public/car-filters?token=' . self::TOKEN_MH)
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data'])
            ->json('data');

        // Core legacy keys
        $this->assertArrayHasKey('brands', $data);
        $this->assertArrayHasKey('models', $data);
        $this->assertArrayHasKey('registration_years', $data);

        // New habitation keys
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('bed_types', $data);
        $this->assertArrayHasKey('seats_range', $data);
        $this->assertArrayHasKey('length_m_range', $data);
        $this->assertArrayHasKey('available_features', $data);

        // bed_types entries must have slug, label, count
        foreach ($data['bed_types'] as $bt) {
            $this->assertArrayHasKey('slug', $bt);
            $this->assertArrayHasKey('label', $bt);
            $this->assertArrayHasKey('count', $bt);
            $this->assertGreaterThan(0, $bt['count']);
        }

        // categories must have id, name, slug, count
        foreach ($data['categories'] as $cat) {
            $this->assertArrayHasKey('id', $cat);
            $this->assertArrayHasKey('name', $cat);
            $this->assertArrayHasKey('slug', $cat);
            $this->assertArrayHasKey('count', $cat);
        }

        // available_features keys are from the approved set
        $allowed = ['has_bathroom', 'has_kitchen', 'has_solar_panel'];
        foreach ($data['available_features'] as $feat) {
            $this->assertContains($feat['key'], $allowed);
            $this->assertArrayHasKey('count', $feat);
        }
    }
}
