<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\Decision;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ACL: o Access decide, com o motivo, nos casos que a fotografia não cobre. */
class AccessDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Company $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500200001', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = Company::create(['nipc' => '500200002', 'fiscal_name' => 'Agência Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
    }

    private function access(): Access
    {
        return app(Access::class);
    }

    private function relation(string $origin = 'platform'): void
    {
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => $origin,
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);
    }

    public function test_unknown_permission_and_other_company_are_refused_with_a_reason(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->assertSame(Decision::UNKNOWN, $this->access()->can($user, $this->client->id, 'editorial.voar')->code);
        $other = $this->access()->can($user, $this->agency->id, 'editorial.ver');
        $this->assertTrue($other->denied());
        $this->assertSame(Decision::TENANT, $other->code);
    }

    public function test_the_approver_column_adds_editorial_approval_to_the_user_profile(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $this->assertTrue($this->access()->can($user, $this->client->id, 'editorial.aprovar')->denied());
        $user->forceFill(['can_approve_content' => true])->save();
        $this->assertTrue($this->access()->can($user->fresh(), $this->client->id, 'editorial.aprovar')->allowed);
        $this->assertTrue($this->access()->can($user->fresh(), $this->client->id, 'blog.aprovar')->denied());
    }

    public function test_only_root_gets_the_platform_area(): void
    {
        $admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $root = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'root']);
        $this->assertSame(Decision::PLATFORM, $this->access()->can($admin, $this->client->id, 'plataforma.configurar')->code);
        $this->assertTrue($this->access()->can($root, $this->client->id, 'plataforma.configurar')->allowed);
    }

    public function test_the_agency_never_takes_client_decisions_and_the_reason_says_so(): void
    {
        $this->relation();
        $member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $d = $this->access()->can($member, $this->client->id, 'editorial.aprovar');
        $this->assertSame(Decision::CLIENT_DECISION, $d->code);
        $this->assertStringContainsString('decisão é do cliente', (string) $d->reason);
        $this->assertTrue($this->access()->can($member, $this->client->id, 'editorial.editar')->allowed);
    }

    public function test_the_agency_member_does_not_configure_integrations_but_the_agency_admin_does(): void
    {
        $this->relation();
        $member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $this->assertSame(Decision::PROFILE, $this->access()->can($member, $this->client->id, 'integracoes.configurar')->code);
        $this->assertTrue($this->access()->can($admin, $this->client->id, 'integracoes.configurar')->allowed);
        $ceiling = $this->access()->can($admin, $this->client->id, 'faturacao_xplendor.ver');
        $this->assertSame(Decision::CEILING, $ceiling->code);
        $this->assertStringContainsString('Cliente Lda não deu acesso a esta agência', (string) $ceiling->reason);
    }

    public function test_the_agency_edits_the_basics_of_a_company_it_created_while_there_is_no_admin(): void
    {
        $this->relation(CompanyManagement::ORIGIN_CREATED_BY_AGENCY);
        $member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->assertTrue($this->access()->can($member, $this->client->id, 'empresa.editar')->allowed);

        User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->assertTrue((new Access(app(\App\Services\Tenancy\CompanyAccess::class), app(\App\Services\CompanyModuleService::class)))
            ->can($member, $this->client->id, 'empresa.editar')->denied());
    }
}
