<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\InviteToRegisterMail;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyDepartment;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Colaboradores e departamentos: tenancy, permissões (conteúdo vs acessos),
 * impersonation, RGPD, fotos, convite (link, reconvite, token inválido, user-by-invite,
 * accepted_at, fila), retirar e repor acesso, e a migração dos utilizadores existentes.
 */
class CollaboratorTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500017001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500017002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $this->a->id, 'role' => 'root']);
    }

    private function url(Company $c, string $suffix = ''): string
    {
        return "/api/v1/companies/{$c->id}/collaborators{$suffix}";
    }

    private function collaborator(Company $c, array $extra = []): Collaborator
    {
        return Collaborator::create(array_merge(['company_id' => $c->id, 'name' => 'Ana Martins', 'role_title' => 'Comercial'], $extra));
    }

    /** Token de impersonation do root sobre um utilizador (o token é do alvo). */
    private function impersonationHeaders(User $target): array
    {
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return ['Authorization' => 'Bearer ' . $nt->plainTextToken, 'Accept' => 'application/json'];
    }

    // ── Conteúdo e tenancy ───────────────────────────────────────────────────

    public function test_admin_creates_and_edits_collaborator_of_own_company(): void
    {
        $dept = CompanyDepartment::create(['company_id' => $this->a->id, 'name' => 'Oficina']);
        $c = $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a), [
            'name' => 'Rui Silva', 'role_title' => 'Mecânico', 'department_id' => $dept->id, 'phone' => '22 998 4130', 'phone_type' => 'fixed',
        ])->assertStatus(201)->assertJsonPath('data.department.name', 'Oficina')->assertJsonPath('data.access_status', 'none')->json('data');

        $this->actingAs($this->adminA, 'sanctum')->patchJson($this->url($this->a, "/{$c['id']}"), ['bio' => 'Doze anos de oficina.'])
            ->assertOk()->assertJsonPath('data.bio', 'Doze anos de oficina.');
        Mail::assertNothingQueued();   // sem "criar acesso", nada de convites
    }

    public function test_common_user_reads_but_cannot_edit(): void
    {
        $c = $this->collaborator($this->a);

        $this->actingAs($this->userA, 'sanctum')->getJson($this->url($this->a))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userA, 'sanctum')->patchJson($this->url($this->a, "/{$c->id}"), ['name' => 'X'])->assertStatus(403);
        $this->actingAs($this->userA, 'sanctum')->postJson($this->url($this->a), ['name' => 'X'])->assertStatus(403);
    }

    public function test_company_b_cannot_see_or_touch_company_a(): void
    {
        $c = $this->collaborator($this->a);
        $as = $this->actingAs($this->adminB, 'sanctum');

        $as->getJson($this->url($this->a))->assertStatus(403);
        $as->patchJson($this->url($this->a, "/{$c->id}"), ['name' => 'X'])->assertStatus(403);
        // Pelo caminho da própria empresa, o colaborador da A não existe.
        $as->getJson($this->url($this->b, "/{$c->id}"))->assertStatus(404);
        $as->patchJson($this->url($this->b, "/{$c->id}"), ['name' => 'X'])->assertStatus(404);
        $as->postJson($this->url($this->b, "/{$c->id}/access"), ['email' => 'x@exemplo.pt'])->assertStatus(404);
        $this->assertSame('Ana Martins', $c->fresh()->name);
    }

    public function test_department_of_another_company_cannot_be_assigned(): void
    {
        $deptB = CompanyDepartment::create(['company_id' => $this->b->id, 'name' => 'Oficina B']);

        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a), ['name' => 'Rui', 'department_id' => $deptB->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->actingAs($this->adminA, 'sanctum')->patchJson("/api/v1/companies/{$this->a->id}/departments/{$deptB->id}", ['name' => 'X'])->assertStatus(404);
    }

    public function test_rgpd_site_and_personal_contact_need_recorded_consent(): void
    {
        $c = $this->collaborator($this->a);
        $as = $this->actingAs($this->adminA, 'sanctum');

        $as->patchJson($this->url($this->a, "/{$c->id}"), ['show_on_site' => true])->assertStatus(422)->assertJsonValidationErrors('show_on_site');
        $as->patchJson($this->url($this->a, "/{$c->id}"), ['contact_mode' => 'personal'])->assertStatus(422)->assertJsonValidationErrors('contact_mode');

        $as->patchJson($this->url($this->a, "/{$c->id}"), ['publish_consent' => true, 'show_on_site' => true])
            ->assertOk()->assertJsonPath('data.on_site', true);
        $fresh = $c->fresh();
        $this->assertNotNull($fresh->publish_consent_at);
        $this->assertSame($this->adminA->id, $fresh->publish_consent_by_user_id);

        // Retirar a autorização tira a pessoa do site.
        $as->patchJson($this->url($this->a, "/{$c->id}"), ['publish_consent' => false, 'show_on_site' => false])->assertOk()->assertJsonPath('data.on_site', false);
        $this->assertNull($c->fresh()->publish_consent_at);
    }

    public function test_photo_is_resized_to_400_webp_and_original_is_not_kept(): void
    {
        $c = $this->collaborator($this->a);
        $file = UploadedFile::fake()->image('retrato.jpg', 1200, 1600);

        $res = $this->actingAs($this->adminA, 'sanctum')->post($this->url($this->a, "/{$c->id}/photo"), ['photo' => $file], ['Accept' => 'application/json'])->assertOk();

        $path = $c->fresh()->photo_path;
        $this->assertStringEndsWith('.webp', $path);
        $this->assertStringStartsWith("company_{$this->a->id}/team/", $path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 400], [$w, $h]);
        $this->assertCount(1, Storage::disk('public')->allFiles("company_{$this->a->id}"));   // só a WebP
        $this->assertStringContainsString($path, $res->json('data.photo_url'));
    }

    public function test_deactivate_leaves_site_and_delete_requires_no_account(): void
    {
        $c = $this->collaborator($this->a, ['publish_consent_at' => now(), 'show_on_site' => true]);
        $as = $this->actingAs($this->adminA, 'sanctum');

        $as->postJson($this->url($this->a, "/{$c->id}/deactivate"))->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.on_site', false);
        $as->postJson($this->url($this->a, "/{$c->id}/activate"))->assertOk()->assertJsonPath('data.on_site', true);

        $linked = $this->collaborator($this->a, ['user_id' => $this->userA->id, 'name' => 'Com conta']);
        $as->deleteJson($this->url($this->a, "/{$linked->id}"))->assertStatus(422);
        $as->deleteJson($this->url($this->a, "/{$c->id}"))->assertOk();
        $this->assertSoftDeleted('collaborators', ['id' => $c->id]);
    }

    public function test_suggested_departments_are_created_once(): void
    {
        $as = $this->actingAs($this->adminA, 'sanctum');
        $as->postJson("/api/v1/companies/{$this->a->id}/departments/suggested")->assertOk()->assertJsonCount(4, 'data');
        $as->postJson("/api/v1/companies/{$this->a->id}/departments/suggested")->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(['Comercial', 'Oficina', 'Pós-Venda', 'Admin'], CompanyDepartment::where('company_id', $this->a->id)->orderBy('sort')->pluck('name')->all());
    }

    // ── Impersonation ────────────────────────────────────────────────────────

    public function test_impersonation_lists_and_edits_content_but_never_touches_accounts(): void
    {
        $c = $this->collaborator($this->a);
        $h = $this->impersonationHeaders($this->adminA);

        $this->getJson($this->url($this->a), $h)->assertOk();
        $this->getJson("/api/v1/companies/{$this->a->id}/users", $h)->assertOk();   // seletor "Vendedor"
        $this->patchJson($this->url($this->a, "/{$c->id}"), ['role_title' => 'Gerente'], $h)->assertOk();
        $this->postJson("/api/v1/companies/{$this->a->id}/departments", ['name' => 'Comercial'], $h)->assertStatus(201);

        $this->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'], $h)->assertStatus(403);
        $this->postJson($this->url($this->a, "/{$c->id}/access/resend"), [], $h)->assertStatus(403);
        $this->deleteJson($this->url($this->a, "/{$c->id}/access/invite"), [], $h)->assertStatus(403);
        $this->postJson($this->url($this->a, "/{$c->id}/access/revoke"), [], $h)->assertStatus(403);
        $this->postJson($this->url($this->a), ['name' => 'Novo', 'create_access' => true, 'access_email' => 'novo@exemplo.pt'], $h)->assertStatus(403);
        $this->postJson("/api/v1/companies/{$this->a->id}/users", ['name' => 'X', 'email' => 'x@exemplo.pt'], $h)->assertStatus(403);

        $this->assertSame(0, UserInvite::count());
        $this->assertSame(1, Collaborator::count());   // o "Novo" com acesso não foi criado
        Mail::assertNothingQueued();
    }

    // ── Convite e acessos ────────────────────────────────────────────────────

    public function test_only_own_company_admin_grants_access(): void
    {
        $c = $this->collaborator($this->a);
        // ACL, D1: o root (mesmo de outra empresa) passa em todas as permissões que não são
        // decisões do cliente, incluindo gerir os acessos; um utilizador comum, não.
        $otherRoot = User::factory()->create(['company_id' => $this->b->id, 'role' => 'root']);

        $this->actingAs($this->userA, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'])->assertStatus(403);
        $this->assertSame(0, UserInvite::count());
        $this->actingAs($otherRoot, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'])->assertOk();
        $this->assertSame(1, UserInvite::count());
    }

    public function test_create_with_access_sends_queued_invite_to_app_register_with_user_role(): void
    {
        $res = $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a), [
            'name' => 'Ana Martins', 'create_access' => true, 'access_email' => 'ana@exemplo.pt',
        ])->assertStatus(201)->assertJsonPath('data.access_status', 'invited')->assertJsonPath('data.invite.email', 'ana@exemplo.pt');

        $invite = UserInvite::sole();
        $this->assertSame(['user', $this->a->id, $res->json('data.id')], [$invite->role, $invite->company_id, $invite->collaborator_id]);
        Mail::assertQueued(InviteToRegisterMail::class, fn ($m) => $m->hasTo('ana@exemplo.pt')
            && str_ends_with((string) parse_url($m->inviteUrl, PHP_URL_PATH), '/app/register')
            && str_ends_with($m->inviteUrl, '/app/register?token=' . $invite->token)
            && $m->companyName === 'Quebom');
    }

    public function test_reinvite_reuses_the_pending_invite_and_cancel_removes_it(): void
    {
        $c = $this->collaborator($this->a);
        $as = $this->actingAs($this->adminA, 'sanctum');
        $as->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'])->assertOk();
        $firstToken = UserInvite::sole()->token;

        $as->postJson($this->url($this->a, "/{$c->id}/access/resend"))->assertOk();
        $as->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'])->assertOk();   // não rebenta no índice único
        $this->assertSame(1, UserInvite::count());
        $this->assertNotSame($firstToken, UserInvite::sole()->token);
        Mail::assertQueued(InviteToRegisterMail::class, 3);

        $as->deleteJson($this->url($this->a, "/{$c->id}/access/invite"))->assertOk()->assertJsonPath('data.access_status', 'none');
        $this->assertSame(0, UserInvite::count());
    }

    public function test_accepting_links_the_account_to_the_collaborator_and_records_accepted_at(): void
    {
        $c = $this->collaborator($this->a);
        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access"), ['email' => 'ana@exemplo.pt'])->assertOk();
        $token = UserInvite::sole()->token;

        // A página de registo só recebe o mínimo.
        $info = $this->getJson("/api/v1/user-by-invite/{$token}")->assertOk()->json('data');
        $this->assertSame(['company_name', 'email', 'expires_at', 'name'], collect($info)->keys()->sort()->values()->all());
        $this->assertSame('Quebom', $info['company_name']);

        $this->postJson('/api/v1/register-by-invite', ['token' => $token, 'password' => 'Segredo123', 'password_confirmation' => 'Segredo123'])->assertOk();

        $user = User::where('email', 'ana@exemplo.pt')->sole();
        $this->assertSame(['user', $this->a->id], [$user->role, $user->company_id]);
        $this->assertNotNull($user->accepted_at);
        $this->assertNotNull(UserInvite::sole()->accepted_at);
        $this->assertSame($user->id, $c->fresh()->user_id);

        // O convite usado já não serve.
        $this->getJson("/api/v1/user-by-invite/{$token}")->assertStatus(410);
        $this->postJson('/api/v1/register-by-invite', ['token' => $token, 'password' => 'Segredo123', 'password_confirmation' => 'Segredo123'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_invalid_or_expired_invite_gets_a_clear_message(): void
    {
        $this->postJson('/api/v1/register-by-invite', ['token' => 'nao-existe', 'password' => 'Segredo123', 'password_confirmation' => 'Segredo123'])
            ->assertStatus(422)->assertJsonFragment(['Este convite é inválido, já foi usado ou expirou. Peça um novo convite ao administrador da empresa.']);
        $this->getJson('/api/v1/user-by-invite/nao-existe')->assertStatus(404);

        $expired = UserInvite::create(['company_id' => $this->a->id, 'email' => 'velho@exemplo.pt', 'name' => 'Velho', 'role' => 'user', 'token' => 'tok-expirado', 'expires_at' => now()->subDay()]);
        $this->getJson("/api/v1/user-by-invite/{$expired->token}")->assertStatus(410);
    }

    public function test_revoke_blocks_login_and_sessions_and_restore_gives_it_back(): void
    {
        $c = $this->collaborator($this->a, ['user_id' => $this->userA->id]);
        $this->userA->forceFill(['password' => bcrypt('Segredo123')])->save();
        $this->userA->createToken('sessao');

        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access/revoke"))->assertOk()->assertJsonPath('data.access_status', 'revoked');
        $this->assertNotNull($this->userA->fresh()->deactivated_at);
        $this->assertSame(0, $this->userA->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $this->userA->email, 'password' => 'Segredo123'])->assertStatus(422)->assertJsonValidationErrors('email');
        // Fora do seletor "Vendedor".
        $this->assertNotContains($this->userA->id, array_column($this->actingAs($this->adminA, 'sanctum')->getJson("/api/v1/companies/{$this->a->id}/users")->json('data'), 'id'));

        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access/restore"))->assertOk()->assertJsonPath('data.access_status', 'active');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $this->userA->email, 'password' => 'Segredo123'])->assertOk();
    }

    public function test_admin_cannot_revoke_own_access(): void
    {
        $c = $this->collaborator($this->a, ['user_id' => $this->adminA->id]);
        $this->actingAs($this->adminA, 'sanctum')->postJson($this->url($this->a, "/{$c->id}/access/revoke"))->assertStatus(422);
        $this->assertNull($this->adminA->fresh()->deactivated_at);
    }

    public function test_users_delete_route_no_longer_exists(): void
    {
        $this->actingAs($this->adminA, 'sanctum')->deleteJson("/api/v1/companies/{$this->a->id}/users/{$this->userA->id}")->assertStatus(405);
        $this->assertNotNull($this->userA->fresh());
    }

    // ── Migração dos utilizadores existentes ─────────────────────────────────

    public function test_existing_users_become_hidden_collaborators_without_consent(): void
    {
        $migration = require database_path('migrations/2026_11_11_100100_create_collaborators_for_existing_users.php');
        $migration->up();
        $migration->up();   // idempotente

        $this->assertSame(3, Collaborator::count());   // adminA, userA, adminB (o root não)
        $c = Collaborator::where('user_id', $this->userA->id)->sole();
        $this->assertSame([$this->a->id, false, null], [$c->company_id, $c->show_on_site, $c->publish_consent_at]);
        $this->assertFalse(Collaborator::where('user_id', $this->root->id)->exists());
    }
}
