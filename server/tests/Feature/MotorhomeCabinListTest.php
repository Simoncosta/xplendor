<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ficha das autocaravanas, parte C: a lista única "Cabine de condução e segurança" é só
 * apresentação (web/src/pages/Cars/Car/data/motorhomeCabinList.ts). O servidor grava os
 * extras tal como chegam: os textos de hoje não mudam, os textos novos ficam como estão, um
 * extra fora da lista mantém-se e um carro continua com os 4 grupos de sempre.
 */
class MotorhomeCabinListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private const GROUPS = ['comfort_multimedia', 'exterior_equipment', 'interior_equipment', 'safety_performance'];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'Plano', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500000931', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        app(CompanyModuleService::class)->enable($this->company->id, 'stock');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function save(string $vehicleType, array $payload): Car
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars", ['status' => 'draft', 'vehicle_type' => $vehicleType])
            ->assertStatus(200);
        $car = Car::where('company_id', $this->company->id)->latest('id')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}", ['status' => 'draft', 'vehicle_type' => $vehicleType, ...$payload])
            ->assertStatus(200);

        return $car->fresh();
    }

    private static function extras(array $byGroup): array
    {
        return array_map(fn ($g) => ['group' => $g, 'items' => $byGroup[$g] ?? []], self::GROUPS);
    }

    public function test_motorhome_extras_keep_todays_texts_new_texts_and_out_of_list_items(): void
    {
        $extras = self::extras([
            // Textos de hoje (a lista mostra "Ecrã tátil", "Vidros elétricos"…), textos novos e um fora da lista.
            'comfort_multimedia' => ['Ecrã Táctil ou Touch Screen', 'Vidros eléctricos', 'Rádio Android', 'Rádio digital (DAB)', 'Android Auto'],
            'exterior_equipment' => ['Faróis Diurnos Em Led', 'Retrovisor Anti-Encadeamento', 'Jantes de Liga Leve'],
            'interior_equipment' => ['Carregador de smartphone', 'Bancos rotativos', 'Acabamentos em Madeira'],
            // Um texto antigo que nem está no catálogo continua gravado.
            'safety_performance' => ['Travão de Mão Eléctrico', 'Fecho Centralizado com Comando a Distância', 'Alarme', 'Texto antigo de importação'],
        ]);

        $car = $this->save('motorhome', ['extras' => $extras, 'vehicle_attributes' => [
            'security' => ['has_alarm' => true],
            'interior_furniture' => ['has_rotating_seats' => true],
        ]]);

        $this->assertSame($extras, $car->extras);
        $this->assertTrue($car->vehicle_attributes['security']['has_alarm']);
        $this->assertTrue($car->vehicle_attributes['interior_furniture']['has_rotating_seats']);

        $read = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}")->assertStatus(200)->json('data.extras');
        $this->assertSame($extras, $read);

        // A API pública devolve os extras em bruto, com os mesmos textos.
        DB::table('cars')->where('id', $car->id)->update(['status' => 'active']);
        $public = $this->getJson("/api/public/cars/{$car->id}?token={$this->company->public_api_token}")->assertStatus(200)->json('data.extras');
        $this->assertSame($extras, $public);
    }

    public function test_saving_again_with_the_same_payload_changes_nothing(): void
    {
        $extras = self::extras(['comfort_multimedia' => ['Android Auto'], 'safety_performance' => ['ABS', 'Fecho Central']]);
        $car = $this->save('motorhome', ['extras' => $extras]);
        $before = DB::table('cars')->where('id', $car->id)->value('extras');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}", ['status' => 'draft', 'vehicle_type' => 'motorhome', 'extras' => $extras])
            ->assertStatus(200);
        $this->assertSame($before, DB::table('cars')->where('id', $car->id)->value('extras'));
    }

    public function test_a_car_keeps_the_four_usual_groups(): void
    {
        $car = $this->save('car', ['transmission' => 'manual', 'extras' => [['group' => 'safety_performance', 'items' => ['ABS']]]]);
        $this->assertSame(self::GROUPS, array_column($car->extras, 'group'));
        $this->assertSame(['ABS'], $car->extras[3]['items']);
    }
}
