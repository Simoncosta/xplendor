<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS Fase 1a — preço de compra + regime de IVA por viatura.
 *
 * Cobre a validação e persistência dos 2 campos novos em `cars`:
 *  - purchase_price (nullable numeric min:0)
 *  - vat_regime     (nullable Rule::in(['margem','normal','isento']))
 *
 * Nota: a visibilidade do regime de IVA (só quando a empresa tem uses_vat)
 * é uma condicional do FRONTEND. O backend aceita vat_regime nullable sempre
 * (uma empresa sem IVA simplesmente nunca o envia).
 */
class CarPurchaseFieldsTest extends TestCase
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
            'nipc'                => '500000021',
            'fiscal_name'         => 'Test Purchase Fields Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
            'uses_vat'            => true,
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

    public function test_uses_vat_flag_persists_on_company(): void
    {
        $this->assertDatabaseHas('companies', [
            'id'       => $this->company->id,
            'uses_vat' => true,
        ]);
        $this->assertTrue($this->company->fresh()->uses_vat === true);
    }

    public function test_stores_purchase_price_and_vat_regime(): void
    {
        $payload = [
            'status'         => 'draft',
            'vehicle_type'   => 'motorhome',
            'purchase_price' => 42500.00,
            'vat_regime'     => 'margem',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cars', [
            'company_id'     => $this->company->id,
            'purchase_price' => 42500.00,
            'vat_regime'     => 'margem',
        ]);
    }

    public function test_purchase_price_and_vat_regime_are_optional(): void
    {
        // Empresa sem IVA (Paulo) nunca envia vat_regime; e purchase_price
        // pode ficar por preencher (dado histórico). Ambos nullable → 200.
        $payload = [
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cars', [
            'company_id'     => $this->company->id,
            'purchase_price' => null,
            'vat_regime'     => null,
        ]);
    }

    public function test_rejects_invalid_vat_regime(): void
    {
        $payload = [
            'status'       => 'draft',
            'vehicle_type' => 'motorhome',
            'vat_regime'   => 'inventado',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vat_regime']);
    }

    public function test_rejects_negative_purchase_price(): void
    {
        // Relaxar o "obrigatório" no rascunho NÃO relaxa o "válido": min:0 mantém-se.
        $payload = [
            'status'         => 'draft',
            'vehicle_type'   => 'motorhome',
            'purchase_price' => -100,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->storeUrl(), $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_price']);
    }
}
