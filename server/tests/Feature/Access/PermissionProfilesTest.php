<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\ProfileSuggestions;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\PermissionProfileEvent;
use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ACL, F5: Utilizadores › Perfis. D12 (sempre um administrador ativo; o Administrador não se
 * edita), D13 (perfis a partir de sugestões, com pré-visualização), o teto da agência (D2),
 * o Criativo externo só nos clientes atribuídos (D11) e a Agência convidada (D14).
 */
class PermissionProfilesTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500600001', 'fiscal_name' => 'Yuko Teste Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'name' => 'Ana Admin']);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/companies/{$this->client->id}/{$suffix}";
    }

    private function suggestion(string $key): PermissionProfile
    {
        return PermissionProfile::where('system_key', $key)->firstOrFail();
    }

    private function createFrom(string $key, string $name, ?array $permissions = null): array
    {
        $s = $this->suggestion($key);

        return $this->actingAs($this->admin, 'sanctum')->postJson($this->url('permission-profiles'), [
            'name' => $name, 'side' => 'cliente', 'from_profile_id' => $s->id, 'permissions' => $permissions ?? $s->permissionKeys(),
        ])->assertOk()->json('data');
    }

    public function test_the_list_has_the_system_profiles_the_suggestions_and_a_plain_summary(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('permission-profiles'))->assertOk();
        $names = collect($res->json('data.profiles'))->pluck('name')->all();
        foreach (['Administrador', 'Utilizador (como hoje)', 'Marketing', 'Financeiro', 'Só leitura', 'Agência convidada'] as $n) {
            $this->assertContains($n, $names);
        }
        $readOnly = collect($res->json('data.profiles'))->firstWhere('name', 'Só leitura');
        $this->assertTrue($readOnly['is_suggestion']);
        $this->assertFalse($readOnly['assignable'], 'a sugestão nunca é imposta: copia-se primeiro');
        $finance = collect($readOnly['summary'])->firstWhere('area', 'financas');
        $this->assertSame('Não vê.', $finance['text']);
        $editorial = collect($readOnly['summary'])->firstWhere('area', 'editorial');
        $this->assertSame('Pode ver; não pode criar, editar, aprovar, apagar e configurar.', $editorial['text']);
        $this->assertTrue($res->json('data.can_manage'));
    }

    public function test_d9_read_only_suggestion_has_exactly_the_decided_areas(): void
    {
        $this->assertSame(
            ['blog.ver', 'bussola.ver', 'editorial.ver', 'empresa.ver', 'marca.ver', 'resultados.ver', 'suporte.ver', 'tarefas.ver'],
            $this->suggestion(ProfileSuggestions::READ_ONLY)->permissionKeys(),
        );
    }

    public function test_d13_a_profile_starts_from_a_suggestion_and_everything_is_editable(): void
    {
        // Uma permissão que não se pode dar (a da plataforma) é recusada na validação.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('permission-profiles'), [
            'name' => 'Com plataforma', 'side' => 'cliente', 'permissions' => ['editorial.ver', 'plataforma.configurar'],
        ])->assertStatus(422);

        $data = $this->createFrom(ProfileSuggestions::MARKETING, 'Marketing da casa', ['editorial.ver', 'editorial.criar', 'blog.ver', 'agencia.configurar']);

        $this->assertSame(['blog.ver', 'editorial.criar', 'editorial.ver'], $data['permissions'], 'editado antes de gravar; as ações da agência não entram num perfil do cliente');
        $this->assertTrue($data['editable']);
        $this->assertSame(1, PermissionProfileEvent::where('event', 'perfil_criado')->count());

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("permission-profiles/{$data['id']}"), ['permissions' => ['editorial.ver']])
            ->assertOk()->assertJsonPath('data.permissions', ['editorial.ver']);
        $this->assertEqualsCanonicalizing(['editorial.criar', 'blog.ver'], PermissionProfileEvent::where('event', 'perfil_alterado')->first()->payload['retiradas']);
    }

    public function test_the_preview_shows_the_effective_permissions_before_saving(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('permission-profiles/preview'), [
            'side' => 'teto', 'permissions' => ['editorial.ver', 'editorial.aprovar', 'financas.ver'],
        ])->assertOk();

        $this->assertSame(['editorial.ver', 'financas.ver'], $res->json('data.effective'));
        $this->assertSame(['aprovar em Linha Editorial'], $res->json('data.ignored'), 'a agência nunca aprova');
        $this->assertContains('Linha Editorial', $res->json('data.inactive_modules'));
        $this->assertNotNull($res->json('data.note'));
    }

    public function test_d12_the_administrator_profile_is_a_system_profile_and_cannot_be_edited(): void
    {
        $adminProfile = PermissionProfile::system(PermissionProfile::ADMIN);

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("permission-profiles/{$adminProfile->id}"), ['permissions' => []])
            ->assertStatus(422)->assertJsonPath('errors.profile.0', 'O perfil Administrador é de sistema e não se edita.');
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("permission-profiles/{$adminProfile->id}"))->assertStatus(422);
    }

    public function test_d12_the_last_active_administrator_keeps_the_profile_and_the_access(): void
    {
        $readOnly = $this->createFrom(ProfileSuggestions::READ_ONLY, 'Leitura');

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("users/{$this->admin->id}/profile"), ['profile_id' => $readOnly['id']])
            ->assertStatus(422)->assertJsonPath('errors.user.0', 'Nomeie outro administrador antes de retirar o perfil de Administrador a Ana Admin: cada empresa tem sempre pelo menos um administrador ativo.');

        // Também não se lhe retira o acesso (o mesmo que o desativar).
        $other = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'deactivated_at' => now()]);
        $c = Collaborator::create(['company_id' => $this->client->id, 'name' => 'Ana Admin', 'role_title' => 'Gerente', 'user_id' => $this->admin->id]);
        $this->actingAs($other->fresh(), 'sanctum'); // um admin desativado não conta
        $this->assertTrue(\App\Access\LastAdminGuard::isActiveAdmin($this->admin));
        try {
            app(\App\Services\CollaboratorService::class)->revokeAccess($c, User::factory()->create(['company_id' => $this->client->id, 'role' => 'root']));
            $this->fail('Devia recusar retirar o acesso ao último administrador.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Nomeie outro administrador antes de retirar o acesso a Ana Admin', $e->getMessage());
        }

        // Com outro administrador ativo, já se pode.
        $second = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->actingAs($second, 'sanctum')->putJson($this->url("users/{$this->admin->id}/profile"), ['profile_id' => $readOnly['id']])->assertOk()
            ->assertJsonPath('data.is_admin', false);
        $this->assertSame('user', $this->admin->fresh()->role, 'o papel antigo acompanha o perfil');
        $this->assertSame(1, PermissionProfileEvent::where('event', 'perfil_atribuido')->where('target_user_id', $this->admin->id)->count());
    }

    public function test_a_suggestion_cannot_be_assigned_directly_and_a_used_profile_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("users/{$user->id}/profile"), ['profile_id' => $this->suggestion(ProfileSuggestions::MARKETING)->id])
            ->assertStatus(422);

        $mk = $this->createFrom(ProfileSuggestions::MARKETING, 'Marketing');
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("users/{$user->id}/profile"), ['profile_id' => $mk['id']])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("permission-profiles/{$mk['id']}"))->assertStatus(422)
            ->assertJsonPath('errors.profile.0', 'Este perfil está atribuído a 1 pessoa: atribua-lhes outro perfil antes de o apagar.');
    }

    public function test_d14_a_guest_agency_user_of_the_client_has_exactly_the_decided_permissions(): void
    {
        $guest = $this->createFrom(ProfileSuggestions::GUEST_AGENCY, 'Media Tailors');
        $mt = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user', 'name' => 'Pessoa da Media Tailors']);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("users/{$mt->id}/profile"), ['profile_id' => $guest['id']])->assertOk();

        $access = app(Access::class);
        foreach (['editorial.ver', 'editorial.criar', 'editorial.editar', 'editorial.apagar', 'bussola.ver', 'bussola.criar', 'bussola.editar', 'resultados.ver'] as $p) {
            $this->assertTrue($access->can($mt->fresh(), $this->client->id, $p)->allowed, $p);
        }
        foreach (['editorial.aprovar', 'financas.ver', 'utilizadores.ver', 'integracoes.ver', 'integracoes.configurar', 'restauracao.ver', 'faturacao_xplendor.ver'] as $p) {
            $this->assertTrue($access->can($mt->fresh(), $this->client->id, $p)->denied(), $p);
        }
        // A base (ver a empresa, os avisos e o que pode fazer) está sempre: o ecrã abre.
        $this->assertTrue($access->can($mt->fresh(), $this->client->id, 'empresa.ver')->allowed);
        $this->assertTrue($this->actingAs($mt->fresh(), 'sanctum')->getJson($this->url('my-access'))->assertOk()->json('data.permissions')['editorial.ver']);
    }

    public function test_the_client_sets_the_ceiling_of_the_managing_agency_and_the_agency_never_does(): void
    {
        $agency = Company::create(['nipc' => '500600002', 'fiscal_name' => 'XPLENDOR Agência', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);
        $agencyAdmin = User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']);
        $ceiling = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('permission-profiles'), [
            'name' => 'Teto da agência', 'side' => 'teto', 'permissions' => ['editorial.ver', 'editorial.editar', 'resultados.ver'],
        ])->assertOk()->json('data');

        $this->actingAs($agencyAdmin, 'sanctum')->putJson($this->url('management/guest-profile'), ['profile_id' => $ceiling['id']])->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('management/guest-profile'), ['profile_id' => $ceiling['id']])->assertOk();

        $this->assertTrue(app(Access::class)->can($agencyAdmin, $this->client->id, 'editorial.editar')->allowed);
        $this->assertTrue(app(Access::class)->can($agencyAdmin, $this->client->id, 'blog.criar')->denied());
        $this->assertSame(1, PermissionProfileEvent::where('event', 'teto_da_agencia')->count());
    }

    public function test_d11_the_external_creative_only_sees_assigned_clients_even_with_team_scope_all(): void
    {
        $agency = Company::create(['nipc' => '500600003', 'fiscal_name' => 'Agência Norte', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        $m = CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);
        $agencyAdmin = User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']);
        $creative = User::factory()->create(['company_id' => $agency->id, 'role' => 'user']);

        $copy = $this->actingAs($agencyAdmin, 'sanctum')->postJson("/api/v1/companies/{$agency->id}/permission-profiles", [
            'name' => 'Criativo', 'side' => 'agencia', 'from_profile_id' => $this->suggestion(ProfileSuggestions::AGENCY_CREATIVE)->id,
            'permissions' => $this->suggestion(ProfileSuggestions::AGENCY_CREATIVE)->permissionKeys(),
        ])->assertOk()->json('data');
        $this->assertTrue($copy['only_assigned_clients']);
        $this->actingAs($agencyAdmin, 'sanctum')->putJson("/api/v1/companies/{$agency->id}/users/{$creative->id}/profile", ['profile_id' => $copy['id']])->assertOk();

        $this->app->instance('request', \Illuminate\Http\Request::create('/')); // sem a cache do pedido anterior
        $this->assertNull(app(CompanyAccess::class)->kind($creative->fresh(), $this->client->id), 'não atribuído: não vê o cliente');
        $m->members()->create(['user_id' => $creative->id]);
        $this->assertSame('agency', app(CompanyAccess::class)->kind($creative->fresh(), $this->client->id));
        $this->assertSame([$this->client->id], app(CompanyAccess::class)->managedCompanyIds($creative->fresh()));
    }
}
