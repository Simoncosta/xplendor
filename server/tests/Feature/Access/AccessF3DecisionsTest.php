<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ACL, F3: o middleware bloqueia com um só formato de 403 (com motivo), e as decisões
 * explícitas D1, D6, D7 e D8 (documents/acl/F3-DECISOES.md).
 */
class AccessF3DecisionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        Queue::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Company::create(['nipc' => '500400001', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500400002', 'fiscal_name' => 'Plataforma Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->modules($this->client, ModuleRegistry::keys());
    }

    private function modules(Company $c, array $keys): void
    {
        CompanyModule::where('company_id', $c->id)->delete();
        foreach ($keys as $k) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => $k]);
        }
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->client->id}/{$suffix}";
    }

    public function test_a_denied_permission_gives_one_403_format_with_the_reason(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);

        $this->actingAs($user, 'sanctum')->putJson($this->url('brand-profile'), ['tone_of_voice' => 'Calmo'])
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'O seu perfil não permite editar em Marca.', 'reason' => 'perfil', 'errors' => null]);
    }

    public function test_d1_root_passes_everything_except_the_client_decisions(): void
    {
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $post = EditorialPost::create(['company_id' => $this->client->id, 'publish_date' => now()->addDays(4)->toDateString(), 'title' => 'Menu',
            'format' => 'Carrossel', 'channel' => 'instagram', 'media_format' => 'ig_carousel', 'stage' => EditorialPost::STAGE_CLIENT_REVIEW]);

        $this->actingAs($root, 'sanctum')->postJson($this->url('users'), ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.pt'])
            ->assertSuccessful();
        $this->actingAs($root, 'sanctum')->postJson($this->url("editorial/posts/{$post->id}/approve"))
            ->assertStatus(403)->assertJsonPath('reason', 'decisao_do_cliente');
    }

    public function test_d1_in_its_own_company_root_counts_as_administrator_also_in_client_decisions(): void
    {
        $root = User::factory()->create(['company_id' => $this->client->id, 'role' => 'root']);
        $access = app(\App\Access\Access::class);
        foreach (['editorial.aprovar', 'blog.aprovar', 'faturacao_xplendor.aprovar', 'empresa.aprovar'] as $p) {
            $this->assertTrue($access->can($root, $this->client->id, $p)->allowed, "{$p} na própria empresa");
            $this->assertTrue($access->can($root, $this->other->id, $p)->denied(), "{$p} noutra empresa");
        }
    }

    public function test_d6_the_content_approver_also_approves_the_blog(): void
    {
        $author = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $approver = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user', 'can_approve_content' => true]);
        $blog = DB::table('blogs')->insertGetId(['company_id' => $this->client->id, 'user_id' => $author->id, 'title' => 'Artigo', 'slug' => 'artigo',
            'content' => '<p>Texto</p>', 'status' => 'in_review', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($author, 'sanctum')->postJson($this->url("blogs/{$blog}/approve"))->assertStatus(403);
        $this->actingAs($approver, 'sanctum')->postJson($this->url("blogs/{$blog}/approve"))->assertSuccessful();
    }

    public function test_d7_only_the_administrator_connects_integrations(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);

        foreach (['integrations/pingwin/connect', 'integrations/covermanager/connect', 'integrations/google/connect', 'carmine-connection'] as $path) {
            $this->actingAs($user, 'sanctum')->postJson($this->url($path), [])->assertStatus(403)->assertJsonPath('reason', 'perfil');
            $this->assertNotSame(403, $this->actingAs($admin, 'sanctum')->postJson($this->url($path), [])->getStatusCode(), $path);
        }
    }

    public function test_d8_the_restaurant_sections_support_and_meta_are_checked_in_the_backend(): void
    {
        $admin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->modules($this->client, ['pingwin', 'linha_editorial']); // sem secções, sem suporte nem análise de marketing

        foreach (['integrations/pingwin/locations', 'integrations/pingwin/catalog', 'ocr/invoices', 'integrations/pingwin/units', 'analytics/pingwin/calendar',
            'tasks', 'analytics/meta/overview'] as $path) {
            $this->actingAs($admin, 'sanctum')->getJson($this->url($path))->assertStatus(403)->assertJsonPath('reason', 'modulo');
        }
        // As partilhadas e as do módulo principal continuam; o suporte com a XPLENDOR é base.
        foreach (['integrations/pingwin/suppliers', 'integrations/covermanager', 'analytics/ga4/traffic', 'marketing/bussola', 'support-tickets', 'support-tickets/quotes'] as $path) {
            $this->assertNotSame(403, $this->actingAs($admin, 'sanctum')->getJson($this->url($path))->getStatusCode(), $path);
        }

        $this->modules($this->client, ['pingwin', 'restauracao_lojas', 'support_tasks', 'marketing_analytics']);
        foreach (['integrations/pingwin/locations', 'tasks', 'analytics/meta/overview'] as $path) {
            $this->assertNotSame(403, $this->actingAs($admin, 'sanctum')->getJson($this->url($path))->getStatusCode(), $path);
        }
    }

    public function test_root_is_not_filtered_by_modules_as_today(): void
    {
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $this->modules($this->client, []);

        $this->assertNotSame(403, $this->actingAs($root, 'sanctum')->getJson($this->url('tasks'))->getStatusCode());
    }
}
