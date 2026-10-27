<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\MailboxHosts;
use App\Services\Mailbox\SmtpMailboxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 1 · A minha caixa de correio: configurar e testar o envio. Só o próprio, na sua empresa:
 * o root tem a sua caixa só na própria empresa; noutras empresas, o root, a agência e as outras
 * empresas recebem 403, e a impersonation também; outra pessoa da mesma empresa só chega à sua
 * própria caixa. A palavra-passe fica cifrada e nunca sai pela API. O envio
 * vai pelo cliente falso (os testes nunca ligam a um servidor de e-mail).
 */
class MyMailboxTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private Company $agency;
    private User $owner;
    private User $colleague;
    private User $root;
    private User $agencyAdmin;
    private User $otherCompanyAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        FakeMailboxClient::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500017001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500017002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = Company::create(['nipc' => '500017003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->a->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->a->id, 'team_scope' => 'all', 'requested_at' => now()]);

        $this->owner = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'email' => 'matilde@exemplo.invalid']);
        $this->colleague = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $this->a->id, 'role' => 'root']);
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $this->otherCompanyAdmin = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
    }

    private function url(?Company $c = null, string $suffix = ''): string
    {
        return '/api/v1/companies/' . ($c ?? $this->a)->id . '/my-mailbox' . $suffix;
    }

    private function cpanel(array $over = []): array
    {
        return $over + [
            'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt', 'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt',
            'password' => 'segredo-de-teste-123', 'save_to_sent' => true,
        ];
    }

    private function configure(User $user, array $over = []): Mailbox
    {
        Sanctum::actingAs($user);
        $this->putJson($this->url(), $this->cpanel($over))->assertOk();

        return Mailbox::personal($this->a->id, $user->id)->firstOrFail();
    }

    private function impersonationHeaders(User $target): array
    {
        $token = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_the_owner_saves_reads_and_removes_the_mailbox_and_the_password_never_comes_back(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.mailbox', null);

        $res = $this->putJson($this->url(), $this->cpanel())->assertOk()
            ->assertJsonPath('data.mailbox.email', 'matilde@exemplo.pt')
            ->assertJsonPath('data.mailbox.has_password', true)
            ->assertJsonPath('data.mailbox.status', Mailbox::STATUS_PENDING)
            ->assertJsonMissingPath('data.mailbox.password');
        $this->assertStringNotContainsString('segredo-de-teste-123', $res->getContent());
        $show = $this->getJson($this->url())->assertOk()->assertJsonPath('data.mailbox.smtp_host', 'mail.exemplo.pt');
        $this->assertStringNotContainsString('segredo-de-teste-123', $show->getContent());

        // Cifrada na base de dados; o modelo decifra.
        $raw = (string) DB::table('mailboxes')->value('password');
        $this->assertStringNotContainsString('segredo-de-teste-123', $raw);
        $this->assertSame('segredo-de-teste-123', Mailbox::first()->password);
        $this->assertArrayNotHasKey('password', Mailbox::first()->toArray());

        $this->deleteJson($this->url())->assertOk();
        $this->assertSame(0, Mailbox::count());
    }

    public function test_an_empty_password_keeps_the_saved_one_and_any_change_asks_for_a_new_test(): void
    {
        $mailbox = $this->configure($this->owner);
        $mailbox->forceFill(['status' => Mailbox::STATUS_ACTIVE])->save();

        $this->putJson($this->url(), $this->cpanel(['password' => '', 'smtp_port' => 587, 'smtp_encryption' => 'starttls']))->assertOk()
            ->assertJsonPath('data.mailbox.status', Mailbox::STATUS_PENDING);
        $this->assertSame('segredo-de-teste-123', $mailbox->fresh()->password);
        $this->assertSame(587, $mailbox->fresh()->smtp_port);
    }

    public function test_the_signature_is_saved_without_asking_for_a_new_test_and_empty_clears_it(): void
    {
        $mailbox = $this->configure($this->owner);
        $mailbox->forceFill(['status' => Mailbox::STATUS_ACTIVE])->save();

        $this->putJson($this->url(), $this->cpanel(['password' => '', 'signature' => "Matilde Exemplo\nQuebom Exemplo Lda  \n"]))->assertOk()
            ->assertJsonPath('data.mailbox.signature', "Matilde Exemplo\nQuebom Exemplo Lda")
            ->assertJsonPath('data.mailbox.status', Mailbox::STATUS_ACTIVE); // a assinatura não obriga a testar de novo
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'signature' => '   ']))->assertOk()->assertJsonPath('data.mailbox.signature', null);
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'signature' => str_repeat('a', 2001)]))->assertStatus(422);
    }

    public function test_in_dev_the_test_addresses_are_saved_normalised_and_validated(): void
    {
        $this->configure($this->owner);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.dev', true);
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'dev_test_addresses' => " Eu.Pessoal@exemplo.invalid; eu.outro@exemplo.invalid, eu.pessoal@exemplo.invalid "]))
            ->assertOk()->assertJsonPath('data.dev', true)
            ->assertJsonPath('data.mailbox.dev_test_addresses', 'eu.pessoal@exemplo.invalid, eu.outro@exemplo.invalid');
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'dev_test_addresses' => 'nao-e-endereco']))->assertStatus(422);
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'dev_test_addresses' => '']))->assertOk()->assertJsonPath('data.mailbox.dev_test_addresses', null);
    }

    public function test_in_production_the_test_addresses_are_not_saved_and_the_field_is_not_offered(): void
    {
        $this->app['env'] = 'production';
        $this->configure($this->owner);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.dev', false);
        $this->putJson($this->url(), $this->cpanel(['password' => '', 'dev_test_addresses' => 'eu.pessoal@exemplo.invalid']))->assertOk()
            ->assertJsonPath('data.mailbox.dev_test_addresses', null);
        $this->assertNull(Mailbox::first()->dev_test_addresses);
    }

    public function test_validation_only_the_three_account_types_and_internal_hosts_and_other_ports_are_refused(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url(), $this->cpanel(['account_type' => 'outlook']))->assertStatus(422)->assertJsonValidationErrors('account_type');
        foreach (['db', 'mailpit', 'localhost', '127.0.0.1', '10.0.0.5', 'mail.empresa.local', 'mail.exemplo.internal'] as $host) {
            $this->putJson($this->url(), $this->cpanel(['smtp_host' => $host]))->assertStatus(422)->assertJsonValidationErrors('smtp_host');
        }
        $this->putJson($this->url(), $this->cpanel(['smtp_port' => 6379]))->assertStatus(422)->assertJsonValidationErrors('smtp_port');
        $this->putJson($this->url(), $this->cpanel(['imap_port' => 3306]))->assertStatus(422)->assertJsonValidationErrors('imap_port');
        $this->putJson($this->url(), $this->cpanel(['password' => '']))->assertStatus(422)->assertJsonValidationErrors('password'); // na primeira vez é obrigatória
        $this->assertSame(0, Mailbox::count());
    }

    public function test_gmail_never_saves_a_copy_in_sent(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url(), $this->cpanel(['account_type' => 'gmail', 'email' => 'matilde.exemplo@gmail.com', 'username' => 'matilde.exemplo@gmail.com',
            'smtp_host' => 'smtp.gmail.com', 'imap_host' => 'imap.gmail.com', 'save_to_sent' => true]))->assertOk()
            ->assertJsonPath('data.mailbox.save_to_sent', false);
    }

    public function test_the_test_sends_to_the_own_address_from_the_mailbox_and_marks_it_active(): void
    {
        $mailbox = $this->configure($this->owner);
        $this->postJson($this->url(suffix: '/test'))->assertOk()->assertJsonPath('data.mailbox.status', Mailbox::STATUS_ACTIVE);

        $this->assertCount(1, FakeMailboxClient::$sent);
        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('matilde@exemplo.pt', $sent['from']);
        $this->assertSame('matilde@exemplo.pt', $sent['to']);
        $this->assertSame($mailbox->id, $sent['mailbox_id']);
        $this->assertNotNull($mailbox->fresh()->last_tested_at);
    }

    public function test_a_failed_test_keeps_the_reason_without_the_password(): void
    {
        $this->configure($this->owner);
        FakeMailboxClient::$failWith = 'O servidor recusou o utilizador ou a palavra-passe.';
        $res = $this->postJson($this->url(suffix: '/test'))->assertStatus(422)
            ->assertJsonPath('message', 'O servidor recusou o utilizador ou a palavra-passe.')
            ->assertJsonPath('errors.mailbox.status', Mailbox::STATUS_ERROR);
        $this->assertStringNotContainsString('segredo-de-teste-123', $res->getContent());
        $this->assertSame(Mailbox::STATUS_ERROR, Mailbox::first()->status);
        $this->assertSame('O servidor recusou o utilizador ou a palavra-passe.', Mailbox::first()->last_error);
    }

    public function test_the_test_without_a_saved_mailbox_asks_to_save_first(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url(suffix: '/test'))->assertStatus(422);
        $this->assertCount(0, FakeMailboxClient::$sent);
    }

    public function test_root_in_another_company_agency_and_other_company_get_403_on_every_route(): void
    {
        $this->configure($this->owner);
        $calls = [['getJson', ''], ['putJson', ''], ['deleteJson', ''], ['postJson', '/test']];
        foreach ([[$this->root, $this->b], [$this->agencyAdmin, $this->a], [$this->otherCompanyAdmin, $this->a]] as [$who, $company]) {
            Sanctum::actingAs($who);
            foreach ($calls as [$method, $suffix]) {
                $this->{$method}($this->url($company, $suffix), $method === 'putJson' ? $this->cpanel() : [])->assertStatus(403);
            }
        }
        Sanctum::actingAs($this->root);
        $this->getJson($this->url($this->b))->assertStatus(403)->assertJsonPath('reason', 'so_o_proprio');
        Sanctum::actingAs($this->agencyAdmin);
        $this->getJson($this->url($this->agency))->assertOk(); // na própria empresa da agência, a sua caixa
        $this->getJson($this->url())->assertStatus(403)->assertJsonPath('reason', 'so_o_proprio');

        $this->assertCount(0, FakeMailboxClient::$sent);
        $this->assertSame(1, Mailbox::count());
        $this->assertSame('segredo-de-teste-123', Mailbox::first()->password);
    }

    public function test_root_has_its_own_mailbox_only_in_its_own_company_and_never_reaches_another_persons(): void
    {
        $this->configure($this->owner);

        Sanctum::actingAs($this->root); // o root trabalha na empresa A
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.mailbox', null); // a sua, não a da Matilde
        $this->putJson($this->url(), $this->cpanel(['email' => 'simon@exemplo.pt', 'username' => 'simon@exemplo.pt']))->assertOk()
            ->assertJsonPath('data.mailbox.email', 'simon@exemplo.pt');
        $this->postJson($this->url(suffix: '/test'))->assertOk();
        $this->assertSame('simon@exemplo.pt', FakeMailboxClient::$sent[0]['from']);
        $this->deleteJson($this->url())->assertOk();

        $this->assertSame(1, Mailbox::count());
        $this->assertSame('matilde@exemplo.pt', Mailbox::personal($this->a->id, $this->owner->id)->value('email'));
        $this->getJson($this->url($this->b))->assertStatus(403);
    }

    public function test_impersonation_gets_403_even_on_the_targets_own_mailbox(): void
    {
        $this->configure($this->owner);
        $headers = $this->impersonationHeaders($this->owner);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson($this->url())->assertStatus(403)->assertJsonPath('reason', 'impersonacao');
        $this->withHeaders($headers)->putJson($this->url(), $this->cpanel(['email' => 'outro@exemplo.pt']))->assertStatus(403);
        $this->withHeaders($headers)->deleteJson($this->url())->assertStatus(403);
        $this->withHeaders($headers)->postJson($this->url(suffix: '/test'))->assertStatus(403);
        $this->assertSame('matilde@exemplo.pt', Mailbox::first()->email);
        $this->assertCount(0, FakeMailboxClient::$sent);
    }

    public function test_another_person_of_the_same_company_only_reaches_their_own_mailbox(): void
    {
        $this->configure($this->owner);

        Sanctum::actingAs($this->colleague); // admin da mesma empresa
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.mailbox', null);
        $this->postJson($this->url(suffix: '/test'))->assertStatus(422);
        $this->deleteJson($this->url())->assertOk();
        $this->putJson($this->url(), $this->cpanel(['email' => 'colega@exemplo.pt', 'username' => 'colega@exemplo.pt']))->assertOk();

        $this->assertSame(2, Mailbox::count());
        $this->assertSame('matilde@exemplo.pt', Mailbox::personal($this->a->id, $this->owner->id)->value('email'));
        $this->assertCount(0, FakeMailboxClient::$sent);
    }

    public function test_the_mailbox_is_per_company(): void
    {
        $this->configure($this->owner);
        $this->assertSame(0, Mailbox::where('company_id', $this->b->id)->count());
        Sanctum::actingAs($this->otherCompanyAdmin);
        $this->getJson($this->url($this->b))->assertOk()->assertJsonPath('data.mailbox', null);
    }

    public function test_hosts_rules_and_the_real_client_never_reaches_an_internal_server(): void
    {
        $this->assertTrue(MailboxHosts::acceptable('mail.quebom.pt'));
        $this->assertTrue(MailboxHosts::acceptable('smtp.gmail.com'));
        foreach (['db', 'redis', 'localhost', '192.168.1.10', '::1', 'mail.local', 'x.internal', ''] as $host) {
            $this->assertFalse(MailboxHosts::acceptable($host), $host);
        }
        // O cliente real (também em dev) recusa um servidor interno antes de ligar (sem ir à rede: um IP escrito à mão).
        config(['mailboxes.driver' => 'smtp']);
        $mailbox = $this->configure($this->owner);
        $mailbox->forceFill(['smtp_host' => '10.0.0.5'])->save(); // como se tivesse passado a validação
        $res = $this->postJson($this->url(suffix: '/test'))->assertStatus(422);
        $this->assertSame('O servidor 10.0.0.5 não foi encontrado ou não é um servidor de e-mail público.', $res->json('message'));
        $this->assertSame(Mailbox::STATUS_ERROR, $mailbox->fresh()->status);
        $this->assertInstanceOf(SmtpMailboxClient::class, (fn () => $this->inner)->call(app(MailboxClient::class)));
    }
}
