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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Atribuição de leads: a coluna car_leads.assigned_user_id existe, a relação assignedUser
 * aponta para ela e, se o utilizador for apagado, a lead fica sem atribuição.
 */
class CarLeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $seller;
    private int $carId;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000900', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);

        $brand = CarBrand::firstOrCreate(['slug' => 'bmw'], ['name' => 'BMW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Serie 3', 'car_brand_id' => $brand->id]);
        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id])->id;
    }

    private function lead(array $extra = []): CarLead
    {
        return CarLead::create(array_merge([
            'name' => 'Cliente', 'email' => uniqid() . '@x.pt', 'phone' => '912345678',
            'status' => 'new', 'source' => 'website_form',
            'car_id' => $this->carId, 'company_id' => $this->company->id,
        ], $extra));
    }

    public function test_the_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('car_leads', 'assigned_user_id'));
    }

    public function test_a_lead_is_assigned_to_a_user(): void
    {
        $lead = $this->lead(['assigned_user_id' => $this->seller->id, 'assigned_at' => now()]);

        $this->assertSame($this->seller->id, $lead->fresh()->assigned_user_id);
        $this->assertTrue($lead->fresh()->assignedUser->is($this->seller));
    }

    public function test_a_lead_without_assignment_has_no_user(): void
    {
        $this->assertNull($this->lead()->fresh()->assignedUser);
    }

    public function test_deleting_the_user_leaves_the_lead_unassigned(): void
    {
        $lead = $this->lead(['assigned_user_id' => $this->seller->id]);

        $this->seller->forceDelete();

        $this->assertNotNull($lead->fresh(), 'A lead não é apagada com o utilizador.');
        $this->assertNull($lead->fresh()->assigned_user_id);
    }
}
