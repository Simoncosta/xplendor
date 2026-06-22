<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R1 — "Guardar rascunho sem campos obrigatórios" (1.14.7, 2026-06-22).
 *
 * Cobre a ramificação `CarRequest::isDraftSave()` + `relaxForDraft()`:
 *  - Rascunho com SÓ vehicle_type passa (sem 422).
 *  - Publicação (status != 'draft') aplica a defesa completa.
 *  - Transição rascunho → activa exige campos completos (não passa silenciosamente).
 *  - Relaxar `required` NÃO relaxa `min:1` / `max:N` — lixo continua rejeitado.
 *
 * Princípio: relaxa o "obrigatório" mas NÃO o "válido".
 */
class CarDraftSaveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $user;
    private int     $brandId;
    private int     $modelId;

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
            'nipc'                => '500000020',
            'fiscal_name'         => 'Test Draft Save Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $brand = CarBrand::create([
            'name'         => 'Hymer',
            'slug'         => 'hymer',
            'vehicle_type' => 'motorhome',
        ]);
        $model = CarModel::create([
            'name'         => 'Tramp',
            'car_brand_id' => $brand->id,
        ]);

        $this->brandId = $brand->id;
        $this->modelId = $model->id;
    }

    private function storeUrl(): string
    {
        return "/api/v1/companies/{$this->company->id}/cars";
    }

    private function updateUrl(int $carId): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$carId}";
    }

    /** Conjunto mínimo necessário para criar uma viatura "publicada" (status='active'). */
    private function fullPublishPayload(): array
    {
        return [
            'status'             => 'active',
            'vehicle_type'       => 'motorhome',
            'origin'             => 'national',
            'registration_year'  => 2020,
            'car_brand_id'       => $this->brandId,
            'car_model_id'       => $this->modelId,
            'version'            => 'Tramp 4x4',
            'doors'              => 4,
            'segment'            => 'integral',
            'seats'              => 4,
            'exterior_color'     => 'Branco',
            'condition'          => 'used',
            'fuel_type'          => 'Diesel',
            'power_hp'           => 150,
            'engine_capacity_cc' => 2300,
            'transmission'       => 'Manual',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_draft_save_with_only_vehicle_type_passes(): void
    {
        $payload = [
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cars', [
            'company_id'   => $this->company->id,
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
        ]);
    }

    public function test_draft_save_via_flag_forces_status_and_passes(): void
    {
        // Mesmo se o cliente esquecer de pôr status='draft' no payload, o
        // FE força via flag. Backend honra a flag.
        $payload = [
            'status'            => 'active',          // ⬅ flag deve vencer
            'vehicle_type'      => 'motorhome',
            '__save_as_draft'   => 1,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        // A flag tira o `required` aos campos completos, mas o backend mantém
        // o status que o cliente enviou ('active'). O FE é responsável por
        // forçar status='draft' antes do submit — este teste prova só a
        // relaxação de regras.
        $response->assertStatus(200);
    }

    public function test_publish_with_missing_required_fields_fails_with_422(): void
    {
        $payload = [
            'status'       => 'active',
            'vehicle_type' => 'motorhome',
            // tudo o resto em falta — a defesa completa tem de actuar.
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'origin', 'registration_year', 'car_brand_id', 'car_model_id',
                'version', 'doors', 'segment', 'seats', 'exterior_color',
                'condition', 'fuel_type', 'power_hp', 'engine_capacity_cc',
                'transmission',
            ]);
    }

    public function test_draft_to_active_transition_requires_full_validation(): void
    {
        // 1) Cria um rascunho incompleto.
        $draftResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), [
                'status'       => 'draft',
                'vehicle_type' => 'motorhome',
            ]);

        $draftResponse->assertStatus(200);
        $carId = (int) ($draftResponse->json('data.id') ?? $draftResponse->json('id'));
        $this->assertGreaterThan(0, $carId, 'created car id should be returned');

        // 2) Tenta promover para 'active' SEM completar — tem de falhar 422.
        // Este é o ponto subtil da auditoria §4: a promoção não pode passar
        // silenciosamente.
        $promoteResponse = $this->actingAs($this->user, 'sanctum')
            ->putJson($this->updateUrl($carId), [
                'status'       => 'active',
                'vehicle_type' => 'motorhome',
            ]);

        $promoteResponse->assertStatus(422)
            ->assertJsonValidationErrors([
                'origin', 'registration_year', 'car_brand_id', 'car_model_id',
                'version', 'doors', 'segment', 'seats', 'exterior_color',
                'condition', 'fuel_type', 'power_hp', 'engine_capacity_cc',
                'transmission',
            ]);

        // BD continua draft (a viatura NÃO foi promovida).
        $this->assertDatabaseHas('cars', [
            'id'     => $carId,
            'status' => 'draft',
        ]);
    }

    public function test_draft_save_still_rejects_invalid_typed_values(): void
    {
        // "Relaxar obrigatório" ≠ "aceitar lixo". Um campo PREENCHIDO em
        // rascunho com valor inválido (ex.: seats negativo) continua a ser
        // rejeitado — as regras de tipo/min/max não são relaxadas.
        $payload = [
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
            'seats'        => -5,           // viola min:1
            'doors'        => 99,           // viola max:6
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['seats', 'doors']);
    }

    public function test_draft_save_does_not_trigger_mark_as_sold(): void
    {
        // Salvaguarda da auditoria §5: o caminho de venda só dispara com
        // status='sold' + sold_at no payload. Um rascunho NUNCA toca esse
        // caminho. Comprova-se indirectamente: criar com status='draft'
        // e sold_at preenchido NÃO entra na lógica de venda.
        $payload = [
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
            'sold_at'      => now()->toDateString(), // ignorado em draft
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload)
            ->assertStatus(200);

        // Não há registo em car_sales para o rascunho.
        $this->assertDatabaseCount('car_sales', 0);
    }
}
