<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\Customer;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 5 · Escrever e responder pela caixa de correio, com o cliente e o IMAP falsos: o pedido vai
 * em JSON, tal como o ecrã o envia (e o multipart antigo também serve); a resposta segue no mesmo fio
 * (In-Reply-To e References) com o original citado; a assinatura vai no fim; sem consentimento não se
 * escreve ao cliente; os limites e, em dev, o envio só para a própria caixa; a ligação ao cliente e à
 * venda; o HTML do editor limpo; e só o próprio, na própria empresa.
 */
class MailboxComposeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $owner;
    private User $root;
    private Mailbox $mailbox;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500030001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500030002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach (['finance', 'stock', 'aftersales'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $this->mailbox = Mailbox::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE,
            'save_to_sent' => true, 'sent_folder' => 'INBOX.Sent', 'signature' => "Matilde Exemplo\nQuebom Exemplo Lda"]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Joana Cliente', 'email' => 'joana@cliente.invalid', 'contact_consent' => true]);
        FakeMailboxImap::$folders = ['INBOX' => [], 'INBOX.Sent' => ['\\Sent']];
    }

    private function url(string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . "/my-mailbox{$suffix}";
    }

    /** O pedido tal como o ecrã o envia: JSON (laravel_helper: sendMailboxMessage com JSON_BODY). */
    private function send(array $body)
    {
        return $this->postJson($this->url('/send'), $body + ['to' => '', 'cc' => '', 'subject' => '', 'body' => '']);
    }

    private function sale(bool $consent, ?Customer $customer = null): Car
    {
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id]));
        CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'female', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(), 'customer_id' => $customer?->id, 'buyer_name' => 'Rita Compradora',
            'buyer_email' => 'rita@compradora.invalid', 'contact_consent' => $consent]);

        return $car;
    }

    public function test_a_new_message_goes_in_json_with_the_signature_cleaned_html_and_a_copy_in_sent(): void
    {
        Sanctum::actingAs($this->owner);
        $this->send(['to' => 'fornecedor@exemplo.invalid', 'cc' => 'colega@exemplo.invalid', 'subject' => 'Peças para o Golf',
            'body' => '<p>Bom dia,</p><p><strong>Preciso</strong> de <a href="https://exemplo.invalid/p">duas peças</a>.</p><script>alert(1)</script><img src="https://x.invalid/t.png">'])
            ->assertOk()->assertJsonPath('message', 'Em dev, a mensagem foi só para a sua própria caixa (matilde@exemplo.pt), com os destinatários no assunto.')
            ->assertJsonPath('data.message.subject', 'Peças para o Golf');

        $message = MailMessage::sole();
        $this->assertSame(MailMessage::STATUS_SENT, $message->status);
        $this->assertSame('fornecedor@exemplo.invalid', $message->to_address);
        $this->assertSame('colega@exemplo.invalid', $message->cc_address);
        $this->assertNull($message->in_reply_to);
        $this->assertStringNotContainsString('script', (string) $message->body_html);
        $this->assertStringNotContainsString('<img', (string) $message->body_html);
        $this->assertStringContainsString('<strong>Preciso</strong>', (string) $message->body_html);
        $this->assertSame("Bom dia,\n\nPreciso de duas peças (https://exemplo.invalid/p).\n\n-- \nMatilde Exemplo\nQuebom Exemplo Lda", $message->body_text);
        $this->assertStringEndsWith('<p>-- <br>Matilde Exemplo<br>Quebom Exemplo Lda</p>', (string) $message->body_html);

        // Em dev: só para a própria caixa, sem Cc, com os destinatários no assunto.
        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('matilde@exemplo.pt', $sent['to']);
        $this->assertSame([], $sent['cc']);
        $this->assertSame('[dev, para: fornecedor@exemplo.invalid; cc: colega@exemplo.invalid] Peças para o Golf', $sent['subject']);
        $this->assertSame('Matilde Exemplo', $sent['from_name']);
        // A cópia em Enviados.
        $this->assertSame('INBOX.Sent', FakeMailboxImap::$appended[0]['folder']);
    }

    public function test_the_old_multipart_request_also_works(): void
    {
        Sanctum::actingAs($this->owner);
        $this->post($this->url('/send'), ['to' => 'fornecedor@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>',
            'reply_to' => ['folder' => 'INBOX', 'uid' => (string) FakeMailboxImap::put('INBOX', $this->eml())]], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame('<boletim-99@novidades.invalid>', MailMessage::sole()->in_reply_to);
    }

    public function test_a_reply_stays_in_the_thread_with_re_and_the_original_quoted(): void
    {
        $uid = FakeMailboxImap::put('INBOX', preg_replace('/^(Message-ID: <boletim-99@novidades\.invalid>)(\r?\n)/m', "$1$2References: <inicio@novidades.invalid>$2", $this->eml()));
        Sanctum::actingAs($this->owner);
        $this->send(['to' => 'boletim@novidades.invalid', 'subject' => 'Re: As novidades da semana', 'body' => '<p>Obrigada!</p>',
            'reply_to' => ['folder' => 'INBOX', 'uid' => $uid]])->assertOk();

        $message = MailMessage::sole();
        $this->assertSame('<boletim-99@novidades.invalid>', $message->in_reply_to);
        $this->assertSame('<inicio@novidades.invalid> <boletim-99@novidades.invalid>', $message->references);
        $this->assertSame("Obrigada!\n\n-- \nMatilde Exemplo\nQuebom Exemplo Lda\n\n"
            . "Em 27 de outubro de 2026, às 17:00, Boletim <boletim@novidades.invalid> escreveu:\n> Esta semana temos novidades.", $message->body_text);
        $this->assertStringContainsString('<blockquote', (string) $message->body_html);
        $this->assertStringContainsString('Em 27 de outubro de 2026, às 17:00, Boletim &lt;boletim@novidades.invalid&gt; escreveu:', (string) $message->body_html);

        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('<boletim-99@novidades.invalid>', $sent['in_reply_to']);
        $this->assertSame(['<inicio@novidades.invalid>', '<boletim-99@novidades.invalid>'], $sent['references']);
    }

    public function test_without_a_signature_nothing_is_added(): void
    {
        $this->mailbox->forceFill(['signature' => null])->save();
        Sanctum::actingAs($this->owner);
        $this->send(['to' => 'fornecedor@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertOk();
        $this->assertSame('Olá', MailMessage::sole()->body_text);
        $this->assertSame('<p>Olá</p>', MailMessage::sole()->body_html);
    }

    public function test_writing_to_the_customer_links_the_message_and_consent_is_mandatory(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url("/compose-context?customer_id={$this->customer->id}"))->assertOk()
            ->assertJsonPath('data.to', 'joana@cliente.invalid')->assertJsonPath('data.name', 'Joana Cliente')
            ->assertJsonPath('data.consent', true)->assertJsonPath('data.blocked', null)
            ->assertJsonPath('data.mailbox.signature', "Matilde Exemplo\nQuebom Exemplo Lda");
        $this->send(['to' => 'joana@cliente.invalid', 'subject' => 'A sua viatura', 'body' => '<p>Está pronta.</p>', 'customer_id' => $this->customer->id])->assertOk();
        $this->assertSame($this->customer->id, MailMessage::sole()->customer_id);
        // Aparece na "Conversa" da ficha.
        $this->getJson("/api/v1/companies/{$this->company->id}/customers/{$this->customer->id}/mail-messages")->assertOk()
            ->assertJsonPath('data.messages.0.subject', 'A sua viatura');

        $this->customer->forceFill(['contact_consent' => false])->save();
        $this->getJson($this->url("/compose-context?customer_id={$this->customer->id}"))->assertOk()->assertJsonPath('data.consent', false);
        $refused = 'O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe.';
        $this->send(['to' => 'joana@cliente.invalid', 'subject' => 'Outra', 'body' => '<p>Olá</p>', 'customer_id' => $this->customer->id])
            ->assertStatus(422)->assertJsonPath('message', $refused);
        // Pela caixa, sem a ficha, o endereço do cliente também é recusado (no Para ou no Cc).
        $this->send(['to' => 'fornecedor@exemplo.invalid', 'cc' => 'JOANA@cliente.invalid', 'subject' => 'Outra', 'body' => '<p>Olá</p>'])
            ->assertStatus(422)->assertJsonPath('message', 'O cliente joana@cliente.invalid não deu consentimento para contacto (RGPD): não se pode escrever-lhe.');
        $this->assertSame(1, MailMessage::count());
        $this->assertCount(1, FakeMailboxClient::$sent);
    }

    public function test_writing_from_the_sale_links_customer_sale_and_lead_and_respects_its_consent(): void
    {
        Sanctum::actingAs($this->owner);
        $car = $this->sale(true, $this->customer);
        $this->getJson($this->url("/compose-context?car_id={$car->id}"))->assertOk()->assertJsonPath('data.to', 'joana@cliente.invalid')->assertJsonPath('data.consent', true);
        $this->send(['to' => 'joana@cliente.invalid', 'subject' => 'A sua viatura', 'body' => '<p>Olá</p>', 'car_id' => $car->id])->assertOk();
        $message = MailMessage::sole();
        $this->assertSame($this->customer->id, $message->customer_id);
        $this->assertSame($car->sale->id, $message->car_sale_id);

        // Venda sem ficha de cliente e sem consentimento: recusada, e o comprador também pela caixa.
        $without = $this->sale(false);
        $this->getJson($this->url("/compose-context?car_id={$without->id}"))->assertOk()->assertJsonPath('data.to', 'rita@compradora.invalid')
            ->assertJsonPath('data.name', 'Rita Compradora')->assertJsonPath('data.consent', false);
        $this->send(['to' => 'rita@compradora.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>', 'car_id' => $without->id])->assertStatus(422);
        $this->send(['to' => 'rita@compradora.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertStatus(422);

        // A viatura sem venda e a de outra empresa.
        $unsold = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id]));
        $this->getJson($this->url("/compose-context?car_id={$unsold->id}"))->assertStatus(422)->assertJsonPath('message', 'Esta viatura ainda não tem uma venda registada.');
        $foreign = Customer::create(['company_id' => $this->other->id, 'name' => 'Cliente Alheio', 'email' => 'alheio@exemplo.invalid', 'contact_consent' => true]);
        $this->getJson($this->url("/compose-context?customer_id={$foreign->id}"))->assertStatus(422)->assertJsonPath('message', 'Cliente não encontrado.');
        $this->assertSame(1, MailMessage::count());
    }

    public function test_refusals_show_the_reason_and_nothing_is_sent(): void
    {
        Sanctum::actingAs($this->owner);
        $this->send(['to' => '', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertStatus(422)->assertJsonPath('message', 'Indique pelo menos um destinatário.');
        $this->send(['to' => 'nao-e-um-endereco', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertStatus(422)->assertJsonPath('message', 'O endereço "nao-e-um-endereco" não é válido.');
        $this->send(['to' => 'a@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p><br></p>'])->assertStatus(422)->assertJsonPath('message', 'Escreva a mensagem.');
        $many = implode(', ', array_map(fn ($i) => "p{$i}@exemplo.invalid", range(1, 11)));
        $this->send(['to' => $many, 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertStatus(422)->assertJsonPath('message', 'No máximo 10 destinatários por mensagem (Para e Cc).');
        $this->send(['to' => 'a@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>', 'reply_to' => ['folder' => 'INBOX', 'uid' => 999]])
            ->assertStatus(422)->assertJsonPath('message', 'A mensagem a que responde já não existe na caixa.');
        $this->send(['to' => 'a@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>', 'reply_to' => ['folder' => 'Inventada', 'uid' => 1]])
            ->assertStatus(422)->assertJsonPath('message', 'Essa pasta não existe na caixa.');

        // Por testar: não envia.
        $this->mailbox->forceFill(['status' => Mailbox::STATUS_PENDING])->save();
        $this->send(['to' => 'a@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])->assertStatus(422)
            ->assertJsonPath('message', 'Teste primeiro a sua caixa de correio no Perfil (Testar envio).');
        $this->assertSame(0, MailMessage::count());
        $this->assertSame([], FakeMailboxClient::$sent);
    }

    public function test_the_hourly_limit_applies(): void
    {
        config(['mailboxes.limits.per_hour' => 2]);
        Sanctum::actingAs($this->owner);
        foreach ([1, 2] as $i) {
            $this->send(['to' => 'a@exemplo.invalid', 'subject' => "N.º {$i}", 'body' => '<p>Olá</p>'])->assertOk();
        }
        $this->send(['to' => 'a@exemplo.invalid', 'subject' => 'N.º 3', 'body' => '<p>Olá</p>'])->assertStatus(422)
            ->assertJsonPath('message', 'A sua caixa chegou ao limite de 2 envios por hora. Tente mais tarde.');
        $this->assertSame(2, MailMessage::count());
    }

    public function test_in_production_it_goes_to_the_real_recipients_with_the_cc(): void
    {
        $this->app['env'] = 'production';
        Sanctum::actingAs($this->owner);
        $this->send(['to' => 'fornecedor@exemplo.invalid; outro@exemplo.invalid', 'cc' => 'colega@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>'])
            ->assertOk()->assertJsonPath('message', 'Mensagem enviada pela sua caixa.');
        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('fornecedor@exemplo.invalid, outro@exemplo.invalid', $sent['to']);
        $this->assertSame(['colega@exemplo.invalid'], $sent['cc']);
        $this->assertSame('Olá', $sent['subject']);
    }

    public function test_only_the_owner_writes_and_never_in_impersonation(): void
    {
        $body = ['to' => 'a@exemplo.invalid', 'subject' => 'Olá', 'body' => '<p>Olá</p>'];
        $agency = Company::create(['nipc' => '500030003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->company->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->company->id, 'team_scope' => 'all', 'requested_at' => now()]);
        foreach ([$this->root, User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']), User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin'])] as $who) {
            Sanctum::actingAs($who);
            $this->send($body)->assertStatus(403);
            $this->getJson($this->url("/compose-context?customer_id={$this->customer->id}"))->assertStatus(403);
        }
        // Outra pessoa da mesma empresa: só a sua caixa (nenhuma), nunca a da Matilde.
        Sanctum::actingAs(User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']));
        $this->send($body)->assertStatus(422)->assertJsonPath('message', 'Ligue primeiro a sua caixa de correio no Perfil.');
        $this->getJson($this->url('/compose-context'))->assertOk()->assertJsonPath('data.mailbox', null)
            ->assertJsonPath('data.blocked', 'Ligue primeiro a sua caixa de correio no Perfil.');

        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])->postJson($this->url('/send'), $body)->assertStatus(403);
        $this->assertSame(0, MailMessage::count());
        $this->assertSame([], FakeMailboxClient::$sent);
    }

    private function eml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/mail/sem-relacao.eml'));
    }
}
