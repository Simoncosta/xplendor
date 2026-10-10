<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarAiAnalysis;
use App\Models\CarmineConnection;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\CarAiAnalysesService;
use App\Services\CarAnalyticsService;
use App\Services\CompanyModuleService;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HOTFIX DE TENANCY — "provar primeiro".
 *
 * Cada teste descreve o comportamento SEGURO: um utilizador da empresa A não
 * consegue ler/alterar/apagar recursos da empresa B (nem pelo {id} da rota, nem
 * passando ids de B debaixo do seu próprio {id}). Os testes "legítimos" garantem
 * que o próprio, o admin, o root e a impersonation continuam a funcionar.
 */
class TenancyHotfixTest extends TestCase
{
    use RefreshDatabase;

    private int $planId;
    private Company $companyA;
    private Company $companyB;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $userB;
    private User $root;
    private Car $carA;
    private Car $carB;

    protected function setUp(): void
    {
        parent::setUp();

        // Nada sai para a rede (OpenAI, Meta, Carmine) nem para filas/email.
        Http::fake(['*' => Http::response(['access_token' => 'evil-token', 'data' => []], 200)]);
        Queue::fake();
        Mail::fake();
        config([
            'services.meta.app_id' => 'app-id',
            'services.meta.app_secret' => 'app-secret',
            'services.meta.redirect_uri' => 'https://xplendor.test/api/oauth/meta/callback',
        ]);

        $this->planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyA = Company::create(['nipc' => '500009001', 'fiscal_name' => 'Stand A', 'plan_id' => $this->planId, 'subscription_status' => 'active']);
        $this->companyB = Company::create(['nipc' => '500009002', 'fiscal_name' => 'Stand B', 'plan_id' => $this->planId, 'subscription_status' => 'active']);

        app(CompanyModuleService::class)->applyPreset($this->companyA->id, 'automotive');
        app(CompanyModuleService::class)->applyPreset($this->companyB->id, 'automotive');

        $this->adminA = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'admin', 'password' => Hash::make('senha-admin-a')]);
        $this->userA  = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'user', 'password' => Hash::make('senha-user-a')]);
        $this->adminB = User::factory()->create(['company_id' => $this->companyB->id, 'role' => 'admin', 'password' => Hash::make('senha-admin-b')]);
        $this->userB  = User::factory()->create(['company_id' => $this->companyB->id, 'role' => 'user', 'password' => Hash::make('senha-user-b')]);
        $this->root   = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'root']);

        $this->carA = Car::factory()->create(['company_id' => $this->companyA->id]);
        $this->carB = Car::factory()->create(['company_id' => $this->companyB->id]);
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/hotfix-secret.txt'));
        File::delete(storage_path('app/public/hotfix-ok.txt'));

        parent::tearDown();
    }

    private function a(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}";
    }

    private function metaIntegrationForB(): CompanyIntegration
    {
        return CompanyIntegration::create([
            'company_id' => $this->companyB->id,
            'platform' => 'meta',
            'access_token' => 'token-original-b',
            'account_id' => '111',
            'status' => 'active',
            'token_expires_at' => now()->addDays(30),
        ]);
    }

    // ── 1. Middleware tenant: {id} da rota de outra empresa ───────────────────

    public function test_dashboard_de_outra_empresa_e_negado(): void
    {
        // O repositório real usa SQL MySQL (DATEDIFF/NOW); aqui só interessa a porta.
        $this->mock(DashboardService::class, fn ($m) => $m->shouldReceive('getDashboard')->andReturn(['dados' => 'de B']));

        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyB->id) . '/dashboard')
            ->assertStatus(403);
    }

    public function test_ad_campaigns_de_outra_empresa_sao_negadas(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyB->id) . "/cars/{$this->carB->id}/ad-campaigns")
            ->assertStatus(403);
    }

    public function test_metricas_de_performance_de_outra_empresa_sao_negadas(): void
    {
        $base = $this->a($this->companyB->id) . "/cars/{$this->carB->id}/performance";
        $metricId = DB::table('car_performance_metrics')->insertGetId([
            'car_id' => $this->carB->id, 'company_id' => $this->companyB->id, 'channel' => 'paid',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->adminA, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson($base . '/summary')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson($base, [
            'channel' => 'paid', 'period_start' => '2026-10-01', 'period_end' => '2026-10-02', 'spend_amount' => 9999,
        ])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->putJson($base . "/{$metricId}", [
            'channel' => 'paid', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'spend_amount' => 9999,
        ])->assertStatus(403);

        $this->assertSame(1, DB::table('car_performance_metrics')->where('car_id', $this->carB->id)->count());
        $this->assertNotEquals(9999, (float) DB::table('car_performance_metrics')->where('id', $metricId)->value('spend_amount'));
    }

    public function test_potential_score_de_outra_empresa_e_negado(): void
    {
        DB::table('car_sale_potential_scores')->insert([
            'car_id' => $this->carB->id, 'company_id' => $this->companyB->id, 'score' => 77, 'classification' => 'hot',
            'score_breakdown' => '{}', 'days_in_stock_at_calc' => 10, 'calculated_at' => now(), 'triggered_by' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = $this->a($this->companyB->id) . "/cars/{$this->carB->id}/potential-score";

        $this->actingAs($this->adminA, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson($base . '/recalculate')->assertStatus(403);
    }

    public function test_car_analytics_de_outra_empresa_e_negado(): void
    {
        // O serviço real usa SQL MySQL (JSON_UNQUOTE); aqui só interessa quem passa a porta.
        $this->mock(CarAnalyticsService::class, fn ($m) => $m->shouldReceive('show')->andReturn(['dados' => 'de B']));

        // Pelo {id} de B…
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyB->id) . "/cars/{$this->carB->id}/analytics")
            ->assertStatus(403);

        // …e pela viatura de B debaixo do {id} de A.
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyA->id) . "/cars/{$this->carB->id}/analytics")
            ->assertStatus(404);
    }

    public function test_integracoes_de_outra_empresa_sao_negadas(): void
    {
        $integration = $this->metaIntegrationForB();
        $base = $this->a($this->companyB->id);

        $this->actingAs($this->adminA, 'sanctum')->getJson($base . '/integrations')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson($base . '/integrations/meta/adsets')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->patchJson($base . '/integrations/meta/account', ['account_id' => '999'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson($base . '/integrations/meta/connect', [
            'short_lived_token' => 'x', 'account_id' => '999',
        ])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($base . '/integrations/meta')->assertStatus(403);

        $integration->refresh();
        $this->assertSame('111', (string) $integration->account_id);
        $this->assertSame('active', $integration->status);
        $this->assertSame('token-original-b', $integration->access_token);
    }

    public function test_oauth_url_para_outra_empresa_e_negado(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyB->id) . '/integrations/meta/oauth-url')
            ->assertStatus(403);
    }

    public function test_sync_carmine_de_outra_empresa_e_negado(): void
    {
        CarmineConnection::create(['company_id' => $this->companyB->id, 'dealer_id' => 'D-B', 'token' => 'T-B']);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->a($this->companyB->id) . '/carmine-connection/sync')
            ->assertStatus(403);
    }

    // ── 2. Recursos filhos de B debaixo do {id} de A ──────────────────────────

    public function test_ver_viatura_de_outra_empresa_e_negado(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyA->id) . "/cars/{$this->carB->id}")
            ->assertStatus(404);
    }

    public function test_atualizar_viatura_de_outra_empresa_nao_a_move_de_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/cars/{$this->carB->id}", ['status' => 'draft', 'vehicle_type' => 'car'])
            ->assertStatus(404);

        $this->assertDatabaseHas('cars', ['id' => $this->carB->id, 'company_id' => $this->companyB->id, 'status' => 'active']);
    }

    public function test_apagar_viatura_de_outra_empresa_e_negado(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->a($this->companyA->id) . "/cars/{$this->carB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('cars', ['id' => $this->carB->id]);
    }

    public function test_analise_ia_de_outra_empresa_e_negada(): void
    {
        // Sem chamada real à OpenAI: se a porta deixar passar, o "gerar" devolve isto.
        $this->partialMock(CarAiAnalysesService::class, fn ($m) => $m->shouldReceive('generate')->andReturn(new CarAiAnalysis(['analysis' => 'de B'])));

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->a($this->companyB->id) . "/car-ai-analyses/{$this->carB->id}")
            ->assertStatus(403);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->a($this->companyA->id) . "/car-ai-analyses/{$this->carB->id}")
            ->assertStatus(404);
    }

    public function test_feedback_de_analise_ia_de_outra_empresa_e_negado(): void
    {
        $analysisId = DB::table('car_ai_analyses')->insertGetId([
            'input_data' => '{"segredo":"B"}', 'analysis_raw' => '{}', 'analysis' => 'analise privada de B', 'status' => 'completed',
            'car_id' => $this->carB->id, 'company_id' => $this->companyB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/car-ai-analyses-feedback/{$analysisId}", ['feedback' => 'negative']);

        $response->assertStatus(404);
        $this->assertStringNotContainsString('analise privada de B', $response->getContent());
        $this->assertNull(DB::table('car_ai_analyses')->where('id', $analysisId)->value('feedback'));
    }

    public function test_ligacao_carmine_de_outra_empresa_nao_pode_ser_alterada_nem_apagada(): void
    {
        $connB = CarmineConnection::create(['company_id' => $this->companyB->id, 'dealer_id' => 'D-B', 'token' => 'T-B']);
        $url = $this->a($this->companyA->id) . "/carmine-connection/{$connB->id}";

        $this->actingAs($this->adminA, 'sanctum')->putJson($url, ['dealer_id' => 'D-ATACANTE', 'token' => 'T-ATACANTE'])->assertStatus(404);
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($url)->assertStatus(404);

        $this->assertDatabaseHas('carmine_connections', ['id' => $connB->id, 'company_id' => $this->companyB->id, 'dealer_id' => 'D-B']);
    }

    public function test_blog_de_outra_empresa_nao_pode_ser_lido_alterado_nem_apagado(): void
    {
        $blogId = DB::table('blogs')->insertGetId([
            'title' => 'Rascunho de B', 'slug' => 'rascunho-de-b', 'content' => 'conteudo privado de B', 'status' => 'draft',
            'user_id' => $this->adminB->id, 'company_id' => $this->companyB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = $this->a($this->companyA->id) . "/blogs/{$blogId}";

        $this->actingAs($this->adminA, 'sanctum')->getJson($url)->assertStatus(404);
        $this->actingAs($this->adminA, 'sanctum')->putJson($url, ['title' => 'Roubado', 'content' => 'x', 'status' => 'draft'])->assertStatus(404);
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($url)->assertStatus(404);

        $this->assertDatabaseHas('blogs', ['id' => $blogId, 'company_id' => $this->companyB->id, 'title' => 'Rascunho de B', 'deleted_at' => null]);
    }

    // ── 3. Críticos: utilizadores, empresas, planos ───────────────────────────

    public function test_nao_se_muda_a_password_de_um_utilizador_de_outra_empresa(): void
    {
        $payload = ['password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'];

        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->userB->id}", $payload)
            ->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyB->id) . "/users/{$this->userB->id}", $payload)
            ->assertStatus(403);

        $this->assertTrue(Hash::check('senha-user-b', $this->userB->fresh()->password));
    }

    public function test_nao_se_muda_a_password_do_root(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->root->id}", ['password' => 'tomada-123', 'password_confirmation' => 'tomada-123'])
            ->assertStatus(403);

        $this->assertFalse(Hash::check('tomada-123', $this->root->fresh()->password));
    }

    public function test_admin_nao_muda_a_password_de_outro_utilizador_da_mesma_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->userA->id}", ['password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])
            ->assertStatus(403);

        $this->assertTrue(Hash::check('senha-user-a', $this->userA->fresh()->password));
    }

    public function test_utilizador_normal_nao_edita_outro_utilizador(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->adminA->id}", ['name' => 'Renomeado'])
            ->assertStatus(403);

        $this->assertNotSame('Renomeado', $this->adminA->fresh()->name);
    }

    public function test_nao_se_convida_utilizador_para_outra_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->a($this->companyB->id) . '/users', ['name' => 'Intruso', 'email' => 'intruso@atacante.pt'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('user_invites', ['email' => 'intruso@atacante.pt']);
    }

    public function test_utilizador_normal_nao_convida_utilizadores(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson($this->a($this->companyA->id) . '/users', ['name' => 'Novo', 'email' => 'novo@a.pt'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('user_invites', ['email' => 'novo@a.pt']);
    }

    public function test_nao_se_altera_outra_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/v1/companies/{$this->companyB->id}", ['fiscal_name' => 'Sequestrada'])
            ->assertStatus(403);

        $this->assertDatabaseHas('companies', ['id' => $this->companyB->id, 'fiscal_name' => 'Stand B']);
    }

    public function test_utilizador_normal_nao_altera_a_propria_empresa(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->putJson("/api/v1/companies/{$this->companyA->id}", ['fiscal_name' => 'Alterada'])
            ->assertStatus(403);

        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id, 'fiscal_name' => 'Stand A']);
    }

    public function test_so_o_root_apaga_empresas(): void
    {
        $this->actingAs($this->adminA, 'sanctum')->deleteJson("/api/v1/companies/{$this->companyB->id}")->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->deleteJson("/api/v1/companies/{$this->companyA->id}")->assertStatus(403);

        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id]);
        $this->assertDatabaseHas('companies', ['id' => $this->companyB->id]);
    }

    public function test_so_o_root_gere_planos(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/plans', ['name' => 'Gratis', 'price' => 0, 'car_limit' => 9999])
            ->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/v1/plans/{$this->planId}", ['name' => 'Gratis', 'price' => 0, 'car_limit' => 9999])
            ->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/v1/plans/{$this->planId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('plans', ['id' => $this->planId, 'name' => 'P']);
        $this->assertDatabaseMissing('plans', ['name' => 'Gratis']);
    }

    // ── 4. OAuth Meta: state forjado no callback POST ─────────────────────────

    public function test_callback_oauth_com_state_forjado_nao_altera_outra_empresa(): void
    {
        $integration = $this->metaIntegrationForB();
        $forged = base64_encode(json_encode(['company_id' => $this->companyB->id]));

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/integrations/meta/callback', ['code' => 'x', 'state' => $forged, 'account_id' => '999'])
            ->assertStatus(422);

        $integration->refresh();
        $this->assertSame('token-original-b', $integration->access_token);
        $this->assertSame('111', (string) $integration->account_id);
    }

    // ── 5. Rota DELETE integrations/meta: bloqueada em impersonation ─────────

    public function test_desligar_meta_em_impersonation_e_bloqueado(): void
    {
        $integration = $this->metaIntegrationForB();

        $nt = $this->adminB->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create([
            'root_id' => $this->root->id, 'target_user_id' => $this->adminB->id,
            'company_id' => $this->companyB->id, 'token_id' => $nt->accessToken->getKey(),
            'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now(),
        ]);

        $this->deleteJson($this->a($this->companyB->id) . '/integrations/meta', [], ['Authorization' => 'Bearer ' . $nt->plainTextToken])
            ->assertStatus(403);

        $this->assertSame('active', $integration->fresh()->status);
    }

    // ── 6. /api/media: nada fora da pasta pública ─────────────────────────────

    public function test_media_nao_serve_ficheiros_fora_do_storage_publico(): void
    {
        File::put(storage_path('app/hotfix-secret.txt'), 'SEGREDO');

        $this->get('/api/media/..%2Fhotfix-secret.txt')->assertStatus(404);
        $this->get('/api/media/..%2F..%2Fapp%2Fhotfix-secret.txt')->assertStatus(404);
    }

    // ── 7. /register (só confirmar) ──────────────────────────────────────────

    public function test_register_publico_nao_cria_nada(): void
    {
        $response = $this->postJson('/api/v1/register', ['name' => 'Anonimo', 'email' => 'anonimo@x.pt']);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertDatabaseMissing('user_invites', ['email' => 'anonimo@x.pt']);
        $this->assertDatabaseMissing('users', ['email' => 'anonimo@x.pt']);
    }

    // ── 8. Legítimos: o próprio, o admin, o root e a impersonation ───────────

    public function test_admin_ve_e_edita_as_suas_viaturas(): void
    {
        $base = $this->a($this->companyA->id) . "/cars/{$this->carA->id}";

        $this->actingAs($this->adminA, 'sanctum')->getJson($base)->assertOk();
        $this->actingAs($this->adminA, 'sanctum')->putJson($base, ['status' => 'draft', 'vehicle_type' => 'car'])->assertOk();

        $this->assertDatabaseHas('cars', ['id' => $this->carA->id, 'company_id' => $this->companyA->id, 'status' => 'draft']);
    }

    public function test_admin_apaga_a_sua_viatura(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->a($this->companyA->id) . "/cars/{$this->carA->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cars', ['id' => $this->carA->id]);
    }

    public function test_utilizador_muda_a_propria_password(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->userA->id}", ['password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])
            ->assertOk();

        $this->assertTrue(Hash::check('nova-senha-123', $this->userA->fresh()->password));
    }

    public function test_admin_edita_o_nome_de_um_utilizador_da_sua_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson($this->a($this->companyA->id) . "/users/{$this->userA->id}", ['name' => 'Nome Novo'])
            ->assertOk();

        $this->assertSame('Nome Novo', $this->userA->fresh()->name);
    }

    public function test_admin_convida_utilizador_para_a_sua_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->a($this->companyA->id) . '/users', ['name' => 'Novo', 'email' => 'novo@a.pt'])
            ->assertOk();

        $this->assertDatabaseHas('user_invites', ['email' => 'novo@a.pt', 'company_id' => $this->companyA->id]);
    }

    public function test_admin_altera_a_propria_empresa(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/v1/companies/{$this->companyA->id}", ['fiscal_name' => 'Stand A Renovado'])
            ->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id, 'fiscal_name' => 'Stand A Renovado']);
    }

    public function test_root_acede_a_qualquer_empresa_e_gere_planos(): void
    {
        $this->actingAs($this->root, 'sanctum')
            ->getJson($this->a($this->companyB->id) . "/cars/{$this->carB->id}")
            ->assertOk();
        $this->actingAs($this->root, 'sanctum')
            ->getJson($this->a($this->companyB->id) . '/integrations')
            ->assertOk();
        $this->actingAs($this->root, 'sanctum')
            ->postJson('/api/v1/plans', ['name' => 'Pro', 'price' => 10, 'car_limit' => 50])
            ->assertOk();
    }

    public function test_root_apaga_empresa(): void
    {
        $companyC = Company::create(['nipc' => '500009003', 'fiscal_name' => 'Stand C', 'plan_id' => $this->planId, 'subscription_status' => 'active']);

        $this->actingAs($this->root, 'sanctum')->deleteJson("/api/v1/companies/{$companyC->id}")->assertOk();

        $this->assertSoftDeleted('companies', ['id' => $companyC->id]);
    }

    public function test_impersonation_fica_limitada_a_empresa_do_alvo(): void
    {
        $nt = $this->userB->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create([
            'root_id' => $this->root->id, 'target_user_id' => $this->userB->id,
            'company_id' => $this->companyB->id, 'token_id' => $nt->accessToken->getKey(),
            'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now(),
        ]);
        $headers = ['Authorization' => 'Bearer ' . $nt->plainTextToken];

        $this->getJson($this->a($this->companyB->id) . "/cars/{$this->carB->id}", $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->a($this->companyA->id) . "/cars/{$this->carA->id}", $headers)->assertStatus(403);
    }

    public function test_callback_oauth_post_com_nonce_valido_liga_a_propria_empresa(): void
    {
        $url = $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->a($this->companyA->id) . '/integrations/meta/oauth-url')
            ->assertOk()
            ->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/integrations/meta/callback', ['code' => 'x', 'state' => $query['state'], 'account_id' => '222'])
            ->assertOk();

        $this->assertDatabaseHas('company_integrations', ['company_id' => $this->companyA->id, 'platform' => 'meta', 'account_id' => '222']);
    }

    /** Pré-deploy, ponto 5: a rota só serve imagens de viaturas (a do editor), nada mais do disco público. */
    public function test_media_continua_a_servir_ficheiros_publicos(): void
    {
        File::ensureDirectoryExists(storage_path('app/public/company_1/cars/carro-1/images'));
        File::put(storage_path('app/public/company_1/cars/carro-1/images/1.webp'), 'WEBP');
        File::put(storage_path('app/public/hotfix-ok.txt'), 'PUBLICO');

        $this->get('/api/media/company_1/cars/carro-1/images/1.webp')->assertOk();
        $this->get('/api/media/hotfix-ok.txt')->assertNotFound();
        File::deleteDirectory(storage_path('app/public/company_1/cars/carro-1'));
        File::delete(storage_path('app/public/hotfix-ok.txt'));
    }
}
