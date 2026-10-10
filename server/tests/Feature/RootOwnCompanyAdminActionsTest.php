<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\ImpersonationSession;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ações "só admin" e o root: o root conta como admin da SUA empresa (company_id do
 * root) para gerir os acessos dos colaboradores e aprovar artigos do blog; noutras
 * empresas continua sem poder (os acessos e as aprovações são do cliente). Ligar e
 * desligar as redes sociais e os anúncios faz-se com o próprio login: o root passa
 * sempre, em qualquer empresa (gestão por agências, F1a). Em impersonation estas ações
 * ficam sempre bloqueadas. Desligar os anúncios retira só
 * a permissão ads_read e mantém a ligação das redes sociais.
 */
class RootOwnCompanyAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $x;   // a empresa do root (XPLENDOR)
    private Company $b;   // outra empresa
    private User $root;
    private User $adminX;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Http::fake(fn (HttpRequest $r) => Http::response(['success' => true]));
        config(['services.meta.app_id' => '111', 'services.meta.app_secret' => 'segredo',
            'services.meta.redirect_uri' => 'https://dev.exemplo.pt/api/oauth/meta/callback']);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->x = Company::create(['nipc' => '500050001', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500050002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->x->id, 'role' => 'root']);
        $this->adminX = User::factory()->create(['company_id' => $this->x->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);

        foreach ([$this->x, $this->b] as $c) {
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'meta', 'access_token' => 'EAAads-' . $c->id, 'account_id' => '123', 'status' => 'active']);
            SocialConnection::create(['company_id' => $c->id, 'access_token' => 'EAAsocial-' . $c->id, 'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE]);
        }
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** Sessão de impersonation do root sobre um utilizador (o token é do alvo). */
    private function impersonating(User $target): self
    {
        $this->app['auth']->forgetGuards();
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $nt->plainTextToken, 'Accept' => 'application/json']);
    }

    /** As ações "só admin" numa empresa: [nome, método, url, corpo]. */
    private function actions(Company $c): array
    {
        $base = "/api/v1/companies/{$c->id}";
        $collaborator = Collaborator::create(['company_id' => $c->id, 'name' => 'Ana Martins', 'role_title' => 'Comercial']);
        $blogId = DB::table('blogs')->insertGetId([
            'company_id' => $c->id, 'user_id' => User::factory()->create(['company_id' => $c->id])->id, 'title' => 'Dicas de inverno',
            'slug' => 'dicas-' . uniqid(), 'content' => '<h2>Antes</h2><p>' . trim(str_repeat('ação ', 60)) . '</p>', 'status' => 'in_review',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            ['ligar as redes sociais', 'GET', "{$base}/integrations/social/auth-url", [], 'integration'],
            ['desligar as redes sociais', 'DELETE', "{$base}/integrations/social", [], 'integration'],
            ['ligar os anúncios', 'GET', "{$base}/integrations/meta/oauth-url", [], 'integration'],
            ['escolher a conta de anúncios', 'PATCH', "{$base}/integrations/meta/account", ['account_id' => 'act_456'], 'integration'],
            ['desligar os anúncios', 'DELETE', "{$base}/integrations/meta", [], 'integration'],
            ['convidar um colaborador', 'POST', "{$base}/collaborators/{$collaborator->id}/access", ['email' => 'ana.' . $c->id . '@exemplo.pt'], 'client'],
            ['aprovar um artigo', 'POST', "{$base}/blogs/{$blogId}/approve", [], 'decisao'],
        ];
    }

    /** ACL, D1: na própria empresa o root conta como administrador, também nas decisões (aprovar os artigos). */
    public function test_root_acts_as_admin_of_its_own_company(): void
    {
        foreach ($this->actions($this->x) as [$name, $method, $url, $body]) {
            $status = $this->as($this->root)->json($method, $url, $body)->status();
            $this->assertSame(200, $status, "O root na própria empresa deve poder: {$name}.");
        }
        // As ações tiveram efeito na empresa do root.
        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->x->id)->value('status'));
        $this->assertSame('published', DB::table('blogs')->where('company_id', $this->x->id)->value('status'));
        // E nada mudou na outra empresa.
        $this->assertSame('active', CompanyIntegration::where('company_id', $this->b->id)->value('status'));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $this->b->id)->value('status'));
    }

    /** ACL, D1: noutra empresa, o root gere os acessos, mas as decisões continuam a ser do cliente. */
    public function test_root_cannot_do_admin_actions_in_another_company(): void
    {
        foreach ($this->actions($this->b) as [$name, $method, $url, $body, $kind]) {
            if ($kind === 'integration') {
                continue; // ver test_root_configures_integrations_in_any_company
            }
            $status = $this->as($this->root)->json($method, $url, $body)->status();
            $this->assertSame($kind === 'decisao' ? 403 : 200, $status, "O root noutra empresa: {$name}.");
        }
        $this->assertSame('active', CompanyIntegration::where('company_id', $this->b->id)->value('status'));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $this->b->id)->value('status'));
        $this->assertSame('in_review', DB::table('blogs')->where('company_id', $this->b->id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_root_configures_integrations_in_any_company(): void
    {
        // Com o próprio login, sem impersonation: o root passa sempre (decisão da F1a).
        $this->as($this->root)->getJson("/api/v1/companies/{$this->b->id}/integrations/meta/oauth-url")->assertOk();
        $this->as($this->root)->getJson("/api/v1/companies/{$this->b->id}/integrations/social/auth-url")->assertOk();
        $this->assertTrue($this->as($this->root)->getJson("/api/v1/companies/{$this->b->id}/integrations/social")->json('data.can_manage'));
    }

    public function test_admin_actions_are_blocked_in_impersonation_even_in_the_root_company(): void
    {
        foreach ([$this->adminX, $this->adminB] as $target) {
            foreach ($this->actions(Company::find($target->company_id)) as [$name, $method, $url, $body]) {
                $status = $this->impersonating($target)->json($method, $url, $body)->status();
                $this->assertSame(403, $status, "Em impersonation não se pode: {$name}.");
            }
        }
        $this->assertSame(2, CompanyIntegration::where('status', 'active')->count());
        $this->assertSame(2, SocialConnection::where('status', SocialConnection::STATUS_ACTIVE)->count());
        $this->assertSame(0, DB::table('blogs')->where('status', 'published')->count());
        Http::assertNothingSent();
    }

    public function test_regular_users_still_cannot_do_admin_actions(): void
    {
        $user = User::factory()->create(['company_id' => $this->b->id, 'role' => 'user']);
        foreach ($this->actions($this->b) as [$name, $method, $url, $body]) {
            $this->assertSame(403, $this->as($user)->json($method, $url, $body)->status(), "Um utilizador sem perfil de admin não pode: {$name}.");
        }
        // O admin da empresa continua a poder.
        $this->assertSame(200, $this->as($this->adminB)->getJson("/api/v1/companies/{$this->b->id}/integrations/meta/oauth-url")->status());
    }

    public function test_disconnecting_ads_removes_only_ads_read_and_keeps_the_social_connection(): void
    {
        $this->as($this->adminB)->deleteJson("/api/v1/companies/{$this->b->id}/integrations/meta")
            ->assertOk()->assertJsonPath('data.permissions_revoked', true);

        $deletes = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE');
        $this->assertSame(['/v25.0/me/permissions/ads_read'], $deletes->map(fn ($p) => parse_url($p[0]->url(), PHP_URL_PATH))->values()->all(),
            'Nunca DELETE /me/permissions sem nome: desligaria também as redes sociais.');
        $this->assertStringContainsString('access_token=EAAads-' . $this->b->id, $deletes->first()[0]->url());

        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->b->id)->value('status'));
        $social = SocialConnection::where('company_id', $this->b->id)->first();
        $this->assertSame(SocialConnection::STATUS_ACTIVE, $social->status);
        $this->assertSame('EAAsocial-' . $this->b->id, $social->access_token);
    }
}
