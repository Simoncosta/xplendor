<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ManagedCompanyRequestMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\ContentReviewLink;
use App\Models\ContentReviewLinkItem;
use App\Models\ContentSector;
use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\ManagedCompanyRequest;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Vista da agência (F1b): a Linha Editorial de todos os clientes que a pessoa vê e da
 * própria agência (só com o módulo ativo), o painel por cliente, as atribuições (só o
 * admin da agência; um cliente não atribuído não aparece em nada) e os pedidos de nova
 * empresa gerida (só o admin da agência pede; só o root aprova ou recusa). E o dashboard
 * base, bloco a bloco pelos módulos.
 */
class AgencyViewTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $agency;
    private Company $other;
    private User $root;
    private User $admin;
    private User $member;
    /** @var array<string, Company> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC')); // 11:00 em Lisboa
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);

        $xplendor = $this->company('XPLENDOR');
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root', 'email' => 'simon@xplendor.tech']);
        $this->agency = $this->company('Agência Norte', true);
        $this->other = $this->company('Agência Sul', true);
        $this->admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin', 'email' => 'ana@norte.pt']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);

        $this->c['domiway'] = $this->managed('Domiway', $this->agency);
        $this->c['quebom'] = $this->managed('Quebom', $this->agency, 'assigned'); // ninguém atribuído
        $this->c['antiga'] = $this->managed('Antiga', $this->agency, 'all', 'ended');
        $this->c['sem_modulo'] = $this->managed('Sem módulo', $this->agency, 'all', 'active', false);
        $this->c['alheia'] = $this->managed('Da Sul', $this->other);
        $this->c['solta'] = $this->company('Sem relação');
        foreach ($this->c + ['agencia' => $this->agency] as $key => $company) {
            $this->makePost($company, "{$key}: produção", 'production', '2026-10-20');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(string $name, bool $agency = false, bool $editorial = true): Company
    {
        $c = Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan, 'subscription_status' => 'active']);
        if ($agency) {
            $c->forceFill(['agency_enabled_at' => now()])->save();
        }
        if ($editorial) {
            CompanyModule::firstOrCreate(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
        }

        return $c;
    }

    private function managed(string $name, Company $agency, string $scope = 'all', string $status = 'active', bool $editorial = true): Company
    {
        $c = $this->company($name, false, $editorial);
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $c->id, 'origin' => 'platform', 'status' => $status,
            'active_key' => $status === 'active' ? $c->id : null, 'team_scope' => $scope, 'requested_at' => now()]);

        return $c;
    }

    private function makePost(Company $c, string $title, string $stage, string $date, ?string $time = null): EditorialPost
    {
        $p = EditorialPost::create(['company_id' => $c->id, 'publish_date' => $date, 'publish_time' => $time, 'title' => $title,
            'format' => 'Imagem', 'channel' => 'social', 'stage' => $stage]);
        $p->networks()->create(['company_id' => $c->id, 'network' => 'instagram', 'media_format' => 'ig_feed_image', 'position' => 0]);

        return $p;
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(string $suffix, ?Company $agency = null): string
    {
        return '/api/v1/agencies/' . ($agency ?? $this->agency)->id . $suffix;
    }

    private function titles(array $posts): array
    {
        $t = array_column($posts, 'title');
        sort($t);

        return $t;
    }

    // ── Linha Editorial da agência ───────────────────────────────────────────

    public function test_the_team_sees_all_its_clients_and_itself_only_with_the_module_and_never_another_one(): void
    {
        $companies = collect($this->as($this->member)->getJson($this->url('/companies'))->assertOk()->json('data'));
        $this->assertSame(['Agência Norte', 'Domiway', 'Sem módulo'], $companies->pluck('name')->all());
        $this->assertSame([true, true, false], $companies->pluck('has_editorial')->all());
        $this->assertTrue($companies->first()['is_agency']);

        $posts = $this->as($this->member)->getJson($this->url('/editorial/board?month=2026-10'))->assertOk()->json('data.posts');
        $this->assertSame(['agencia: produção', 'domiway: produção'], $this->titles($posts));
        $this->assertSame('Domiway', collect($posts)->firstWhere('title', 'domiway: produção')['company']['name']);
        $this->assertSame($posts, $this->as($this->member)->getJson($this->url('/editorial/calendar?month=2026-10'))->json('data.posts'));

        // O admin da agência vê também o cliente "só atribuídos" (é ele que atribui).
        $this->assertSame(['agencia: produção', 'domiway: produção', 'quebom: produção'],
            $this->titles($this->as($this->admin)->getJson($this->url('/editorial/board?month=2026-10'))->json('data.posts')));
        // Filtro por cliente; ids fora do âmbito são ignorados.
        $only = fn (array $ids) => $this->titles($this->as($this->member)->getJson($this->url('/editorial/board?month=2026-10&' . http_build_query(['company_ids' => $ids])))->json('data.posts'));
        $this->assertSame(['domiway: produção'], $only([$this->c['domiway']->id]));
        $this->assertSame([], $only([$this->c['alheia']->id, $this->c['solta']->id, $this->c['antiga']->id, $this->c['quebom']->id]));
        // O root vê a agência que escolher, com todos os clientes dela.
        $this->assertCount(3, $this->as($this->root)->getJson($this->url('/editorial/board?month=2026-10'))->json('data.posts'));
    }

    public function test_today_overdue_awaiting_results_and_the_panel_per_client(): void
    {
        $d = $this->c['domiway'];
        $this->makePost($d, 'Hoje', 'scheduled', '2026-10-14', '18:00');
        $this->makePost($d, 'Atrasada', 'scheduled', '2026-10-13', '09:00');
        $waiting = $this->makePost($d, 'Na aprovação', 'client_review', '2026-10-22');
        $version = EditorialPostVersion::create(['company_id' => $d->id, 'editorial_post_id' => $waiting->id, 'number' => 1, 'status' => 'sent']);
        $waiting->forceFill(['current_version_id' => $version->id])->save();
        $link = ContentReviewLink::create(['company_id' => $d->id, 'title' => 'Semana 3', 'token_hash' => str_repeat('a', 64), 'token_encrypted' => 'x',
            'expires_at' => now()->addDays(5), 'last_sent_at' => now()]);
        ContentReviewLinkItem::create(['content_review_link_id' => $link->id, 'editorial_post_id' => $waiting->id, 'version_id' => $version->id]);
        $this->makePost($this->c['quebom'], 'Quebom hoje', 'scheduled', '2026-10-14');
        $this->makePost($d, 'Em revisão interna', 'internal_review', '2026-10-23');
        $this->makePost($d, 'Ainda em planeamento', 'planning', '2026-10-24'); // fora de "em produção"

        $today = $this->as($this->member)->getJson($this->url('/editorial/today'))->assertOk()->json('data');
        $this->assertSame(['Hoje'], array_column($today['today'], 'title'));
        $this->assertSame(['Atrasada'], array_column($today['overdue'], 'title'));
        $this->assertTrue($today['today'][0]['can_mark']);
        $awaiting = $this->as($this->member)->getJson($this->url('/editorial/awaiting'))->json('data.posts');
        $this->assertSame([['Na aprovação', 'Semana 3', 'Domiway']], array_map(fn ($p) => [$p['title'], $p['links'][0]['title'], $p['company']['name']], $awaiting));
        $this->as($this->member)->getJson($this->url('/editorial/results?month=2026-10'))->assertOk()->assertJsonPath('data.rows', []);

        $panel = collect($this->as($this->member)->getJson($this->url('/panel'))->json('data.rows'))->keyBy(fn ($r) => $r['company']['name']);
        $this->assertSame(['Agência Norte', 'Domiway'], $panel->keys()->all(), 'Sem o módulo, o cliente não entra no painel.');
        // Em produção: Produção e Revisão interna (o Planeamento fica de fora).
        $this->assertSame(['today' => 1, 'overdue' => 1, 'awaiting' => 1, 'production' => 2],
            array_intersect_key($panel['Domiway'], array_flip(['today', 'overdue', 'awaiting', 'production'])));
    }

    // ── Atribuições ──────────────────────────────────────────────────────────

    public function test_only_the_agency_admin_assigns_and_an_unassigned_client_disappears_everywhere_for_that_person(): void
    {
        $d = $this->c['domiway'];
        $this->as($this->member)->getJson($this->url('/assignments'))->assertForbidden();
        $this->as($this->member)->putJson($this->url("/assignments/{$d->id}"), ['team_scope' => 'assigned', 'member_ids' => []])->assertForbidden();
        $this->as($this->admin)->putJson($this->url("/assignments/{$this->c['alheia']->id}"), ['team_scope' => 'assigned', 'member_ids' => []])->assertNotFound();

        $this->as($this->admin)->putJson($this->url("/assignments/{$d->id}"), ['team_scope' => 'assigned', 'member_ids' => [$this->admin->id]])->assertOk();
        $rows = collect($this->as($this->admin)->getJson($this->url('/assignments'))->json('data.clients'))->keyBy(fn ($r) => $r['company']['name']);
        $this->assertSame(['assigned', [$this->admin->id]], [$rows['Domiway']['team_scope'], $rows['Domiway']['member_ids']]);

        // O membro deixa de ver a Domiway em todas as vistas, contagens, na lista de empresas e no acesso direto.
        $this->assertNotContains('Domiway', collect($this->as($this->member)->getJson($this->url('/companies'))->json('data'))->pluck('name'));
        $this->assertSame(['agencia: produção'], $this->titles($this->as($this->member)->getJson($this->url('/editorial/board?month=2026-10'))->json('data.posts')));
        $this->assertNotContains('Domiway', collect($this->as($this->member)->getJson($this->url('/panel'))->json('data.rows'))->pluck('company.name'));
        $this->assertNotContains($d->id, collect($this->as($this->member)->getJson('/api/v1/companies')->json('data'))->pluck('id'));
        $this->as($this->member)->getJson("/api/v1/companies/{$d->id}/editorial/board?month=2026-10")->assertForbidden();

        // Atribuído: volta a ver.
        $this->as($this->admin)->putJson($this->url("/assignments/{$d->id}"), ['team_scope' => 'assigned', 'member_ids' => [$this->member->id]])->assertOk();
        $this->as($this->member)->getJson("/api/v1/companies/{$d->id}/editorial/board?month=2026-10")->assertOk();
    }

    // ── Pedidos de nova empresa gerida ───────────────────────────────────────

    public function test_new_managed_company_request_full_cycle(): void
    {
        $sector = ContentSector::where('slug', 'domotica')->firstOrFail();
        $body = ['name' => 'Casa Viva', 'content_sector_id' => $sector->id, 'contact_name' => 'Joana', 'contact_email' => 'joana@casaviva.pt', 'note' => 'Cliente novo de redes sociais.'];

        $this->as($this->member)->postJson($this->url('/company-requests'), $body + ['authorization_declared' => true])->assertForbidden();
        $this->as($this->admin)->postJson($this->url('/company-requests'), $body)->assertStatus(422)->assertJsonValidationErrors('authorization_declared');
        $id = $this->as($this->admin)->postJson($this->url('/company-requests'), $body + ['authorization_declared' => true])->assertOk()->json('data.id');

        // O root é avisado (sino da equipa e email); a agência vê o estado; só o root decide.
        $this->assertSame(1, Alert::where('company_id', $this->root->company_id)->where('title', 'Pedido de nova empresa gerida: Casa Viva')->count());
        Mail::assertQueued(ManagedCompanyRequestMail::class, fn ($m) => $m->kind === 'new' && $m->hasTo('simon@xplendor.tech'));
        $this->assertSame('pending', $this->as($this->member)->getJson($this->url('/company-requests'))->json('data.requests.0.status'));
        $this->assertFalse($this->as($this->member)->getJson($this->url('/company-requests'))->json('data.can_request'));
        $this->assertTrue($this->as($this->admin)->getJson($this->url('/company-requests'))->json('data.can_request'));
        // O root decide os pedidos; não os faz em nome da agência.
        $this->assertFalse($this->as($this->root)->getJson($this->url('/company-requests'))->json('data.can_request'));
        $this->as($this->admin)->postJson("/api/v1/admin/company-requests/{$id}/approve")->assertForbidden();

        $r = $this->as($this->root)->postJson("/api/v1/admin/company-requests/{$id}/approve")->assertOk();
        $company = Company::findOrFail($r->json('data.company_id'));
        $m = $company->activeManagement;
        $this->assertSame([$this->agency->id, 'created_by_agency', $this->admin->id], [(int) $m->agency_company_id, $m->origin, (int) $m->requested_by_user_id]);
        $this->assertNotNull(ManagedCompanyRequest::find($id)->decided_at, 'A data de aprovação fica registada.');
        $this->assertNull($company->subscription_status, 'Sem período de teste próprio.');
        $this->assertTrue($company->hasPlatformAccess(), 'O acesso vem da agência.');
        $this->assertEqualsCanonicalizing(['marketing_analytics', 'support_tasks', 'linha_editorial'], CompanyModule::where('company_id', $company->id)->pluck('module_key')->all());
        $this->assertSame(['Joana', 'joana@casaviva.pt'], [$company->responsible_name, $company->email]);
        $this->assertSame(0, User::where('company_id', $company->id)->count());
        Mail::assertQueued(ManagedCompanyRequestMail::class, fn ($mail) => $mail->kind === 'approved' && $mail->hasTo('ana@norte.pt'));
        $this->assertSame(1, Alert::where('company_id', $this->agency->id)->where('title', 'Empresa gerida aprovada: Casa Viva')->count());
        // A nova empresa entra já na vista da agência.
        $this->assertContains('Casa Viva', collect($this->as($this->member)->getJson($this->url('/companies'))->json('data'))->pluck('name'));
        $this->as($this->root)->postJson("/api/v1/admin/company-requests/{$id}/approve")->assertStatus(422);

        // Recusa com motivo.
        $id2 = $this->as($this->admin)->postJson($this->url('/company-requests'), ['name' => 'Outra', 'authorization_declared' => true])->json('data.id');
        $this->as($this->root)->postJson("/api/v1/admin/company-requests/{$id2}/decline", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->root)->postJson("/api/v1/admin/company-requests/{$id2}/decline", ['reason' => 'Já existe na plataforma.'])->assertOk()->assertJsonPath('data.status', 'declined');
        Mail::assertQueued(ManagedCompanyRequestMail::class, fn ($mail) => $mail->kind === 'declined' && $mail->note === 'Já existe na plataforma.');
        $this->assertSame(['declined', 'approved'], array_column($this->as($this->admin)->getJson($this->url('/company-requests'))->json('data.requests'), 'status'));
        $this->assertSame(0, Company::where('fiscal_name', 'Outra')->count());
        // Outra agência não vê estes pedidos.
        $outsider = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->as($outsider)->getJson($this->url('/company-requests'))->assertForbidden();
        $this->assertSame([], $this->as($outsider)->getJson($this->url('/company-requests', $this->other))->json('data.requests'));
    }

    // ── Dashboard base e perfil ──────────────────────────────────────────────

    public function test_base_dashboard_blocks_follow_the_active_modules(): void
    {
        $d = $this->c['domiway'];
        $this->makePost($d, 'Hoje', 'scheduled', '2026-10-14', '20:00');
        $this->makePost($d, 'Espera', 'client_review', '2026-10-25');
        SocialFollowerSnapshot::create(['company_id' => $d->id, 'platform' => 'instagram', 'snapshot_date' => '2026-09-20', 'followers_count' => 1000, 'source' => 'manual']);
        SocialFollowerSnapshot::create(['company_id' => $d->id, 'platform' => 'instagram', 'snapshot_date' => '2026-10-13', 'followers_count' => 1100, 'source' => 'api']);

        $data = $this->as($this->member)->getJson("/api/v1/companies/{$d->id}/dashboard/base")->assertOk()->json('data');
        $this->assertSame(['2026-10', 3, 1, 0, 1], [$data['editorial']['month'], $data['editorial']['total'], $data['editorial']['today'], $data['editorial']['overdue'], $data['editorial']['awaiting']]);
        $this->assertSame(1, $data['editorial']['by_stage']['client_review']);
        $this->assertSame([1100, 'api', 100], [$data['followers']['instagram']['current']['count'], $data['followers']['instagram']['current']['source'], $data['followers']['instagram']['growth_30d']]);

        $none = $this->c['sem_modulo'];
        CompanyModule::where('company_id', $none->id)->delete(); // sem Linha Editorial nem Marketing
        $data = $this->as($this->member)->getJson("/api/v1/companies/{$none->id}/dashboard/base")->assertOk()->json('data');
        $this->assertSame([null, null], [$data['editorial'], $data['followers']]);
        $this->as($this->member)->getJson("/api/v1/companies/{$this->c['solta']->id}/dashboard/base")->assertForbidden();
    }

    public function test_company_profile_edit_flag(): void
    {
        $d = $this->c['domiway'];
        $clientAdmin = User::factory()->create(['company_id' => $d->id, 'role' => 'admin']);
        $flag = fn (User $u) => $this->as($u)->getJson("/api/v1/companies/{$d->id}/management")->json('data.can_edit_company');
        $this->assertSame([false, true, true], [$flag($this->member), $flag($clientAdmin), $flag($this->root)]);
    }
}
