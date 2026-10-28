<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancingLink;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use App\Services\Mailbox\LinkMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 9 · O link de financiamento e o de retoma enviados pela caixa, com os clientes falsos: a
 * janela abre preenchida (o endereço da lead, o assunto, o texto com o link e o prazo); o texto que sai
 * leva o link certo e a cópia em Enviados também; a cópia guardada na XPLENDOR (e a "Conversa" da lead)
 * nunca leva o token; a mensagem fica ligada à lead, ao link e ao cliente; sem caixa; sem
 * consentimento; as permissões de cada link; 403 para outra empresa, o root e a impersonation.
 */
class MailboxLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $admin;
    private Mailbox $mailbox;
    private CarLead $lead;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        config(['storage_targets.private_disk' => 'local', 'financing.require_antivirus' => false]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500070001', 'fiscal_name' => 'Quebom Exemplo Lda', 'trade_name' => 'Quebom', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500070002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
            app(CompanyModuleService::class)->enable($c->id, 'commercial_crm');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->mailbox = Mailbox::create(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE,
            'save_to_sent' => true, 'sent_folder' => 'INBOX.Sent', 'signature' => 'Matilde Exemplo']);
        FakeMailboxImap::$folders = ['INBOX' => [], 'INBOX.Sent' => ['\\Sent']];
        $this->car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id]));
        $this->lead = CarLead::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'name' => 'Joana Cliente', 'email' => 'joana@cliente.invalid',
            'type' => 'proposta', 'trade_in' => ['brand' => 'Renault', 'model' => 'Clio'], 'consent_contact_at' => now(), 'consent_contact_text' => 'Autorizo o contacto.']);
    }

    private function base(?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id;
    }

    /** Os links criados como no ecrã (o cartão do financiamento e o da retoma da lead). */
    private function financingLink(): FinancingLink
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->base() . "/leads/{$this->lead->id}/financing-links", ['customer_name' => 'Joana Cliente', 'items' => ['iban']])->assertCreated();

        return FinancingLink::whereHas('request', fn ($q) => $q->where('purpose', 'financiamento'))->latest('id')->firstOrFail();
    }

    private function tradeInLink(): FinancingLink
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->base() . "/leads/{$this->lead->id}/trade-in/link", ['days' => 5])->assertCreated();

        return FinancingLink::whereHas('request', fn ($q) => $q->where('purpose', 'retoma'))->latest('id')->firstOrFail();
    }

    private function context(string $kind, FinancingLink $link, ?Company $c = null)
    {
        return $this->getJson($this->base($c) . "/my-mailbox/link-context?kind={$kind}&link_id={$link->id}");
    }

    /** O envio tal como o ecrã o faz: JSON, com o texto (que a pessoa pode mudar) e o link. */
    private function send(string $kind, FinancingLink $link, array $over = [])
    {
        $ctx = $this->context($kind, $link)->assertOk()->json('data');

        return $this->postJson($this->base() . '/my-mailbox/send', $over + ['to' => (string) $ctx['to'], 'cc' => '', 'subject' => $ctx['subject'], 'body' => $ctx['body'],
            'attachments' => [], 'link' => ['kind' => $kind, 'id' => $link->id]]);
    }

    public function test_financing_the_window_opens_filled_the_client_gets_the_link_and_the_stored_copy_never_has_the_token(): void
    {
        $link = $this->financingLink();
        $url = $link->url();
        $until = $link->expires_at->format('d/m/Y');
        $ctx = $this->context('financing', $link)->assertOk()
            ->assertJsonPath('data.to', 'joana@cliente.invalid')->assertJsonPath('data.consent', true)
            ->assertJsonPath('data.subject', 'Documentos para o financiamento · Quebom')
            ->assertJsonPath('data.mailbox.signature', 'Matilde Exemplo')->assertJsonPath('data.blocked', null)->json('data');
        $this->assertSame('<p>Olá, Joana Cliente,</p><p>Para avançarmos com o pedido de financiamento, envie-nos os documentos pedidos através deste link seguro:</p>'
            . "<p><a href=\"{$url}\">{$url}</a></p><p>O link é pessoal e válido até {$until}. Por favor, não o partilhe com ninguém.</p><p>Com os melhores cumprimentos,</p>", $ctx['body']);

        $this->send('financing', $link)->assertOk();

        // O que sai (em dev, para a própria caixa) leva o link completo e a assinatura; a cópia em Enviados também.
        $sent = FakeMailboxClient::$sent[0];
        $this->assertStringContainsString($url, $sent['text']);
        $this->assertStringContainsString("href=\"{$url}\"", (string) $sent['html']);
        $this->assertStringContainsString("-- \nMatilde Exemplo", $sent['text']);
        $this->assertStringContainsString($link->token(), FakeMailboxImap::$appended[0]['raw']);

        // A cópia guardada: sem o token, com o aviso; o texto completo cifrado já foi apagado.
        $message = MailMessage::sole();
        $token = $link->token();
        foreach ([(string) $message->body_text, (string) $message->body_html] as $stored) {
            $this->assertStringNotContainsString($token, $stored);
            $this->assertStringContainsString(LinkMail::HIDDEN, $stored);
        }
        $this->assertNull($message->outgoing_secret);
        $this->assertNull(DB::table('mail_messages')->value('outgoing_secret'));
        $this->assertStringNotContainsString($token, json_encode(DB::table('mail_messages')->first()));
        // Ligada à lead e ao link; aparece na "Conversa" da lead, sem o token.
        $this->assertSame($this->lead->id, $message->car_lead_id);
        $this->assertSame($link->id, $message->financing_link_id);
        $conversation = $this->getJson($this->base() . "/leads/{$this->lead->id}/mail-messages")->assertOk()
            ->assertJsonPath('data.messages.0.subject', 'Documentos para o financiamento · Quebom');
        $this->assertStringNotContainsString($token, $conversation->getContent());
        $this->assertStringContainsString(LinkMail::HIDDEN, $conversation->json('data.messages.0.text'));
        // No HTML guardado, a ligação passa a texto (sem href para o link).
        $this->assertStringContainsString('<p><em>' . LinkMail::HIDDEN . '</em></p>', $conversation->json('data.messages.0.html'));
        $this->assertStringNotContainsString('href="[', $conversation->json('data.messages.0.html'));
    }

    public function test_trade_in_text_and_the_person_can_change_it_but_not_remove_the_link(): void
    {
        $link = $this->tradeInLink();
        $url = $link->url();
        $ctx = $this->context('trade_in', $link)->assertOk()->assertJsonPath('data.subject', 'Avaliação da sua retoma · Quebom')->json('data');
        $this->assertSame('<p>Olá, Joana Cliente,</p><p>Obrigado pelo seu pedido. Para avaliarmos a sua retoma, envie-nos as fotografias da viatura através deste link seguro:</p>'
            . "<p><a href=\"{$url}\">{$url}</a></p><p>O link é válido até {$link->expires_at->format('d/m/Y')}.</p><p>Com os melhores cumprimentos,</p>", $ctx['body']);

        // Sem o link no texto: recusado (nada sai).
        $this->send('trade_in', $link, ['body' => '<p>Olá, envie as fotografias.</p>'])->assertStatus(422)
            ->assertJsonPath('message', 'O texto tem de levar o link. Volte a abrir "Enviar por email" para o repor.');
        // Mudado, com o link: sai como a pessoa o escreveu.
        $this->send('trade_in', $link, ['body' => "<p>Boa tarde! Aqui está o link: {$url}</p>"])->assertOk();
        $this->assertStringContainsString("Boa tarde! Aqui está o link: {$url}", FakeMailboxClient::$sent[0]['text']);
        $this->assertStringNotContainsString($link->token(), (string) MailMessage::sole()->body_text);
        $this->assertSame($this->lead->id, MailMessage::sole()->car_lead_id);
    }

    public function test_linked_to_the_customer_if_there_is_one(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Joana Cliente', 'email' => 'joana@cliente.invalid', 'contact_consent' => true]);
        $link = $this->financingLink();
        $this->send('financing', $link)->assertOk();
        $this->assertSame($customer->id, MailMessage::sole()->customer_id);
        // E aparece também na "Conversa" da ficha do cliente (sem o token).
        $res = $this->getJson($this->base() . "/customers/{$customer->id}/mail-messages")->assertOk()->assertJsonPath('data.messages.0.subject', 'Documentos para o financiamento · Quebom');
        $this->assertStringNotContainsString($link->token(), $res->getContent());
    }

    public function test_without_mailbox_the_context_says_so_and_nothing_is_sent(): void
    {
        $link = $this->financingLink();
        $this->mailbox->delete();
        $this->context('financing', $link)->assertOk()->assertJsonPath('data.mailbox', null)
            ->assertJsonPath('data.blocked', 'Ligue primeiro a sua caixa de correio no Perfil.');
        $this->postJson($this->base() . '/my-mailbox/send', ['to' => 'joana@cliente.invalid', 'subject' => 'x', 'body' => "<p>{$link->url()}</p>", 'link' => ['kind' => 'financing', 'id' => $link->id]])
            ->assertStatus(422)->assertJsonPath('message', 'Ligue primeiro a sua caixa de correio no Perfil.');
        $this->assertSame([], FakeMailboxClient::$sent);
    }

    public function test_without_contact_consent_the_warning_and_the_send_refused(): void
    {
        $this->lead->forceFill(['consent_contact_at' => null])->save();
        $link = $this->financingLink();
        $this->context('financing', $link)->assertOk()->assertJsonPath('data.consent', false);
        $this->send('financing', $link)->assertStatus(422)->assertJsonPath('message', 'O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe.');
        $this->assertSame(0, MailMessage::count());
    }

    public function test_an_inactive_link_or_the_wrong_kind_is_refused(): void
    {
        $link = $this->financingLink();
        $this->context('trade_in', $link)->assertNotFound();
        $link->forceFill(['revoked_at' => now()])->save();
        $this->context('financing', $link)->assertStatus(422)->assertJsonPath('message', 'Este link já não está ativo. Crie um link novo.');
    }

    public function test_the_permissions_of_each_link_and_403_for_other_company_root_and_impersonation(): void
    {
        $financing = $this->financingLink();
        $tradeIn = $this->tradeInLink();

        // Quem trata as leads sem a área "Financiamento": a retoma sim, o financiamento não.
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => PermissionProfile::SIDE_CLIENT, 'name' => 'Leads', 'is_system' => false]);
        $profile->syncPermissions(['empresa.ver', 'automovel.ver', 'automovel.editar']);
        $seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
        Mailbox::create(['company_id' => $this->company->id, 'user_id' => $seller->id, 'account_type' => 'cpanel', 'email' => 'vendedor@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'vendedor@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
        Sanctum::actingAs($seller);
        $this->context('trade_in', $tradeIn)->assertOk();
        $this->context('financing', $financing)->assertStatus(403);
        $this->postJson($this->base() . '/my-mailbox/send', ['to' => 'joana@cliente.invalid', 'subject' => 'x', 'body' => "<p>{$financing->url()}</p>", 'link' => ['kind' => 'financing', 'id' => $financing->id]])
            ->assertStatus(403);

        // Outra empresa: o link de A não existe para ela (e a rota da caixa de A é 403).
        $outsider = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        Sanctum::actingAs($outsider);
        $this->context('financing', $financing)->assertStatus(403);
        $this->context('financing', $financing, $this->other)->assertNotFound();
        $this->getJson($this->base() . "/leads/{$this->lead->id}/mail-messages")->assertStatus(403);

        // O root noutra empresa: 403 (a caixa e a "Conversa" da lead).
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        Sanctum::actingAs($root);
        $this->context('financing', $financing)->assertStatus(403);
        $this->getJson($this->base() . "/leads/{$this->lead->id}/mail-messages")->assertStatus(403);

        // Impersonation: 403.
        $token = $this->admin->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $root->id, 'target_user_id' => $this->admin->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken];
        $this->withHeaders($headers)->getJson($this->base() . "/my-mailbox/link-context?kind=financing&link_id={$financing->id}")->assertStatus(403);
        $this->withHeaders($headers)->postJson($this->base() . '/my-mailbox/send', ['to' => 'joana@cliente.invalid', 'subject' => 'x', 'body' => "<p>{$financing->url()}</p>",
            'link' => ['kind' => 'financing', 'id' => $financing->id]])->assertStatus(403);
        $this->withHeaders($headers)->getJson($this->base() . "/leads/{$this->lead->id}/mail-messages")->assertStatus(403);
        $this->assertSame(0, MailMessage::count());
    }
}
