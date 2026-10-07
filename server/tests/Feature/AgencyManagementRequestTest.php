<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CompanyArchiveJob;
use App\Jobs\ExpireManagementRequestsJob;
use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\CompanyModule;
use App\Models\ManagementRequest;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Agency\ManagementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * F1d-1: pedido de gestão de uma empresa existente (por NIPC ou email, sem revelar se a
 * empresa existe), aceitar, recusar, expirar, retirar; fim pelos três lados com o acesso
 * cortado de imediato; período de teste; arquivo e apagamento aos 90 dias; ligações à Meta;
 * aviso ao root com a situação de faturação.
 */
class AgencyManagementRequestTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $agency;
    private Company $client;
    private User $root;
    private User $agencyAdmin;
    private User $agencyMember;
    private User $clientAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $xplendor = $this->company('XPLENDOR', 'active');
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root', 'email' => 'simon@xplendor.tech']);
        $this->agency = $this->company('Agência Norte', 'active');
        $this->agency->forceFill(['agency_enabled_at' => now(), 'agency_notification_email' => 'avisos@norte.pt'])->save();
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin', 'email' => 'ana@norte.pt']);
        $this->agencyMember = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->client = $this->company('Domiway', null, '501234567');
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'email' => 'joana@domiway.pt']);
    }

    private function company(string $name, ?string $subscription, ?string $nipc = null): Company
    {
        $c = Company::create(['nipc' => $nipc ?? (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan]);
        $c->forceFill(['subscription_status' => $subscription, 'trial_starts_at' => null, 'trial_ends_at' => null])->save();
        CompanyModule::firstOrCreate(['company_id' => $c->id, 'module_key' => 'linha_editorial']);

        return $c->fresh();
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function ask(array $body, ?User $by = null)
    {
        return $this->as($by ?? $this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/management-requests", $body + ['authorization_declared' => true]);
    }

    private function pending(): ManagementRequest
    {
        return ManagementRequest::where('agency_company_id', $this->agency->id)->latest('id')->firstOrFail();
    }

    private function accept(?ManagementRequest $r = null)
    {
        $r ??= $this->pending();

        return $this->as($this->clientAdmin)->postJson("/api/v1/companies/{$this->client->id}/management/requests/{$r->id}/accept");
    }

    /** Uma rota de empresa qualquer, para ver se a agência tem acesso. */
    private function agencyReaches(Company $company, ?User $who = null): int
    {
        return $this->as($who ?? $this->agencyMember)->getJson("/api/v1/companies/{$company->id}/editorial/calendar")->getStatusCode();
    }

    // ── Pedido ───────────────────────────────────────────────────────────────

    public function test_request_by_nipc_or_email_never_reveals_whether_the_company_exists(): void
    {
        $hit = $this->ask(['nipc' => '501 234 567', 'message' => 'Somos a agência que já vos faz as redes.'])->assertOk();
        $miss = $this->ask(['nipc' => '599999999'])->assertOk();
        $byEmail = $this->ask(['email' => 'JOANA@domiway.pt'])->assertOk(); // duplicado do primeiro: também não diz nada
        $other = $this->ask(['email' => 'ninguem@exemplo.pt'])->assertOk();

        foreach ([$hit, $miss, $byEmail, $other] as $r) {
            $this->assertSame(ManagementRequestService::NEUTRAL_REPLY, $r->json('message'));
            $this->assertSame('pending', $r->json('data.status'));
            $this->assertNull($r->json('data.company'));
            $this->assertArrayNotHasKey('managed_company_id', $r->json('data'));
        }
        $this->assertSame([$this->client->id, null, null, null],
            ManagementRequest::orderBy('id')->pluck('managed_company_id')->all(), 'Só o primeiro chega à empresa (o terceiro é um repetido).');

        // A lista da agência mostra os quatro iguais.
        $list = $this->as($this->agencyAdmin)->getJson("/api/v1/agencies/{$this->agency->id}/management-requests")->assertOk()->json('data.requests');
        $this->assertCount(4, $list);
        $this->assertSame(['pending'], array_values(array_unique(array_column($list, 'status'))));

        // Aviso só aos admins da empresa: sino só da própria empresa e email.
        $alert = Alert::where('company_id', $this->client->id)->sole();
        $this->assertTrue($alert->own_only);
        $this->assertStringContainsString('Agência Norte', $alert->title);
        Mail::assertQueued(AgencyNoticeMail::class, fn ($m) => $m->hasTo('joana@domiway.pt'));
        Mail::assertQueued(AgencyNoticeMail::class, 1);
    }

    public function test_request_by_the_email_of_an_admin_reaches_that_company(): void
    {
        $this->ask(['email' => 'joana@domiway.pt'])->assertOk();
        $this->assertSame($this->client->id, $this->pending()->managed_company_id);
    }

    public function test_only_the_agency_admin_asks_with_the_authorization_and_one_identifier(): void
    {
        $this->ask(['nipc' => '501234567'], $this->agencyMember)->assertForbidden();
        $this->as($this->root)->postJson("/api/v1/agencies/{$this->agency->id}/management-requests", ['nipc' => '501234567', 'authorization_declared' => true])->assertForbidden();
        $this->as($this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/management-requests", ['nipc' => '501234567'])
            ->assertStatus(422)->assertJsonValidationErrors('authorization_declared');
        $this->ask([])->assertStatus(422)->assertJsonValidationErrors(['nipc', 'email']);
        $this->ask(['nipc' => '12345'])->assertStatus(422)->assertJsonValidationErrors('nipc');
        $this->ask(['nipc' => '501234567', 'email' => 'joana@domiway.pt'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_companies_without_an_admin_agencies_and_own_clients_are_never_reached(): void
    {
        $noAdmin = $this->company('Sem admin', null, '502222222');
        $otherAgency = $this->company('Agência Sul', 'active', '503333333');
        $otherAgency->forceFill(['agency_enabled_at' => now()])->save();
        User::factory()->create(['company_id' => $otherAgency->id, 'role' => 'admin']);
        foreach (['502222222', '503333333', (string) $this->agency->nipc] as $nipc) {
            $this->ask(['nipc' => $nipc])->assertOk();
        }
        $this->assertSame(0, ManagementRequest::whereNotNull('managed_company_id')->count());
    }

    // ── Resposta ─────────────────────────────────────────────────────────────

    public function test_company_admins_see_the_scope_and_accept_and_the_root_gets_the_billing_situation(): void
    {
        $this->ask(['nipc' => '501234567', 'message' => 'Olá'])->assertOk();
        $this->assertSame(403, $this->agencyReaches($this->client), 'Pedido pendente: sem acesso.');

        $list = $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management/requests")->assertOk()->json('data.requests');
        $this->assertSame('Agência Norte', $list[0]['agency']['name']);
        $this->assertSame('Olá', $list[0]['message']);
        $this->assertNotEmpty($list[0]['scope']['can']);
        $this->assertNotEmpty($list[0]['scope']['cannot']);
        $this->assertSame(1, $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management")->json('data.pending_requests'));

        $this->accept()->assertOk();
        $m = CompanyManagement::active()->where('managed_company_id', $this->client->id)->sole();
        $this->assertSame(['request_accepted', $this->agency->id, $this->clientAdmin->id], [$m->origin, $m->agency_company_id, $m->responded_by_user_id]);
        $this->assertSame('accepted', $this->pending()->status);
        $this->assertSame(200, $this->agencyReaches($this->client), 'Aceite: a agência trabalha na empresa.');
        $this->assertSame('Domiway', $this->as($this->agencyAdmin)->getJson("/api/v1/agencies/{$this->agency->id}/management-requests")->json('data.requests.0.company.name'));

        // Sem subscrição própria ativa: conta para a agência a partir do mês seguinte.
        $rootAlert = Alert::where('company_id', $this->root->company_id)->sole();
        $this->assertStringContainsString('passa a contar para a agência', $rootAlert->message);
        Mail::assertQueued(AgencyNoticeMail::class, fn ($mail) => $mail->hasTo('simon@xplendor.tech') && str_contains(implode(' ', $mail->lines), '15 € por mês'));
        Mail::assertQueued(AgencyNoticeMail::class, fn ($mail) => $mail->hasTo('avisos@norte.pt') && str_contains($mail->title, 'aceite'));

        $billing = $this->as($this->root)->getJson('/api/v1/admin/management-requests?status=accepted')->assertOk()->json('data.requests.0.billing');
        $this->assertTrue($billing['counts_for_agency']);
        $this->assertSame(now()->startOfMonth()->addMonth()->format('Y-m'), $billing['from_month']);
    }

    public function test_a_company_with_its_own_active_subscription_keeps_paying_it(): void
    {
        $this->client->forceFill(['subscription_status' => 'active'])->save();
        $this->ask(['nipc' => '501234567'])->assertOk();
        $this->accept()->assertOk();
        $this->assertStringContainsString('continua a pagá-la', Alert::where('company_id', $this->root->company_id)->sole()->message);
    }

    public function test_decline_with_an_optional_reason_tells_the_agency_and_gives_no_access(): void
    {
        $this->ask(['nipc' => '501234567'])->assertOk();
        $r = $this->pending();
        $this->as($this->clientAdmin)->postJson("/api/v1/companies/{$this->client->id}/management/requests/{$r->id}/decline", ['reason' => 'Já temos agência.'])->assertOk();
        $this->assertSame('declined', $r->fresh()->status);
        $this->assertSame(403, $this->agencyReaches($this->client));
        $row = $this->as($this->agencyAdmin)->getJson("/api/v1/agencies/{$this->agency->id}/management-requests")->json('data.requests.0');
        $this->assertSame(['declined', 'Já temos agência.', null], [$row['status'], $row['decline_reason'], $row['company']]);
        Mail::assertQueued(AgencyNoticeMail::class, fn ($m) => $m->hasTo('ana@norte.pt') && str_contains($m->title, 'recusado'));
        $this->accept($r)->assertStatus(422);
    }

    public function test_requests_expire_after_14_days_and_the_agency_can_withdraw_them(): void
    {
        $this->ask(['nipc' => '501234567'])->assertOk();
        $first = $this->pending();
        $this->travel(15)->days();
        (new ExpireManagementRequestsJob())->handle(app(ManagementRequestService::class));
        $this->assertSame('expired', $first->fresh()->status);
        $this->accept($first)->assertStatus(422);
        $this->travelBack();

        $this->ask(['nipc' => '501234567'])->assertOk();
        $second = $this->pending();
        $this->as($this->agencyMember)->postJson("/api/v1/agencies/{$this->agency->id}/management-requests/{$second->id}/withdraw")->assertForbidden();
        $this->as($this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/management-requests/{$second->id}/withdraw")->assertOk();
        $this->assertSame('withdrawn', $second->fresh()->status);
        $this->accept($second)->assertStatus(422);
        $this->assertSame(403, $this->agencyReaches($this->client));
    }

    public function test_only_the_company_own_admins_answer_and_its_current_agency_never_sees_the_request(): void
    {
        // A empresa já é gerida por outra agência: essa agência não vê o pedido nem decide.
        $sul = $this->company('Agência Sul', 'active');
        $sul->forceFill(['agency_enabled_at' => now()])->save();
        $sulAdmin = User::factory()->create(['company_id' => $sul->id, 'role' => 'admin']);
        CompanyManagement::create(['agency_company_id' => $sul->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()]);

        $this->ask(['nipc' => '501234567'])->assertOk();
        $r = $this->pending();
        $this->as($sulAdmin)->getJson("/api/v1/companies/{$this->client->id}/management/requests")->assertForbidden();
        $this->as($sulAdmin)->postJson("/api/v1/companies/{$this->client->id}/management/requests/{$r->id}/accept")->assertForbidden();
        $this->assertSame(0, $this->as($sulAdmin)->getJson("/api/v1/companies/{$this->client->id}/alerts/unread-count")->json('data.count'));
        $this->assertSame(1, $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/alerts/unread-count")->json('data.count'));

        // Aceitar muda de agência: a anterior perde o acesso de imediato, sem período de teste.
        $this->accept($r)->assertOk();
        $this->assertSame(403, $this->as($sulAdmin)->getJson("/api/v1/companies/{$this->client->id}/editorial/calendar")->getStatusCode());
        $this->assertSame(200, $this->agencyReaches($this->client));
        $this->assertNull($this->client->fresh()->trial_ends_at);
    }

    public function test_a_company_without_access_can_still_answer_a_request(): void
    {
        $this->client->forceFill(['subscription_status' => 'expired'])->save();
        $this->ask(['nipc' => '501234567'])->assertOk();
        $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/editorial/calendar")->assertForbidden();
        $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management/requests")->assertOk();
        $this->accept()->assertOk();
        $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/editorial/calendar")->assertOk();
    }

    // ── Fim da relação ───────────────────────────────────────────────────────

    private function managed(): CompanyManagement
    {
        $this->ask(['nipc' => '501234567'])->assertOk();
        $this->accept()->assertOk();

        return CompanyManagement::active()->where('managed_company_id', $this->client->id)->sole();
    }

    private function agencyConnections(): void
    {
        CompanyIntegration::create(['company_id' => $this->client->id, 'platform' => 'meta', 'access_token' => 'tok', 'account_id' => 'act_1',
            'status' => 'active', 'connected_by_user_id' => $this->agencyAdmin->id]);
        SocialConnection::create(['company_id' => $this->client->id, 'access_token' => 'tok2', 'status' => SocialConnection::STATUS_ACTIVE,
            'connected_by_user_id' => $this->agencyAdmin->id, 'connected_at' => now()]);
    }

    public function test_end_by_the_company_cuts_access_starts_a_trial_and_applies_the_connections_choice(): void
    {
        $this->managed();
        $this->agencyConnections();
        $kinds = array_column($this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management")->json('data.agency_connections'), 'kind');
        $this->assertSame(['meta_ads', 'social'], $kinds);

        $this->as($this->clientAdmin)->deleteJson("/api/v1/companies/{$this->client->id}/management", ['reason' => 'Mudámos de estratégia.', 'connections' => 'disconnect'])->assertOk();
        $this->assertSame(403, $this->agencyReaches($this->client), 'Acesso cortado de imediato.');
        $m = CompanyManagement::where('managed_company_id', $this->client->id)->sole();
        $this->assertSame(['ended', 'company', 'handed_over', 'disconnected'], [$m->status, $m->ended_by_side, $m->data_outcome, $m->connections_decision]);
        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->client->id)->value('status'));
        $this->assertSame(SocialConnection::STATUS_REVOKED, SocialConnection::where('company_id', $this->client->id)->value('status'));

        $client = $this->client->fresh();
        $this->assertSame('trial', $client->subscription_status, 'Só tinha acesso pela agência: período de teste.');
        $this->assertSame(30, (int) round($client->trial_starts_at->diffInDays($client->trial_ends_at)));
        $this->assertTrue($client->hasPlatformAccess());
        Mail::assertQueued(AgencyNoticeMail::class, fn ($mail) => $mail->hasTo('ana@norte.pt') && str_contains($mail->title, 'Relação terminada'));
    }

    public function test_end_by_the_company_keeps_the_connections_by_default_and_no_trial_with_own_subscription(): void
    {
        $this->client->forceFill(['subscription_status' => 'active'])->save();
        $this->managed();
        $this->agencyConnections();
        $this->as($this->clientAdmin)->deleteJson("/api/v1/companies/{$this->client->id}/management")->assertOk();
        $this->assertSame('kept', CompanyManagement::where('managed_company_id', $this->client->id)->value('connections_decision'));
        $this->assertSame('active', CompanyIntegration::where('company_id', $this->client->id)->value('status'));
        $this->assertSame('active', $this->client->fresh()->subscription_status);
    }

    public function test_end_by_the_agency_asks_the_client_admin_about_the_connections(): void
    {
        $this->managed();
        $this->agencyConnections();
        $this->as($this->agencyMember)->postJson("/api/v1/agencies/{$this->agency->id}/managed/{$this->client->id}/end", ['reason' => 'Fim do contrato.'])->assertForbidden();
        $this->as($this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/managed/{$this->client->id}/end", [])->assertStatus(422);
        $this->as($this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/managed/{$this->client->id}/end", ['reason' => 'Fim do contrato.'])->assertOk();
        $this->assertSame(403, $this->agencyReaches($this->client));

        $m = CompanyManagement::where('managed_company_id', $this->client->id)->sole();
        $this->assertSame(['agency', 'pending'], [$m->ended_by_side, $m->connections_decision]);
        $decision = $this->as($this->clientAdmin)->getJson("/api/v1/companies/{$this->client->id}/management")->json('data.connections_decision');
        $this->assertSame('Agência Norte', $decision['agency']);
        $this->assertCount(2, $decision['connections']);
        Mail::assertQueued(AgencyNoticeMail::class, fn ($mail) => $mail->hasTo('joana@domiway.pt') && str_contains(implode(' ', $mail->lines), 'manter ou desligar'));

        $this->as($this->clientAdmin)->postJson("/api/v1/companies/{$this->client->id}/management/connections", ['decision' => 'keep'])->assertOk();
        $this->assertSame('kept', $m->fresh()->connections_decision);
        $this->assertSame('active', CompanyIntegration::where('company_id', $this->client->id)->value('status'));
        $this->as($this->clientAdmin)->postJson("/api/v1/companies/{$this->client->id}/management/connections", ['decision' => 'keep'])->assertStatus(422);
    }

    public function test_end_by_the_root_with_a_reason(): void
    {
        $this->managed();
        $this->as($this->agencyAdmin)->postJson("/api/v1/admin/companies/{$this->client->id}/management/end", ['reason' => 'x'])->assertForbidden();
        $this->as($this->root)->postJson("/api/v1/admin/companies/{$this->client->id}/management/end", [])->assertStatus(422);
        $this->as($this->root)->postJson("/api/v1/admin/companies/{$this->client->id}/management/end", ['reason' => 'Pedido do cliente por telefone.'])->assertOk();
        $this->assertSame(403, $this->agencyReaches($this->client));
        $this->assertSame('platform', CompanyManagement::where('managed_company_id', $this->client->id)->value('ended_by_side'));
        Mail::assertQueued(AgencyNoticeMail::class, fn ($mail) => $mail->hasTo('joana@domiway.pt') && str_contains(implode(' ', $mail->lines), 'A XPLENDOR terminou'));
    }

    public function test_a_company_without_admin_is_archived_its_agency_connections_disconnected_and_deleted_after_90_days(): void
    {
        $orphan = $this->company('Criada pela agência', null);
        $orphan->forceFill(['email' => 'geral@orfa.pt'])->save();
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $orphan->id, 'origin' => 'created_by_agency',
            'status' => 'active', 'active_key' => $orphan->id, 'team_scope' => 'all', 'requested_at' => now()]);
        CompanyIntegration::create(['company_id' => $orphan->id, 'platform' => 'meta', 'access_token' => 'tok', 'account_id' => 'act_2', 'status' => 'active']);

        $this->as($this->agencyAdmin)->postJson("/api/v1/agencies/{$this->agency->id}/managed/{$orphan->id}/end", ['reason' => 'Cliente perdido.'])->assertOk();
        $orphan->refresh();
        $this->assertNotNull($orphan->archived_at);
        $this->assertFalse($orphan->hasPlatformAccess(), 'Arquivada: desativada.');
        $this->assertSame('revoked', CompanyIntegration::where('company_id', $orphan->id)->value('status'), 'Sem admin: as ligações da agência desligam-se.');
        $this->assertSame(['archived', 'disconnected'], [CompanyManagement::where('managed_company_id', $orphan->id)->value('data_outcome'),
            CompanyManagement::where('managed_company_id', $orphan->id)->value('connections_decision')]);
        Mail::assertQueued(AgencyNoticeMail::class, fn ($m) => $m->hasTo('geral@orfa.pt') && str_contains($m->title, 'arquivada'));

        $this->travel(84)->days();
        app(CompanyArchiveJob::class)->handle(app(\App\Services\Agency\CompanyArchiveService::class));
        Mail::assertQueued(AgencyNoticeMail::class, fn ($m) => $m->hasTo('geral@orfa.pt') && str_contains($m->title, 'vai ser apagada'));
        $this->assertNotNull(Company::find($orphan->id));

        $this->travel(7)->days();
        app(CompanyArchiveJob::class)->handle(app(\App\Services\Agency\CompanyArchiveService::class));
        $this->assertNull(Company::find($orphan->id), 'Apagada ao fim de 90 dias.');
        $this->assertNotNull(Company::withTrashed()->find($orphan->id));
    }

    public function test_an_archived_company_leaves_the_archive_when_it_gains_an_admin_or_an_agency(): void
    {
        $a = $this->company('A', null);
        $b = $this->company('B', null);
        foreach ([$a, $b] as $c) {
            $c->forceFill(['archived_at' => now(), 'archive_delete_at' => now()->addDays(90)])->save();
        }
        User::factory()->create(['company_id' => $a->id, 'role' => 'admin']);
        $this->assertNull($a->fresh()->archived_at, 'Ganhou um admin.');

        $this->as($this->root)->putJson("/api/v1/admin/companies/{$b->id}/management", ['agency_company_id' => $this->agency->id])->assertOk();
        $this->assertNull($b->fresh()->archived_at, 'Ganhou uma agência.');
    }
}
