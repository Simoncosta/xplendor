<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\CompatibilityMigration;
use App\Access\CompatibilityProfiles;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\PermissionProfileEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACL, F2: os dados de hoje passam para os perfis de compatibilidade sem mudar nenhum
 * acesso (a fotografia é a mesma: AccessSnapshotTest), e o perfil acompanha o papel.
 */
class CompatibilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Company $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500300001', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = Company::create(['nipc' => '500300002', 'fiscal_name' => 'Agência Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
    }

    public function test_the_system_profiles_have_exactly_the_derived_permissions(): void
    {
        foreach (CompatibilityMigration::SYSTEM as $key => [$derived]) {
            $profile = PermissionProfile::system($key);
            $this->assertNotNull($profile, $key);
            $this->assertTrue($profile->is_system);
            $expected = array_keys(CompatibilityProfiles::allowed($derived));
            sort($expected);
            $this->assertSame($expected, $profile->permissionKeys(), $key);
        }
    }

    public function test_new_users_and_relations_get_the_profile_of_their_role(): void
    {
        $admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $agencyMember = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $root = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'root']);
        $m = CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);

        $id = fn (string $k) => PermissionProfile::system($k)->id;
        $this->assertSame($id(PermissionProfile::ADMIN), $admin->profile_id);
        $this->assertSame($id(PermissionProfile::USER_COMPAT), $user->profile_id);
        $this->assertNull($admin->agency_profile_id);
        $this->assertSame($id(PermissionProfile::ADMIN), $agencyAdmin->profile_id);
        $this->assertSame($id(PermissionProfile::AGENCY_ADMIN_COMPAT), $agencyAdmin->agency_profile_id);
        $this->assertSame($id(PermissionProfile::AGENCY_MEMBER_COMPAT), $agencyMember->agency_profile_id);
        $this->assertNull($root->profile_id);
        $this->assertSame($id(PermissionProfile::CEILING_COMPAT), $m->guest_profile_id);
    }

    public function test_the_migration_fills_only_what_is_empty_and_is_idempotent(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $agencyMember = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        DB::table('users')->whereIn('id', [$user->id, $agencyMember->id])->update(['profile_id' => null, 'agency_profile_id' => null]);
        $custom = PermissionProfile::create(['company_id' => $this->client->id, 'side' => 'cliente', 'name' => 'Marketing da casa']);
        $kept = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user', 'profile_id' => $custom->id]);

        $first = CompatibilityMigration::run();
        $this->assertSame(2, $first['utilizadores']);
        $this->assertSame(1, $first['agencia']);
        $this->assertSame(PermissionProfile::system(PermissionProfile::USER_COMPAT)->id, $user->fresh()->profile_id);
        $this->assertSame(PermissionProfile::system(PermissionProfile::AGENCY_MEMBER_COMPAT)->id, $agencyMember->fresh()->agency_profile_id);
        $this->assertSame($custom->id, $kept->fresh()->profile_id);
        $this->assertSame(1, PermissionProfileEvent::where('event', 'migracao_compatibilidade')->count());

        $second = CompatibilityMigration::run();
        $this->assertSame(['perfis' => 5, 'utilizadores' => 0, 'agencia' => 0, 'relacoes' => 0], $second);
        $this->assertSame(1, PermissionProfileEvent::where('event', 'migracao_compatibilidade')->count());
    }

    public function test_a_role_change_moves_a_system_profile_but_never_a_custom_one(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $user->update(['role' => 'admin']);
        $this->assertSame(PermissionProfile::system(PermissionProfile::ADMIN)->id, $user->fresh()->profile_id);
        $user->update(['role' => 'user']);
        $this->assertSame(PermissionProfile::system(PermissionProfile::USER_COMPAT)->id, $user->fresh()->profile_id);

        $custom = PermissionProfile::create(['company_id' => $this->client->id, 'side' => 'cliente', 'name' => 'Só leitura da casa']);
        $user->forceFill(['profile_id' => $custom->id])->save();
        $user->update(['role' => 'admin']);
        $this->assertSame($custom->id, $user->fresh()->profile_id);
    }

    public function test_access_reads_the_profile_from_the_database(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $this->assertTrue(app(Access::class)->can($user, $this->client->id, 'blog.criar')->allowed);

        $custom = PermissionProfile::create(['company_id' => $this->client->id, 'side' => 'cliente', 'name' => 'Só Linha Editorial']);
        $custom->syncPermissions(['editorial.ver', 'editorial.criar', 'plataforma.configurar']);
        $user->forceFill(['profile_id' => $custom->id])->save();

        $this->assertTrue(app(Access::class)->can($user, $this->client->id, 'editorial.criar')->allowed);
        $this->assertTrue(app(Access::class)->can($user, $this->client->id, 'blog.criar')->denied());
        $this->assertSame(['editorial.criar', 'editorial.ver'], $custom->permissionKeys(), 'a plataforma nunca entra num perfil');
    }

    public function test_the_agency_gets_its_profile_intersected_with_the_ceiling_saved_on_the_relation(): void
    {
        $member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $ceiling = PermissionProfile::create(['company_id' => $this->client->id, 'side' => 'teto', 'name' => 'Teto da casa']);
        $ceiling->syncPermissions(['editorial.ver', 'editorial.editar', 'resultados.ver']);
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now(), 'guest_profile_id' => $ceiling->id]);

        $this->assertTrue(app(Access::class)->can($member, $this->client->id, 'editorial.editar')->allowed);
        $d = app(Access::class)->can($member, $this->client->id, 'financas.ver');
        $this->assertTrue($d->denied());
        $this->assertStringContainsString('Cliente Lda não deu acesso a esta agência', (string) $d->reason);
    }
}
