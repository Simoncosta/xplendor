<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * XPLENDOR — Registo de venda enriquecido (Fase 1). Cobre: gravar/persistir os
 * campos novos (retoma, financiamento, anunciado vs final, desconto, 1ª
 * autocaravana, veículo anterior), nullable para vendas antigas, a origem herdada
 * da lead ligada, validação e tenancy.
 */
class CarSaleEnrichedFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private int $carId;
    private int $otherCarId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000900', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'California', 'car_brand_id' => $brand->id]);

        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold', 'sold_at' => now()])->id;
        $this->otherCarId = Car::factory()->create(['company_id' => $this->other->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold', 'sold_at' => now()])->id;
    }

    private function saleUrl(?int $carId = null, ?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/cars/' . ($carId ?? $this->carId) . '/sale';
    }

    private function specsUrl(?int $carId = null): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/" . ($carId ?? $this->carId) . '/specs';
    }

    public function test_update_sale_persists_enriched_fields(): void
    {
        // Venda já existente (o fecho criou-a); o utilizador edita para enriquecer.
        CarSale::create([
            'car_id' => $this->carId, 'company_id' => $this->company->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person',
            'sold_at' => now(),
        ]);

        $payload = [
            'sale_price' => 28500,
            'advertised_price' => 30000,
            'discount_amount' => 1500,
            'offers' => 'Tapetes + revisão',
            'has_financing' => true,
            'financing_entity' => 'Cofidis',
            'financed_amount' => 20000,
            'has_trade_in' => true,
            'trade_in_vehicle' => 'VW Golf 2015 · 12-AB-34',
            'trade_in_value' => 8000,
            'first_motorhome' => true,
            'previous_vehicle' => 'VW Golf',
        ];

        $this->actingAs($this->user, 'sanctum')->patchJson($this->saleUrl(), $payload)->assertStatus(200);

        $this->assertDatabaseHas('car_sales', [
            'car_id' => $this->carId,
            'advertised_price' => 30000,
            'discount_amount' => 1500,
            'offers' => 'Tapetes + revisão',
            'has_financing' => 1,
            'financing_entity' => 'Cofidis',
            'financed_amount' => 20000,
            'has_trade_in' => 1,
            'trade_in_vehicle' => 'VW Golf 2015 · 12-AB-34',
            'trade_in_value' => 8000,
            'first_motorhome' => 1,
            'previous_vehicle' => 'VW Golf',
        ]);
    }

    public function test_enriched_fields_are_nullable_for_old_sales(): void
    {
        // Venda "antiga" sem os campos novos → colunas ficam null, sem erro.
        $sale = CarSale::create([
            'car_id' => $this->carId, 'company_id' => $this->company->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person',
            'sold_at' => now(),
        ]);

        $fresh = $sale->fresh();
        $this->assertNull($fresh->advertised_price);
        $this->assertNull($fresh->has_trade_in);
        $this->assertNull($fresh->first_motorhome);
    }

    public function test_lead_origin_inherited_from_linked_lead(): void
    {
        $lead = CarLead::create([
            'name' => 'Cliente', 'email' => 'c@x.pt', 'phone' => '912345678', 'status' => 'won',
            'source' => 'website_form', 'channel' => 'paid', 'utm_source' => 'meta_ads', 'utm_campaign' => 'california_2026',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ]);
        CarSale::create([
            'car_id' => $this->carId, 'company_id' => $this->company->id, 'lead_id' => $lead->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'referral', 'sold_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->specsUrl())->assertStatus(200);

        // A origem aparece HERDADA da lead ligada (não duplicada na venda).
        $res->assertJsonPath('data.sale.lead_origin.utm_source', 'meta_ads')
            ->assertJsonPath('data.sale.lead_origin.channel', 'paid');
    }

    public function test_negative_values_are_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson($this->saleUrl(), ['advertised_price' => -5])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->patchJson($this->saleUrl(), ['trade_in_value' => -1])->assertStatus(422);
    }

    public function test_cannot_update_other_company_sale(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson($this->saleUrl($this->otherCarId, $this->other->id), ['discount_amount' => 100])
            ->assertStatus(403);
    }
}
