<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AgencyBillingSnapshotJob;
use App\Jobs\ContentReviewDigestJob;
use App\Jobs\GenerateDailyAlertsEmailJob;
use App\Mail\ContentReviewDigestMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\ContentReviewLink;
use App\Models\User;
use App\Services\Agency\AgencyBilling;
use App\Services\ContentReview\ContentReviewNotifier;
use App\Services\Editorial\EditorialWorkflowService;
use App\Support\TeamDeviceMarker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1e: os avisos e resumos das empresas geridas vão para a agência delas (nunca para os
 * roots); as aberturas da agência gestora não contam; os módulos só pelo root; a contagem
 * para faturação (paga quem dá o acesso); quem produz nos textos ("a sua agência").
 */
class AgencyF1eTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $xplendor;
    private Company $agency;
    private Company $client;
    private User $root;
    private User $agencyAdmin;
    private User $member;
    private User $clientAdmin;
    private CompanyManagement $relation;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC'));
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->xplendor = $this->company('XPLENDOR', 'active');
        $this->root = User::factory()->create(['company_id' => $this->xplendor->id, 'role' => 'root', 'email' => 'simon@xplendor.tech']);
        config(['quotes.team_company_id' => $this->xplendor->id, 'content_review.team_emails' => 'equipa@xplendor.tech']);
        $this->agency = $this->company('Agência Norte', 'active');
        $this->agency->forceFill(['agency_enabled_at' => now(), 'agency_notification_email' => 'avisos@norte.pt'])->save();
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin', 'email' => 'ana@norte.pt']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user', 'email' => 'rui@norte.pt']);
        $this->client = $this->company('Domiway', null);
        $this->client->forceFill(['content_production_mode' => 'team'])->save();
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'email' => 'joana@domiway.pt']);
        $this->relation = $this->manage($this->client, now()->subMonths(2));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(string $name, ?string $subscription): Company
    {
        $c = Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan]);
        $c->forceFill(['subscription_status' => $subscription, 'trial_starts_at' => null, 'trial_ends_at' => null])->save();
        CompanyModule::firstOrCreate(['company_id' => $c->id, 'module_key' => 'linha_editorial']);

        return $c->fresh();
    }

    private function manage(Company $c, $since, string $scope = 'all'): CompanyManagement
    {
        return CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $c->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $c->id, 'team_scope' => $scope, 'requested_at' => $since, 'responded_at' => $since]);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    // ── Avisos na agência certa ──────────────────────────────────────────────

    public function test_notices_of_a_managed_client_go_to_its_agency_and_never_to_the_roots(): void
    {
        app(ContentReviewNotifier::class)->notifyCompany($this->client->id, 'urgent', 'Publicação atrasada: Outono', 'Falta marcar.', 'high', '/editorial?vista=kanban&mes=2026-10');

        $alert = Alert::where('company_id', $this->agency->id)->sole();
        $this->assertSame('Domiway: Publicação atrasada: Outono', $alert->title);
        $this->assertSame("/editorial?cliente={$this->client->id}&vista=kanban&mes=2026-10", $alert->detail_path);
        $this->assertSame(0, Alert::where('company_id', $this->xplendor->id)->count(), 'Nada no sino dos roots.');

        // Resumo por email: os admins da agência e o email de avisos dela, nunca CONTENT_REVIEW_TEAM_EMAILS.
        $this->assertEqualsCanonicalizing(['ana@norte.pt', 'avisos@norte.pt'], ContentReviewNotifier::recipients($this->client->id, null));
        (new ContentReviewDigestJob())->handle();
        Mail::assertSent(ContentReviewDigestMail::class, fn ($m) => $m->hasTo('ana@norte.pt'));
        Mail::assertSent(ContentReviewDigestMail::class, fn ($m) => $m->hasTo('avisos@norte.pt'));
        Mail::assertNotSent(ContentReviewDigestMail::class, fn ($m) => $m->hasTo('equipa@xplendor.tech') || $m->hasTo('simon@xplendor.tech'));
    }

    public function test_a_client_limited_to_chosen_people_notifies_those_people(): void
    {
        $this->relation->update(['team_scope' => 'assigned']);
        $this->relation->members()->create(['user_id' => $this->member->id, 'assigned_at' => now()]);
        $this->assertEqualsCanonicalizing(['rui@norte.pt', 'avisos@norte.pt'], ContentReviewNotifier::recipients($this->client->id, null));
    }

    public function test_companies_without_an_agency_still_use_the_xplendor_team(): void
    {
        $solo = $this->company('Sem agência', 'active');
        $solo->forceFill(['content_production_mode' => 'team'])->save();
        app(ContentReviewNotifier::class)->notifyCompany($solo->id, 'warning', 'Para publicar hoje: 1 publicação', 'Outono.');

        $this->assertSame(1, Alert::where('company_id', $this->xplendor->id)->count());
        $this->assertSame(['equipa@xplendor.tech'], ContentReviewNotifier::recipients($solo->id, null));
    }

    public function test_the_daily_alerts_summary_of_a_managed_company_goes_to_its_agency(): void
    {
        $this->assertEqualsCanonicalizing(['ana@norte.pt', 'avisos@norte.pt'], GenerateDailyAlertsEmailJob::recipients($this->client));
        $this->assertNotContains('simoncosta@xplendor.tech', GenerateDailyAlertsEmailJob::recipients($this->client));
        $this->assertContains('simoncosta@xplendor.tech', GenerateDailyAlertsEmailJob::recipients($this->xplendor));
    }

    // ── Aberturas ────────────────────────────────────────────────────────────

    private function link(Company $c): string
    {
        $token = Str::random(ContentReviewLink::TOKEN_LENGTH);
        ContentReviewLink::create(['company_id' => $c->id, 'title' => 'Semana 3', 'token_hash' => ContentReviewLink::hashToken($token),
            'token_encrypted' => 'x', 'expires_at' => now()->addDays(10), 'last_sent_at' => now()]);

        return $token;
    }

    private function open(string $token, ?string $marker, string $visitor)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15', 'X-Review-Token' => $token])
            ->postJson('/api/public/review/open', ['visitor_id' => $visitor, 'team_marker' => $marker])->assertOk()->json('data');
    }

    public function test_opens_by_the_managing_agency_do_not_count(): void
    {
        $token = $this->link($this->client);
        $other = $this->company('Agência Sul', 'active');
        $other->forceFill(['agency_enabled_at' => now()])->save();
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);

        $this->assertSame('team', $this->open($token, TeamDeviceMarker::issue($this->member), str_repeat('a', 20))['reason'], 'Membro da agência gestora.');
        $this->assertSame('team', $this->open($token, TeamDeviceMarker::issue($this->root), str_repeat('b', 20))['reason'], 'A equipa XPLENDOR.');
        $this->assertTrue($this->open($token, TeamDeviceMarker::issue($stranger), str_repeat('c', 20))['counted'], 'Outra agência conta como qualquer visitante.');
        $this->assertTrue($this->open($token, null, str_repeat('d', 20))['counted']);
        $this->assertSame(2, (int) ContentReviewLink::where('company_id', $this->client->id)->value('open_count'));

        // Uma marca forjada não é aceite.
        $this->assertTrue($this->open($token, $this->member->id . '.' . now()->timestamp . '.forjada', str_repeat('e', 20))['counted']);
    }

    public function test_agency_members_receive_the_browser_marker_at_login(): void
    {
        $this->member->forceFill(['password' => bcrypt('Segredo-123')])->save();
        $this->clientAdmin->forceFill(['password' => bcrypt('Segredo-123')])->save();
        $this->assertNotEmpty($this->postJson('/api/v1/login', ['email' => 'rui@norte.pt', 'password' => 'Segredo-123'])->json('data.team_marker'));
        $this->assertNull($this->postJson('/api/v1/login', ['email' => 'joana@domiway.pt', 'password' => 'Segredo-123'])->json('data.team_marker'));
    }

    // ── Módulos só pelo root ─────────────────────────────────────────────────

    public function test_only_the_root_switches_the_modules_of_a_managed_company(): void
    {
        foreach ([$this->agencyAdmin, $this->member, $this->clientAdmin] as $who) {
            $this->as($who)->patchJson("/api/v1/admin/companies/{$this->client->id}/modules", ['module_key' => 'blog', 'enabled' => true])->assertForbidden();
            $this->as($who)->postJson("/api/v1/admin/companies/{$this->client->id}/modules/preset", ['preset' => 'automotive'])->assertForbidden();
            $this->as($who)->getJson("/api/v1/admin/companies/{$this->client->id}/modules")->assertForbidden();
        }
        $this->as($this->root)->patchJson("/api/v1/admin/companies/{$this->client->id}/modules", ['module_key' => 'marketing_analytics', 'enabled' => true])->assertOk();
    }

    // ── Contagem para faturação (paga quem dá o acesso) ──────────────────────

    public function test_billing_count_follows_who_gives_the_access(): void
    {
        $ownSub = $this->company('Com subscrição', 'active');
        $this->manage($ownSub, now()->subMonths(3));
        $midMonth = $this->company('Começou a meio do mês', null);
        $this->manage($midMonth, now()->subDays(5)); // 9 de outubro
        $ended = $this->company('Terminada', null);
        $this->manage($ended, now()->subMonths(4))->update(['status' => 'ended', 'active_key' => null, 'ended_at' => now()->subDay()]);

        $s = AgencyBilling::summary($this->agency);
        $this->assertSame(['2026-10', 1, false], [$s['current']['month'], $s['current']['count'], $s['current']['from_snapshot']], 'Este mês: só a Domiway (começou antes de outubro e não paga).');
        $this->assertSame(['2026-11', 2], [$s['next']['month'], $s['next']['count']], 'Novembro: a Domiway e a que começou a meio de outubro.');
        $rows = collect($s['companies'])->keyBy('name');
        $this->assertTrue($rows['Com subscrição']['pays_own']);
        $this->assertFalse($rows['Com subscrição']['counts_next']);
        $this->assertFalse($rows['Começou a meio do mês']['counts_current']);
        $this->assertTrue($rows['Começou a meio do mês']['counts_next']);
        $this->assertArrayNotHasKey('Terminada', $rows->all(), 'A relação terminada deixa de contar.');

        // Se a empresa deixar de pagar a subscrição e continuar gerida, passa a contar.
        $ownSub->forceFill(['subscription_status' => 'expired'])->save();
        $this->assertSame(3, AgencyBilling::summary($this->agency)['next']['count']);

        // Visível para o admin da agência e para o root; não para um membro comum.
        $this->as($this->agencyAdmin)->getJson("/api/v1/agencies/{$this->agency->id}/billing")->assertOk()->assertJsonPath('data.next.count', 3);
        $this->as($this->member)->getJson("/api/v1/agencies/{$this->agency->id}/billing")->assertForbidden();
        $agencies = collect($this->as($this->root)->getJson('/api/v1/admin/agencies')->assertOk()->json('data.agencies'))->keyBy('id');
        $this->assertSame(3, $agencies[$this->agency->id]['billing']['next']['count']);
    }

    public function test_the_monthly_snapshot_on_day_1_keeps_the_count_of_the_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'Europe/Lisbon'));
        (new AgencyBillingSnapshotJob())->handle();
        $row = DB::table('agency_billing_counts')->where('agency_company_id', $this->agency->id)->where('month', '2026-11')->sole();
        $this->assertSame([1, [$this->client->id], 15], [(int) $row->companies_count, json_decode($row->company_ids, true), (int) $row->monthly_fee]);

        // A relação termina a meio do mês: o mês atual mantém o registo do dia 1; o seguinte já não a conta.
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Europe/Lisbon'));
        $this->relation->update(['status' => 'ended', 'active_key' => null, 'ended_at' => now()]);
        $s = AgencyBilling::summary($this->agency);
        $this->assertSame([1, true], [$s['current']['count'], $s['current']['from_snapshot']]);
        $this->assertSame(0, $s['next']['count']);
    }

    // ── Textos: quem produz ──────────────────────────────────────────────────

    public function test_texts_name_the_agency_instead_of_the_xplendor_team(): void
    {
        $this->assertSame('Nesta empresa a produção é feita pela sua agência (Agência Norte): pode comentar, aprovar ou pedir alterações.',
            EditorialWorkflowService::teamProducesMessage($this->client->id));
        $this->assertSame('a sua agência (Agência Norte)', $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management")->json('data.producer_label'));
        $this->assertSame('a Agência Norte', $this->as($this->member)->getJson("/api/v1/companies/{$this->client->id}/management")->json('data.producer_label'));

        $solo = $this->company('Sem agência', 'active');
        $this->assertSame(EditorialWorkflowService::MSG_TEAM_PRODUCES, EditorialWorkflowService::teamProducesMessage($solo->id), 'Sem agência: a própria plataforma.');
    }
}
