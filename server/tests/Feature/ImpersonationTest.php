<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * XPLENDOR — IMPERSONATION (fatia sensível). Prova que a SEGURANÇA está no BACKEND:
 * só root inicia; o token autentica COMO o alvo; ações sensíveis são bloqueadas no servidor;
 * sair revoga o token. Bate nas rotas HTTP reais com tokens Sanctum (Bearer).
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $target;
    private User $otherRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $companyA = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Empresa Root', 'plan_id' => $planId]);
        $companyB = Company::create(['nipc' => '500000002', 'fiscal_name' => 'Empresa Cliente', 'plan_id' => $planId]);

        $this->root = User::create(['name' => 'Dona', 'email' => 'root@x.pt', 'password' => Hash::make('x'), 'role' => 'root', 'company_id' => $companyA->id]);
        $this->target = User::create(['name' => 'Matilde', 'email' => 'matilde@x.pt', 'password' => Hash::make('x'), 'role' => 'user', 'company_id' => $companyB->id]);
        $this->otherRoot = User::create(['name' => 'Outro Root', 'email' => 'root2@x.pt', 'password' => Hash::make('x'), 'role' => 'root', 'company_id' => $companyA->id]);
    }

    private function tokenFor(User $u): string
    {
        return $u->createToken('login')->plainTextToken;
    }

    /** Header de auth EXPLÍCITO por request (evita o estado pegajoso do withToken). */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_nao_root_nao_pode_iniciar_impersonation(): void
    {
        // ⚠️ SEGURANÇA: um user normal a chamar o start → 403 (ensure_super_admin).
        $this->postJson('/api/v1/admin/impersonation/start', ['user_id' => $this->target->id], $this->auth($this->tokenFor($this->target)))
            ->assertStatus(403);

        $this->assertDatabaseCount('impersonation_sessions', 0);
    }

    public function test_root_inicia_e_cria_sessao(): void
    {
        $res = $this->postJson('/api/v1/admin/impersonation/start', ['user_id' => $this->target->id], $this->auth($this->tokenFor($this->root)))
            ->assertOk();

        $res->assertJsonPath('data.user.id', $this->target->id);
        $res->assertJsonPath('data.impersonator.id', $this->root->id);
        $this->assertNotEmpty($res->json('data.token'));

        $this->assertDatabaseHas('impersonation_sessions', [
            'root_id' => $this->root->id, 'target_user_id' => $this->target->id,
            'company_id' => $this->target->company_id, 'ended_at' => null,
        ]);
    }

    public function test_nao_impersona_outro_root(): void
    {
        $this->postJson('/api/v1/admin/impersonation/start', ['user_id' => $this->otherRoot->id], $this->auth($this->tokenFor($this->root)))
            ->assertStatus(422);
    }

    public function test_token_de_impersonation_autentica_como_alvo(): void
    {
        // Token criado diretamente (como o start faz) → 1 só request autenticado (evita a
        // memoização do guard entre requests no mesmo teste).
        $token = $this->makeImpersonationToken();

        $res = $this->getJson('/api/v1/impersonation/current', $this->auth($token))->assertOk();
        $res->assertJsonPath('data.impersonating', true);
        $res->assertJsonPath('data.user.id', $this->target->id);
        $res->assertJsonPath('data.impersonator.id', $this->root->id);
    }

    public function test_acao_sensivel_bloqueada_no_backend(): void
    {
        $token = $this->makeImpersonationToken();

        // revoke-tokens está marcada block_when_impersonating → 403 (não confia no frontend).
        $this->postJson('/api/v1/revoke-tokens', [], $this->auth($token))->assertStatus(403);
    }

    public function test_sair_termina_sessao_e_revoga_token(): void
    {
        [$token, $tokenId] = $this->makeImpersonationToken(true);

        $this->postJson('/api/v1/impersonation/stop', [], $this->auth($token))->assertOk();

        // Sessão terminada + token revogado (sem 2.º request autenticado).
        $this->assertDatabaseMissing('impersonation_sessions', ['ended_at' => null]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_current_falso_para_token_normal(): void
    {
        $this->getJson('/api/v1/impersonation/current', $this->auth($this->tokenFor($this->target)))->assertOk()
            ->assertJsonPath('data.impersonating', false);
    }

    public function test_root_lista_empresas(): void
    {
        $res = $this->getJson('/api/v1/admin/companies', $this->auth($this->tokenFor($this->root)))->assertOk();
        $names = collect($res->json('data.companies'))->pluck('name')->all();
        $this->assertContains('Empresa Cliente', $names);
        $this->assertContains('Empresa Root', $names);
        // nº de utilizadores presente.
        $this->assertNotNull($res->json('data.companies.0.users_count'));
    }

    public function test_root_lista_users_de_uma_empresa(): void
    {
        $res = $this->getJson('/api/v1/admin/companies/' . $this->target->company_id . '/users', $this->auth($this->tokenFor($this->root)))->assertOk();
        $ids = collect($res->json('data.users'))->pluck('id')->all();
        $this->assertContains($this->target->id, $ids);
    }

    public function test_nao_root_nao_acede_admin_empresas(): void
    {
        $this->getJson('/api/v1/admin/companies', $this->auth($this->tokenFor($this->target)))->assertStatus(403);
        $this->getJson('/api/v1/admin/companies/' . $this->target->company_id . '/users', $this->auth($this->tokenFor($this->target)))->assertStatus(403);
    }

    public function test_platform_summary_root(): void
    {
        $res = $this->getJson('/api/v1/admin/platform/summary', $this->auth($this->tokenFor($this->root)))->assertOk();
        // 3 users criados no setUp (root, target, otherRoot); 0 carros.
        $this->assertSame(3, $res->json('data.users_total'));
        $this->assertSame(0, $res->json('data.cars_total'));
    }

    public function test_platform_summary_nao_root_403(): void
    {
        $this->getJson('/api/v1/admin/platform/summary', $this->auth($this->tokenFor($this->target)))->assertStatus(403);
    }

    /** Cria o token de impersonation + a sessão (como o endpoint start), sem HTTP. */
    private function makeImpersonationToken(bool $withId = false)
    {
        $nt = $this->target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create([
            'root_id' => $this->root->id, 'target_user_id' => $this->target->id,
            'company_id' => $this->target->company_id, 'token_id' => $nt->accessToken->getKey(),
            'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now(),
        ]);
        return $withId ? [$nt->plainTextToken, $nt->accessToken->getKey()] : $nt->plainTextToken;
    }
}
