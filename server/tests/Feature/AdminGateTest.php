<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS — Portão do super-admin (fundação da consola de administração).
 * A peça de RISCO: só root passa o grupo /api/v1/admin. Testa os 4 papéis.
 */
class AdminGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000300', 'fiscal_name' => 'Stand Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
    }

    public function test_root_reaches_admin_ping(): void
    {
        $root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/ping')
            ->assertStatus(200)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.role', 'root');
    }

    public function test_root_without_company_reaches_admin_ping(): void
    {
        // Super-admin "puro" (sem empresa) — company_id nullable.
        $root = User::factory()->create(['company_id' => null, 'role' => 'root']);
        $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/ping')->assertStatus(200);
    }

    public function test_admin_of_stand_is_forbidden(): void
    {
        $admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/ping')->assertStatus(403);
    }

    public function test_user_is_forbidden(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/ping')->assertStatus(403);
    }

    public function test_unauthenticated_is_unauthorized(): void
    {
        $this->getJson('/api/v1/admin/ping')->assertStatus(401);
    }
}
