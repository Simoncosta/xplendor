<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * XPLENDOR — CRM / funil de leads (car_leads). Cobre: listar leads da empresa,
 * mover a lead entre fases (incl. as fases novas Visita/Negociação), exigir motivo
 * ao mover para Perdida, e a tenancy (2 camadas).
 */
class LeadFunnelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private User $stranger;
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
        $this->company = Company::create(['nipc' => '500000800', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000801', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'bmw'], ['name' => 'BMW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Serie 3', 'car_brand_id' => $brand->id]);

        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id])->id;
        $this->otherCarId = Car::factory()->create(['company_id' => $this->other->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id])->id;
    }

    private function lead(array $extra = [], bool $other = false): CarLead
    {
        return CarLead::create(array_merge([
            'name' => 'Cliente', 'email' => uniqid() . '@x.pt', 'phone' => '912345678',
            'status' => 'new', 'source' => 'website_form',
            'car_id' => $other ? $this->otherCarId : $this->carId,
            'company_id' => $other ? $this->other->id : $this->company->id,
        ], $extra));
    }

    private function url(?int $id = null, ?int $companyId = null): string
    {
        $base = '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/leads';
        return $id ? "{$base}/{$id}" : $base;
    }

    public function test_index_lists_only_own_company_leads(): void
    {
        $this->lead(['name' => 'Minha']);
        $this->lead(['name' => 'Alheia'], other: true);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Minha'));
        $this->assertFalse($names->contains('Alheia'));
    }

    public function test_moves_lead_to_visit_stage(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'visit'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'visit');
        $this->assertSame('visit', $lead->fresh()->status);
    }

    public function test_negotiation_stage_is_accepted(): void
    {
        $lead = $this->lead(['status' => 'qualified']);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'negotiation'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'negotiation');
    }

    public function test_moving_to_lost_requires_reason(): void
    {
        $lead = $this->lead(['status' => 'negotiation']);

        // Sem motivo → 422 (não perder sem motivo).
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'lost'])
            ->assertStatus(422);
        $this->assertSame('negotiation', $lead->fresh()->status);

        // Com motivo válido → 200 + guarda motivo + closed_at.
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'lost', 'lost_reason' => 'preco'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'lost')
            ->assertJsonPath('data.lost_reason', 'preco');
        $fresh = $lead->fresh();
        $this->assertSame('preco', $fresh->lost_reason);
        $this->assertNotNull($fresh->closed_at);
    }

    public function test_invalid_loss_reason_is_rejected(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'lost', 'lost_reason' => 'inventado'])
            ->assertStatus(422);
    }

    public function test_moving_out_of_lost_clears_reason(): void
    {
        $lead = $this->lead(['status' => 'lost', 'lost_reason' => 'preco', 'closed_at' => now()]);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'contacted'])
            ->assertStatus(200)
            ->assertJsonPath('data.lost_reason', null);
        $this->assertNull($lead->fresh()->lost_reason);
    }

    public function test_invalid_status_rejected(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($lead->id), ['status' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_cannot_index_other_company(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->url(companyId: $this->other->id))->assertStatus(403);
    }

    public function test_cannot_update_other_company_lead(): void
    {
        // Camada 2: lead da empresa B pela rota da empresa A → 404 (findScoped).
        $alien = $this->lead([], other: true);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($alien->id), ['status' => 'contacted'])
            ->assertStatus(404);
        $this->assertSame('new', $alien->fresh()->status);
    }
}
