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
 * XPLENDOR — Fase 2: vender → detetar lead aberta do cliente → (confirmação)
 * mover para "Venda" no funil. Cobre deteção (match por contacto do cliente),
 * confirmação (move + amarra venda↔lead), casos-limite e tenancy.
 */
class SaleLeadFunnelLinkTest extends TestCase
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
        $this->company = Company::create(['nipc' => '500001000', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500001001', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'California', 'car_brand_id' => $brand->id]);
        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold', 'sold_at' => now()])->id;

        $this->customer = Customer::create([
            'company_id' => $this->company->id, 'name' => 'João Silva',
            'email' => 'joao@exemplo.pt', 'phone' => '912 345 678',
        ]);
    }

    private function sale(array $extra = []): CarSale
    {
        return CarSale::create(array_merge([
            'car_id' => $this->carId, 'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person',
            'sold_at' => now(),
        ], $extra));
    }

    private function lead(array $extra = []): CarLead
    {
        return CarLead::create(array_merge([
            'name' => 'João Silva', 'email' => 'joao@exemplo.pt', 'phone' => '912345678',
            'status' => 'negotiation', 'source' => 'website_form',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ], $extra));
    }

    private function matchUrl(?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . "/cars/{$this->carId}/sale/lead-match";
    }

    private function linkUrl(?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . "/cars/{$this->carId}/sale/link-lead";
    }

    public function test_detects_open_lead_of_the_customer(): void
    {
        $this->sale();
        $lead = $this->lead(['status' => 'negotiation']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())->assertStatus(200);
        $res->assertJsonPath('data.candidates.0.id', $lead->id)
            ->assertJsonPath('data.candidates.0.status', 'negotiation');
    }

    public function test_matches_by_phone_despite_formatting(): void
    {
        $this->sale(); // customer phone "912 345 678"
        $lead = $this->lead(['email' => 'outro@x.pt', 'phone' => '+351 912 345 678']); // só o telefone bate (formato diferente)

        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.candidates.0.id', $lead->id);
    }

    public function test_no_lead_no_prompt(): void
    {
        $this->sale(); // cliente sem leads abertas
        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.candidates', []);
    }

    public function test_sale_without_customer_no_match(): void
    {
        $this->sale(['customer_id' => null]);
        $this->lead(); // existe lead, mas a venda não tem cliente → sem match
        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.candidates', []);
    }

    public function test_ignores_leads_already_won_or_lost(): void
    {
        $this->sale();
        $this->lead(['status' => 'won']);
        $this->lead(['status' => 'lost', 'lost_reason' => 'preco', 'email' => 'joao@exemplo.pt']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.candidates', []);
    }

    public function test_negotiation_is_prioritised_over_earlier_stages(): void
    {
        $this->sale();
        $this->lead(['status' => 'contacted']);
        $neg = $this->lead(['status' => 'negotiation']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.candidates.0.id', $neg->id); // Negociação primeiro
    }

    public function test_confirm_moves_lead_to_won_and_links_sale(): void
    {
        $this->sale();
        $lead = $this->lead(['status' => 'negotiation']);

        $this->actingAs($this->user, 'sanctum')->postJson($this->linkUrl(), ['lead_id' => $lead->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'won');

        $this->assertSame('won', $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->closed_at);
        // Venda amarrada à lead.
        $this->assertSame($lead->id, CarSale::where('car_id', $this->carId)->value('lead_id'));
    }

    public function test_cannot_move_a_closed_lead(): void
    {
        $this->sale();
        $lead = $this->lead(['status' => 'lost', 'lost_reason' => 'preco']);
        $this->actingAs($this->user, 'sanctum')->postJson($this->linkUrl(), ['lead_id' => $lead->id])
            ->assertStatus(422);
        $this->assertSame('lost', $lead->fresh()->status);
    }

    public function test_tenancy_other_company_forbidden(): void
    {
        $this->sale();
        $this->actingAs($this->user, 'sanctum')->getJson($this->matchUrl($this->other->id))->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->postJson($this->linkUrl($this->other->id), ['lead_id' => 1])->assertStatus(403);
    }
}
