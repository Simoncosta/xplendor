<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Public\CarIndexRequest;
use App\Models\Car;
use App\Models\Company;
use App\Models\User;
use App\Models\VehicleAttribute;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ficha das autocaravanas, parte B: os campos novos (marca do frigorífico, fogão com bocas e
 * tipo, marca do exaustor, marca e capacidade das baterias de cabine, número de velocidades)
 * e a cama pop-up panorâmica. Grava-se e lê-se cada campo pelo editor, a cama nova não passa a
 * "Outra", a API pública devolve as chaves novas e um carro grava igual a antes.
 */
class MotorhomeSheetFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'Plano', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500000930', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        app(CompanyModuleService::class)->enable($this->company->id, 'stock');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function create(array $payload): Car
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars", ['status' => 'draft', ...$payload])
            ->assertStatus(200);

        return Car::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function update(Car $car, array $payload)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}", ['status' => 'draft', ...$payload]);
    }

    private const NEW_ATTRIBUTES = [
        'habitation_basics' => [
            'has_kitchen' => true,
            'kitchen' => [
                'has_stove' => true, 'stove_burners' => 3, 'stove_type' => 'gas',
                'has_extractor' => true, 'extractor_brand' => 'Thetford',
                'has_fridge' => true, 'fridge_type' => 'compressor', 'fridge_brand' => 'Dometic',
            ],
        ],
        'energy_climate' => ['cabin_battery_count' => 2, 'cabin_battery_brand' => 'Varta', 'cabin_battery_ah' => 95],
        'beds' => [['type' => 'cama_popup_panoramica', 'capacity' => 2], ['type' => 'cama_sofa', 'capacity' => 1]],
    ];

    public function test_each_new_field_is_saved_and_read_back(): void
    {
        $car = $this->create(['vehicle_type' => 'motorhome']);

        $this->update($car, ['vehicle_type' => 'motorhome', 'transmission' => 'manual', 'gear_count' => 6,
            'vehicle_attributes' => self::NEW_ATTRIBUTES])->assertStatus(200);

        $read = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}")->assertStatus(200)->json('data');

        $this->assertSame(6, $read['gear_count']);
        $kitchen = $read['vehicle_attributes']['habitation_basics']['kitchen'];
        $this->assertSame(3, $kitchen['stove_burners']);
        $this->assertSame('gas', $kitchen['stove_type']);
        $this->assertSame('Thetford', $kitchen['extractor_brand']);
        $this->assertSame('Dometic', $kitchen['fridge_brand']);
        $energy = $read['vehicle_attributes']['energy_climate'];
        $this->assertSame('Varta', $energy['cabin_battery_brand']);
        $this->assertSame(95, $energy['cabin_battery_ah']);
        $this->assertSame(2, $energy['cabin_battery_count']);
    }

    public function test_the_popup_bed_does_not_become_other(): void
    {
        $normalized = VehicleAttribute::normalizeShape(['beds' => [['type' => 'cama_popup_panoramica', 'capacity' => 2]]]);
        $this->assertSame([['type' => 'cama_popup_panoramica', 'capacity' => 2]], $normalized['beds']);

        $car = $this->create(['vehicle_type' => 'motorhome']);
        $this->update($car, ['vehicle_type' => 'motorhome', 'vehicle_attributes' => self::NEW_ATTRIBUTES])->assertStatus(200);
        // A gravação ordena as camas (Arr::sortRecursive, comportamento anterior): lê-se por tipo.
        $beds = array_column($car->fresh()->vehicle_attributes['beds'], 'capacity', 'type');
        ksort($beds);
        $this->assertSame(['cama_popup_panoramica' => 2, 'cama_sofa' => 1], $beds);
    }

    public function test_an_unknown_new_value_is_rejected(): void
    {
        $car = $this->create(['vehicle_type' => 'motorhome']);
        $this->update($car, ['vehicle_type' => 'motorhome', 'gear_count' => 40, 'vehicle_attributes' => [
            'habitation_basics' => ['kitchen' => ['stove_type' => 'lenha']],
            'beds' => [['type' => 'cama_inventada']],
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'gear_count', 'vehicle_attributes.habitation_basics.kitchen.stove_type', 'vehicle_attributes.beds.0.type',
        ]);
    }

    public function test_the_public_api_returns_the_new_keys(): void
    {
        $car = $this->create(['vehicle_type' => 'motorhome']);
        $this->update($car, ['vehicle_type' => 'motorhome', 'transmission' => 'automatic', 'gear_count' => 8,
            'vehicle_attributes' => self::NEW_ATTRIBUTES])->assertStatus(200);
        DB::table('cars')->where('id', $car->id)->update(['status' => 'active']);
        $token = $this->company->public_api_token;

        $p = $this->getJson("/api/public/cars/{$car->id}?token={$token}")->assertStatus(200)->json('data');
        $this->assertSame(8, $p['gear_count']);
        $this->assertSame('automatic', $p['transmission']);
        $this->assertSame('Dometic', $p['habitation']['fridge_brand']);
        $this->assertSame(3, $p['habitation']['stove_burners']);
        $this->assertSame('gas', $p['habitation']['stove_type']);
        $this->assertSame('Thetford', $p['habitation']['extractor_brand']);
        $this->assertSame('Varta', $p['features']['cabin_battery_brand']);
        $this->assertSame(95, $p['features']['cabin_battery_ah']);
        $this->assertEqualsCanonicalizing([
            ['type' => 'cama_popup_panoramica', 'label' => 'Cama pop-up panorâmica'],
            ['type' => 'cama_sofa', 'label' => 'Cama de sofá'],
        ], array_map(fn ($b) => array_intersect_key($b, ['type' => 0, 'label' => 0]), $p['beds']));

        // car-filters com o rótulo.
        $filters = $this->getJson("/api/public/car-filters?token={$token}")->assertStatus(200)->json('data.bed_types');
        $this->assertContains(['slug' => 'cama_popup_panoramica', 'label' => 'Cama pop-up panorâmica', 'count' => 1], $filters);
        $this->assertContains(['slug' => 'cama_sofa', 'label' => 'Cama de sofá', 'count' => 1], $filters);
    }

    public function test_the_public_bed_filter_accepts_the_new_bed_and_the_sofa_bed(): void
    {
        // A consulta do filtro usa JSON_SEARCH (só MySQL); aqui valida-se a regra do pedido.
        $rules = (new CarIndexRequest())->rules();
        foreach (['cama_popup_panoramica', 'cama_sofa'] as $bed) {
            $this->assertFalse(Validator::make(['bed_types' => [$bed]], $rules)->fails(), $bed);
        }
        $this->assertTrue(Validator::make(['bed_types' => ['cama_inventada']], $rules)->fails());
    }

    public function test_a_car_saves_the_same_as_before(): void
    {
        $payload = ['vehicle_type' => 'car', 'transmission' => 'manual', 'mileage_km' => 1000,
            'extras' => [['group' => 'safety_performance', 'items' => ['ABS', 'Cruise Control']]]];
        $car = $this->create($payload);
        $before = DB::table('cars')->where('id', $car->id)->first();

        $this->update($car, $payload)->assertStatus(200);
        $after = DB::table('cars')->where('id', $car->id)->first();

        unset($before->updated_at, $after->updated_at);
        $this->assertEquals($before, $after);
        $this->assertNull($after->gear_count);
        $this->assertSame(0, DB::table('vehicle_attributes')->where('car_id', $car->id)->count());
        $this->assertSame(['comfort_multimedia', 'exterior_equipment', 'interior_equipment', 'safety_performance'],
            array_column($car->fresh()->extras, 'group'));
    }

    public function test_a_caravan_never_keeps_a_gear_count(): void
    {
        $car = $this->create(['vehicle_type' => 'caravan']);
        $this->update($car, ['vehicle_type' => 'caravan', 'gear_count' => 6])->assertStatus(200);
        $this->assertNull($car->fresh()->gear_count);
    }
}
