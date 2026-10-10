<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ACL (F4): o /my-access devolve os módulos e as permissões efetivas (as do Access), com os motivos. */
class MyAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500500001', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
    }

    public function test_the_user_gets_the_effective_permissions_and_the_reasons(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$this->client->id}/my-access")->assertOk();
        $this->assertTrue($res->json('data.permissions')['blog.criar']);
        $this->assertFalse($res->json('data.permissions')['integracoes.configurar']);
        $this->assertSame('O seu perfil não permite configurar em Integrações.', $res->json('data.reasons')['integracoes.configurar']);
        $this->assertSame('own', $res->json('data.kind'));
        $this->assertSame('Utilizador (como hoje)', $res->json('data.profile.name'));
        $this->assertIsArray($res->json('data.modules'));
    }

    public function test_the_agency_sees_its_ceiling_and_the_agency_admin_flag(): void
    {
        $plan = DB::table('plans')->value('id');
        $agency = Company::create(['nipc' => '500500002', 'fiscal_name' => 'Agência Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);
        $admin = User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']);

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$this->client->id}/my-access")->assertOk();
        $this->assertSame('agency', $res->json('data.kind'));
        $this->assertTrue($res->json('data.permissions')['editorial.editar']);
        $this->assertFalse($res->json('data.permissions')['editorial.aprovar']);
        $this->assertFalse($res->json('data.permissions')['faturacao_xplendor.ver']);
        $this->assertTrue($res->json('data.agency_admin'));
    }

    public function test_another_company_gets_403(): void
    {
        $plan = DB::table('plans')->value('id');
        $other = Company::create(['nipc' => '500500003', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $user = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$this->client->id}/my-access")->assertStatus(403);
    }
}
