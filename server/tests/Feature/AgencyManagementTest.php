<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\ContentSector;
use App\Models\EditorialPost;
use App\Models\EditorialPostComment;
use App\Models\EditorialPostEvent;
use App\Models\ImpersonationSession;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Gestão de empresas por agências (F1a): a relação gere-se na plataforma (root), o admin
 * da empresa gerida vê e termina a relação, a agência trabalha com a própria identidade
 * (produz, liga integrações, vê resultados) e nunca aprova nem gere acessos do cliente; o
 * cliente aprova pelo link; uma empresa gerida nunca fica bloqueada pelo período de teste.
 */
class AgencyManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $xplendor;
    private Company $agency;
    private Company $client;
    private User $root;
    private User $member;
    private User $agencyAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        Queue::fake();
        Mail::fake();
        Storage::fake('media');
        config(['services.meta.app_id' => 'app-id', 'services.meta.app_secret' => 'secret', 'services.meta.redirect_uri' => 'https://x.test/api/oauth/meta/callback']);

        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->xplendor = $this->company('XPLENDOR');
        $this->agency = $this->company('Agência Norte');
        $this->client = $this->company('Domiway');
        foreach (['linha_editorial', 'marketing_analytics'] as $k) {
            CompanyModule::firstOrCreate(['company_id' => $this->client->id, 'module_key' => $k]);
        }
        $this->root = User::factory()->create(['company_id' => $this->xplendor->id, 'role' => 'root', 'name' => 'Simon']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user', 'name' => 'Rita Agência']);
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
    }

    private function company(string $name, array $extra = []): Company
    {
        return Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan, 'subscription_status' => 'active'] + $extra);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(Company $c, string $suffix = ''): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    /** O root marca a agência e põe-na a gerir o cliente. */
    private function manage(?Company $client = null): void
    {
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->agency->id}/agency", ['enabled' => true])->assertOk();
        $this->as($this->root)->putJson('/api/v1/admin/companies/' . ($client ?? $this->client)->id . '/management', ['agency_company_id' => $this->agency->id])->assertOk();
    }

    // ── A relação na plataforma ──────────────────────────────────────────────

    public function test_only_the_root_marks_agencies_and_sets_the_managing_agency_with_history(): void
    {
        $this->as($this->agencyAdmin)->patchJson("/api/v1/admin/companies/{$this->agency->id}/agency", ['enabled' => true])->assertForbidden();
        // Só uma empresa marcada como agência pode gerir.
        $this->as($this->root)->putJson("/api/v1/admin/companies/{$this->client->id}/management", ['agency_company_id' => $this->agency->id])->assertStatus(422);

        $this->manage();
        $other = $this->company('Agência Sul');
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$other->id}/agency", ['enabled' => true, 'notification_email' => 'equipa@sul.pt'])->assertOk();
        // Mudar: termina a atual e cria outra.
        $this->as($this->root)->putJson("/api/v1/admin/companies/{$this->client->id}/management", ['agency_company_id' => $other->id])->assertOk();
        // Retirar.
        $r = $this->as($this->root)->putJson("/api/v1/admin/companies/{$this->client->id}/management", ['agency_company_id' => null])->assertOk();

        $this->assertNull($r->json('data.current'));
        $history = $r->json('data.history');
        $this->assertSame([['Agência Sul', 'ended', 'platform'], ['Agência Norte', 'ended', 'platform']],
            array_map(fn ($h) => [$h['agency']['name'], $h['status'], $h['ended_by_side']], $history));
        $this->assertSame(['Simon', 'Simon'], array_column($history, 'ended_by'));
        $this->assertSame(0, CompanyManagement::whereNotNull('active_key')->count());
    }

    public function test_one_active_agency_per_company_and_no_chains(): void
    {
        $this->manage();
        // Uma empresa gerida não pode ser agência; uma agência não pode ser gerida.
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->client->id}/agency", ['enabled' => true])->assertStatus(422);
        $this->as($this->root)->putJson("/api/v1/admin/companies/{$this->agency->id}/management", ['agency_company_id' => $this->agency->id])->assertStatus(422);
        // Desmarcar uma agência com clientes ativos é recusado.
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->agency->id}/agency", ['enabled' => false])->assertStatus(422);

        // A unicidade da relação ativa é da base de dados (active_key), não só da aplicação.
        $this->expectException(\Illuminate\Database\QueryException::class);
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id]);
    }

    public function test_root_creates_a_managed_company_without_users_nor_nipc_with_the_modules_of_its_branch(): void
    {
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->agency->id}/agency", ['enabled' => true])->assertOk();
        $r = $this->as($this->root)->postJson('/api/v1/companies', [
            'fiscal_name' => 'Casa Nova', 'lead_distribution' => 'manual', 'plan_id' => $this->plan, 'managed_by_company_id' => $this->agency->id,
        ])->assertOk();
        $new = Company::findOrFail($r->json('data.id'));

        $this->assertNull($new->nipc);
        $this->assertSame(0, User::where('company_id', $new->id)->count());
        $this->assertSame(0, UserInvite::where('company_id', $new->id)->count());
        $this->assertSame($this->agency->id, (int) $new->activeManagement->agency_company_id);
        $this->assertSame('platform', $new->activeManagement->origin);
        // Sem ramo: o preset base (com a Linha Editorial), não o automóvel.
        $this->assertEqualsCanonicalizing(['marketing_analytics', 'support_tasks', 'linha_editorial'], CompanyModule::where('company_id', $new->id)->pluck('module_key')->all());

        // Ramo automóvel: o preset automóvel. Sem agência, continua a exigir NIPC e o admin.
        $leaf = ContentSector::where('slug', 'carros')->firstOrFail(); // semeado pela migração (sob Automóvel)
        $this->as($this->root)->postJson('/api/v1/companies', ['fiscal_name' => 'Stand', 'lead_distribution' => 'manual', 'plan_id' => $this->plan])
            ->assertStatus(422)->assertJsonValidationErrors(['nipc', 'name_user', 'email_user']);
        $r = $this->as($this->root)->postJson('/api/v1/companies', ['fiscal_name' => 'Stand', 'nipc' => '509999999', 'lead_distribution' => 'manual',
            'plan_id' => $this->plan, 'content_sector_id' => $leaf->id, 'name_user' => 'Rui', 'email_user' => 'rui@stand.pt'])->assertOk();
        $this->assertContains('stock', CompanyModule::where('company_id', $r->json('data.id'))->pluck('module_key')->all());
    }

    public function test_the_client_admin_sees_the_agency_and_ends_the_relation_with_immediate_effect(): void
    {
        $this->manage();
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $clientUser = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $post = EditorialPost::create(['company_id' => $this->client->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Menu',
            'format' => 'Imagem', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);

        $seen = $this->as($clientUser)->getJson($this->url($this->client, '/management'))->assertOk();
        $this->assertSame(['Agência Norte', false], [$seen->json('data.agency.name'), $seen->json('data.can_end')]);
        $this->assertTrue($this->as($clientAdmin)->getJson($this->url($this->client, '/management'))->json('data.can_end'));
        $this->as($this->member)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertOk();

        $this->as($clientUser)->deleteJson($this->url($this->client, '/management'))->assertForbidden();
        $this->as($this->member)->deleteJson($this->url($this->client, '/management'))->assertForbidden();
        $this->as($clientAdmin)->deleteJson($this->url($this->client, '/management'), ['reason' => 'Mudámos de agência.'])->assertOk();

        // Acesso cortado de imediato; os dados ficam na empresa.
        $this->as($this->member)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertForbidden();
        $this->assertTrue(EditorialPost::whereKey($post->id)->exists());
        $m = CompanyManagement::where('managed_company_id', $this->client->id)->sole();
        $this->assertSame(['ended', 'company', $clientAdmin->id, 'Mudámos de agência.', null], [$m->status, $m->ended_by_side, $m->ended_by_user_id, $m->end_reason, $m->active_key]);
        $this->assertNull($this->as($clientAdmin)->getJson($this->url($this->client, '/management'))->json('data.agency'));
    }

    public function test_company_list_has_the_own_company_plus_the_managed_ones_and_all_for_the_root(): void
    {
        $this->manage();
        $ids = fn (User $u) => collect($this->as($u)->getJson('/api/v1/companies')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->agency->id, $this->client->id], $ids($this->member));
        $clientUser = User::factory()->create(['company_id' => $this->client->id]);
        $this->assertSame([$this->client->id], $ids($clientUser));
        $this->assertSame([$this->xplendor->id, $this->agency->id, $this->client->id], $ids($this->root));
    }

    // ── Subscrição ───────────────────────────────────────────────────────────

    public function test_a_managed_company_is_never_blocked_by_its_own_trial(): void
    {
        $this->client->forceFill(['subscription_status' => 'trial', 'trial_starts_at' => now()->subDays(40), 'trial_ends_at' => now()->subDays(10)])->save();
        $clientUser = User::factory()->create(['company_id' => $this->client->id]);
        $this->as($clientUser)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertForbidden();

        $this->manage();
        $this->assertTrue($this->client->fresh()->hasPlatformAccess());
        $this->as($clientUser)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertOk();
        $this->as($this->member)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertOk();
        $this->assertTrue(Company::active()->whereKey($this->client->id)->exists());

        // Quem paga é a agência: sem a subscrição dela, nem a agência nem o cliente gerido entram.
        $this->agency->forceFill(['subscription_status' => 'cancelled'])->save();
        $this->as($this->member)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertForbidden();
        $this->as($clientUser)->getJson($this->url($this->client, '/editorial/board?month=' . now()->format('Y-m')))->assertForbidden();
        $this->assertFalse(Company::active()->whereKey($this->client->id)->exists());
    }

    // ── A agência como equipa ────────────────────────────────────────────────

    public function test_the_agency_produces_in_team_mode_with_its_own_identity_and_never_approves(): void
    {
        $this->manage();
        $this->client->forceFill(['content_production_mode' => 'team'])->save();
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $p = EditorialPost::create(['company_id' => $this->client->id, 'publish_date' => now()->addDays(5)->toDateString(), 'title' => 'Menu',
            'format' => 'Imagem', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $base = $this->url($this->client, "/editorial/posts/{$p->id}");

        $this->as($this->member)->putJson("{$base}/content", ['caption' => 'Pela agência'])->assertOk();
        $this->as($this->member)->postJson("{$base}/comments", ['body' => 'Rever a cor.', 'visibility' => EditorialPostComment::INTERNAL])->assertOk();
        $this->as($this->member)->postJson("{$base}/move", ['stage' => 'client_review'])->assertOk();
        // O cliente gerido pela agência não produz; a agência nunca aprova.
        $this->as($clientAdmin)->putJson("{$base}/content", ['caption' => 'Pelo cliente'])->assertForbidden();
        $this->as($this->member)->postJson("{$base}/approve")->assertForbidden();

        $settings = $this->as($this->member)->getJson($this->url($this->client, '/editorial/workflow-settings'))->assertOk();
        $this->assertTrue($settings->json('data.can_change_mode'));
        $this->assertFalse($settings->json('data.can_manage_approvers'));
        $this->as($this->member)->putJson($this->url($this->client, '/editorial/workflow-settings'),
            ['content_approval_required' => true, 'internal_review_required' => false, 'production_mode' => 'self'])->assertOk();

        // O histórico regista a pessoa real e a empresa dela (a agência), sem impersonation.
        $events = EditorialPostEvent::where('editorial_post_id', $p->id)->get();
        $this->assertSame([$this->member->id], $events->pluck('user_id')->unique()->values()->all());
        $this->assertSame([null], $events->pluck('impersonator_user_id')->unique()->values()->all());
        $this->assertSame([$this->agency->id], $events->pluck('acting_company_id')->unique()->values()->all());
        // No histórico e nos comentários: "Rita Agência (Agência Norte)" (o nome da agência já começa por "Agência").
        $detail = $this->as($clientAdmin)->getJson("{$base}/workflow")->json('data');
        $this->assertSame(['Rita Agência (Agência Norte)'], array_values(array_unique(array_column($detail['events'], 'who'))));
        // O comentário interno só a equipa o vê.
        $this->assertCount(1, $this->as($this->member)->getJson("{$base}/workflow")->json('data.comments'));
        $this->assertCount(0, $this->as($clientAdmin)->getJson("{$base}/workflow")->json('data.comments'));
    }

    public function test_the_client_approves_through_the_link_sent_by_the_agency(): void
    {
        $this->manage();
        $p = EditorialPost::create(['company_id' => $this->client->id, 'publish_date' => now('Europe/Lisbon')->addDays(6)->toDateString(), 'title' => 'Menu',
            'format' => 'Imagem', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $base = $this->url($this->client, "/editorial/posts/{$p->id}");
        $dir = 'company_' . $this->client->id . '/a1';
        Storage::disk('media')->put("{$dir}/thumb.webp", 'x');
        $asset = MediaAsset::create(['company_id' => $this->client->id, 'kind' => 'image', 'disk' => 'media', 'dir' => $dir, 'original_name' => 'f.jpg', 'extension' => 'jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 1080, 'height' => 1350, 'sha256' => hash('sha256', $dir),
            'variants' => ['thumb' => 'thumb.webp', 'preview' => 'thumb.webp'], 'status' => MediaAsset::READY]);
        $this->as($this->member)->putJson("{$base}/content", ['caption' => 'Legenda', 'media_format' => 'ig_feed_image'])->assertOk();
        $this->as($this->member)->putJson("{$base}/media", ['items' => [$asset->id], 'cover_id' => null])->assertOk();
        $this->as($this->member)->postJson("{$base}/move", ['stage' => 'client_review'])->assertOk();

        $link = $this->as($this->member)->postJson($this->url($this->client, '/editorial/review-links'), ['title' => 'Semana 1', 'post_ids' => [$p->id]])->assertStatus(201)->json('data');
        $token = substr($link['url'], strrpos($link['url'], '#') + 1);
        $this->app['auth']->forgetGuards();
        $items = $this->withHeaders(['X-Review-Token' => $token])->getJson('/api/public/review')->assertOk()->json('data.items');
        $this->withHeaders(['X-Review-Token' => $token])->postJson("/api/public/review/items/{$items[0]['id']}/approve", ['name' => 'Cliente Domiway'])->assertOk();

        $this->assertSame(EditorialPost::STAGE_SCHEDULED, $p->fresh()->stage);
    }

    public function test_only_agency_admins_connect_integrations_with_their_own_login(): void
    {
        $this->manage();
        // Um ADMIN da agência liga e desliga (Meta, redes sociais, GA4)…
        $this->as($this->agencyAdmin)->getJson($this->url($this->client, '/integrations/meta/oauth-url'))->assertOk();
        $this->as($this->agencyAdmin)->getJson($this->url($this->client, '/integrations/social/auth-url'))->assertOk();
        $this->assertTrue($this->as($this->agencyAdmin)->getJson($this->url($this->client, '/integrations/social'))->json('data.can_manage'));
        $this->as($this->agencyAdmin)->postJson($this->url($this->client, '/integrations/google/connect'), ['property_id' => '398765432'])->assertOk();
        $this->as($this->agencyAdmin)->deleteJson($this->url($this->client, '/integrations/google'))->assertOk();

        // …um membro comum da agência produz, mas não liga integrações.
        $this->as($this->member)->getJson($this->url($this->client, '/integrations/meta/oauth-url'))->assertForbidden();
        $this->as($this->member)->getJson($this->url($this->client, '/integrations/social/auth-url'))->assertForbidden();
        $this->assertFalse($this->as($this->member)->getJson($this->url($this->client, '/integrations/social'))->json('data.can_manage'));
        $this->as($this->member)->postJson($this->url($this->client, '/integrations/google/connect'), ['property_id' => '398765432'])->assertForbidden();
        $this->as($this->member)->deleteJson($this->url($this->client, '/integrations/google'))->assertForbidden();

        // Os utilizadores do cliente seguem as regras da empresa (a Meta só o admin; o GA4 qualquer utilizador, como antes).
        $clientUser = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $this->as($clientUser)->getJson($this->url($this->client, '/integrations/meta/oauth-url'))->assertForbidden();
        $this->as($clientUser)->postJson($this->url($this->client, '/integrations/google/connect'), ['property_id' => '398765432'])->assertOk();
    }

    public function test_only_agency_admins_change_pingwin_covermanager_and_carmine_credentials(): void
    {
        $this->manage();
        foreach (['pingwin', 'stock'] as $k) {
            CompanyModule::firstOrCreate(['company_id' => $this->client->id, 'module_key' => $k]);
        }
        $this->as($this->member)->postJson($this->url($this->client, '/integrations/covermanager/connect'), ['token' => 'cm-token'])->assertForbidden();
        $this->as($this->member)->postJson($this->url($this->client, '/carmine-connection'), ['dealer_id' => 'D1'])->assertForbidden();
        $this->as($this->member)->postJson($this->url($this->client, '/integrations/pingwin/connect'), ['username' => 'u', 'database' => 'd', 'password' => 'p'])->assertForbidden();

        $this->as($this->agencyAdmin)->postJson($this->url($this->client, '/integrations/covermanager/connect'), ['token' => 'cm-token'])->assertOk();
        $this->as($this->member)->deleteJson($this->url($this->client, '/integrations/covermanager'))->assertForbidden();
        $this->as($this->agencyAdmin)->deleteJson($this->url($this->client, '/integrations/covermanager'))->assertOk();
        // Ler continua aberto a quem trabalha no cliente.
        $this->as($this->member)->getJson($this->url($this->client, '/integrations/covermanager'))->assertOk();
    }

    public function test_the_agency_edits_the_basic_data_only_of_companies_it_created_and_without_a_client_admin(): void
    {
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->agency->id}/agency", ['enabled' => true])->assertOk();
        // Relação nascida da criação pela agência (o fluxo de criar pela agência chega na F1b).
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'created_by_agency',
            'status' => 'active', 'active_key' => $this->client->id, 'requested_at' => now()]);
        $nipc = $this->client->nipc;

        $this->as($this->member)->putJson($this->url($this->client), [
            'fiscal_name' => 'Domiway Lda', 'phone' => '220000000', 'email' => 'geral@domiway.pt',
            'nipc' => '599999999', 'plan_id' => $this->plan, 'lead_distribution' => 'automatic_latest',
        ])->assertOk();
        $c = $this->client->fresh();
        $this->assertSame(['Domiway Lda', '220000000', 'geral@domiway.pt'], [$c->fiscal_name, $c->phone, $c->email]);
        // Só os dados básicos: o resto não se grava.
        $this->assertSame([$nipc, 'manual'], [$c->nipc, $c->lead_distribution]);
        // O ramo define-se uma vez; trocar de ramo é uma fase futura.
        $carros = ContentSector::where('slug', 'carros')->firstOrFail();
        $restauracao = ContentSector::where('slug', 'restauracao')->firstOrFail();
        $this->as($this->member)->putJson($this->url($this->client), ['content_sector_id' => $carros->id])->assertOk();
        $this->as($this->member)->putJson($this->url($this->client), ['content_sector_id' => $restauracao->id])->assertStatus(422);
        $this->assertSame($carros->id, (int) $this->client->fresh()->content_sector_id);
        // Apagar, nunca.
        $this->as($this->member)->deleteJson($this->url($this->client))->assertForbidden();

        // Com admin do cliente, só ele edita.
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->as($this->member)->putJson($this->url($this->client), ['fiscal_name' => 'Outro nome'])->assertForbidden();
        $this->as($clientAdmin)->putJson($this->url($this->client), ['fiscal_name' => 'Domiway SA'])->assertOk();
        $this->assertSame('Domiway SA', $this->client->fresh()->fiscal_name);
    }

    public function test_the_agency_does_not_edit_companies_it_did_not_create(): void
    {
        $this->manage(); // relação definida pela plataforma
        $this->as($this->member)->putJson($this->url($this->client), ['fiscal_name' => 'Outro nome'])->assertForbidden();
        $this->as($this->agencyAdmin)->putJson($this->url($this->client), ['fiscal_name' => 'Outro nome'])->assertForbidden();
        $this->assertSame('Domiway', $this->client->fresh()->fiscal_name);
    }

    public function test_the_agency_invites_only_the_first_admin_of_a_company_without_one(): void
    {
        $this->manage();
        $this->assertTrue($this->as($this->member)->getJson($this->url($this->client, '/management'))->json('data.can_invite_first_admin'));
        $this->as($this->member)->postJson($this->url($this->client, '/management/first-admin'), ['name' => 'Joana', 'email' => 'joana@domiway.pt'])->assertOk();
        $this->assertSame('admin', UserInvite::where('company_id', $this->client->id)->value('role'));
        // Já há um convite pendente: não convida outro; e nunca convida utilizadores comuns.
        $this->as($this->member)->postJson($this->url($this->client, '/management/first-admin'), ['name' => 'Rui', 'email' => 'rui@domiway.pt'])->assertStatus(422);
        $this->as($this->member)->postJson($this->url($this->client, '/users'), ['name' => 'Rui', 'email' => 'rui@domiway.pt'])->assertForbidden();
        // Fora da agência gestora (um utilizador do cliente), não.
        $clientUser = User::factory()->create(['company_id' => $this->client->id]);
        $this->as($clientUser)->postJson($this->url($this->client, '/management/first-admin'), ['name' => 'X', 'email' => 'x@domiway.pt'])->assertForbidden();
    }

    // ── Correções ────────────────────────────────────────────────────────────

    public function test_root_reads_users_of_any_company_and_audits_record_the_root_in_impersonation(): void
    {
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->as($this->root)->getJson($this->url($this->client, '/users'))->assertOk();
        $this->as($this->root)->getJson($this->url($this->client, "/users/{$clientAdmin->id}"))->assertOk();

        config(['audit.console' => true]);
        $this->app['auth']->forgetGuards();
        $nt = $clientAdmin->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $clientAdmin->id, 'company_id' => $this->client->id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $nt->plainTextToken])
            ->postJson($this->url($this->client, '/collaborators'), ['name' => 'Ana Martins', 'role_title' => 'Comercial'])->assertSuccessful();

        $audit = Audit::where('auditable_type', Collaborator::class)->latest('id')->firstOrFail();
        $this->assertSame($this->root->id, (int) $audit->user_id, 'Em impersonation, a auditoria regista a pessoa real (o root).');
    }
}
