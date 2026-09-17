<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * XPLENDOR — Fase 3: ficha-hub do cliente. Vendas (customer_id), leads (match de
 * contacto, reutiliza a Fase 2), documentos (vazio) e histórico derivado. + tenancy.
 */
class CustomerHubTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private int $carId;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500001100', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500001101', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'California', 'car_brand_id' => $brand->id]);
        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold', 'sold_at' => now()])->id;

        $this->customer = Customer::create([
            'company_id' => $this->company->id, 'name' => 'João Silva',
            'email' => 'joao@exemplo.pt', 'phone' => '912 345 678',
        ]);
    }

    private function hubUrl(?int $id = null, ?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/customers/' . ($id ?? $this->customer->id) . '/hub';
    }

    public function test_hub_shows_customer_sales(): void
    {
        CarSale::create([
            'car_id' => $this->carId, 'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person',
            'sale_price' => 42000, 'advertised_price' => 44000, 'has_trade_in' => true, 'trade_in_value' => 9000,
            'sold_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl())->assertStatus(200);
        $res->assertJsonPath('data.sales.0.sale_price', 42000)
            ->assertJsonPath('data.sales.0.advertised_price', 44000)
            ->assertJsonPath('data.sales.0.has_trade_in', true);
    }

    public function test_hub_shows_leads_matched_by_contact(): void
    {
        // Lead por telefone com formato diferente (reutiliza o match da Fase 2).
        $lead = CarLead::create([
            'name' => 'João Silva', 'email' => 'x@y.pt', 'phone' => '+351 912 345 678',
            'status' => 'negotiation', 'source' => 'website_form', 'channel' => 'paid',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl())->assertStatus(200);
        $res->assertJsonPath('data.leads.0.id', $lead->id)
            ->assertJsonPath('data.leads.0.status', 'negotiation');
    }

    public function test_hub_includes_closed_leads_too(): void
    {
        // Ao contrário da Fase 2 (só abertas), a ficha mostra TODAS as fases.
        CarLead::create([
            'name' => 'João Silva', 'email' => 'joao@exemplo.pt', 'phone' => '999999999',
            'status' => 'lost', 'lost_reason' => 'preco', 'source' => 'website_form',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ]);
        $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.leads.0.status', 'lost');
    }

    public function test_empty_blocks_when_nothing_linked(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl())->assertStatus(200);
        $res->assertJsonPath('data.sales', [])
            ->assertJsonPath('data.leads', [])
            ->assertJsonPath('data.documents', [])
            ->assertJsonPath('data.history', []);
    }

    public function test_history_is_derived_from_sales_and_leads(): void
    {
        CarSale::create([
            'car_id' => $this->carId, 'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now(),
        ]);
        CarLead::create([
            'name' => 'João', 'email' => 'joao@exemplo.pt', 'phone' => '912345678',
            'status' => 'won', 'source' => 'website_form', 'channel' => 'paid',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl())->assertStatus(200);
        $history = $res->json('data.history');
        $this->assertNotEmpty($history);
        $types = collect($history)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['lead', 'sale'], $types);
    }

    public function test_only_own_company_data(): void
    {
        // Venda de OUTRA empresa com o mesmo customer_id não aparece (scoped).
        $otherCustomer = Customer::create(['company_id' => $this->other->id, 'name' => 'Outro', 'email' => 'joao@exemplo.pt', 'phone' => '912 345 678']);
        $this->assertNotNull($otherCustomer);

        // Utilizador da empresa A não acede à ficha da empresa B.
        $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl($otherCustomer->id, $this->other->id))->assertStatus(403);
    }

    public function test_customer_not_found_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->hubUrl(999999))->assertStatus(404);
    }
}
