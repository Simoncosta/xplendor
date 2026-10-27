<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SatisfactionReportMail;
use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\SatisfactionReport;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\SmtpMailboxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 2 · Enviar o link de pós-venda pela minha caixa. Com via=mailbox, a mensagem sai pela
 * caixa de quem envia (o From é o da caixa), fica em mail_messages com o Message-ID e as ligações
 * (cliente, venda, lead, relatório) e corre num job. Sem caixa ou sem via=mailbox, como até aqui.
 * O consentimento e o limite por caixa contam; durante a impersonation não se usa a caixa da pessoa.
 */
class SatisfactionMailboxSendTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $seller;
    private User $root;
    private Car $car;
    private CarSale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        FakeMailboxClient::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500018001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($this->company->id, 'aftersales');
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id]));
        $this->sale = CarSale::create(['car_id' => $this->car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'male',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now(), 'buyer_name' => 'Joana Cliente',
            'buyer_email' => 'joana@cliente.invalid', 'contact_consent' => true]);
    }

    private function mailbox(User $user, array $over = []): Mailbox
    {
        return Mailbox::create($over + ['company_id' => $this->company->id, 'user_id' => $user->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
    }

    private function send(array $body = ['via' => 'mailbox'])
    {
        return $this->postJson("/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/satisfaction-report/send-email", $body);
    }

    public function test_via_my_mailbox_the_from_is_the_mailbox_and_the_message_id_is_saved_with_the_links(): void
    {
        $mailbox = $this->mailbox($this->seller);
        Sanctum::actingAs($this->seller);
        $this->app['env'] = 'production'; // em produção, para o cliente (fora dela, ver o teste do DevRecipientGuard)
        $this->send()->assertOk()->assertJsonPath('data.via', 'mailbox')->assertJsonPath('message', 'Email enviado pela sua caixa (matilde@exemplo.pt).');

        $this->assertCount(1, FakeMailboxClient::$sent);
        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('matilde@exemplo.pt', $sent['from']);
        $this->assertSame('Matilde Exemplo', $sent['from_name']);
        $this->assertSame('joana@cliente.invalid', $sent['to']);
        $this->assertSame('A sua nova viatura — Quebom Exemplo Lda', $sent['subject']);
        $link = '/r/' . SatisfactionReport::sole()->public_token;
        $this->assertStringContainsString($link, $sent['text']);
        $this->assertStringContainsString($link, (string) $sent['html']);
        Mail::assertNothingQueued();

        $message = MailMessage::sole();
        $report = SatisfactionReport::sole();
        $this->assertSame($sent['message_id'], $message->message_id);
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@exemplo\.pt>$/', $message->message_id);
        $this->assertSame(MailMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame([$mailbox->id, $this->seller->id, MailMessage::OUT, 'matilde@exemplo.pt', 'joana@cliente.invalid'],
            [$message->mailbox_id, $message->user_id, $message->direction, $message->from_address, $message->to_address]);
        $this->assertSame([$this->sale->id, $report->id], [$message->car_sale_id, $message->satisfaction_report_id]);
        $this->assertSame('email', $report->sent_channel);
    }

    public function test_without_via_mailbox_it_goes_as_today_by_the_platform(): void
    {
        $this->mailbox($this->seller);
        Sanctum::actingAs($this->seller);
        $this->send([])->assertOk();
        Mail::assertQueued(SatisfactionReportMail::class);
        $this->assertCount(0, FakeMailboxClient::$sent);
        $this->assertSame(0, MailMessage::count());
    }

    public function test_without_a_mailbox_or_with_an_untested_one_it_asks_first(): void
    {
        Sanctum::actingAs($this->seller);
        $this->send()->assertStatus(422)->assertJsonPath('message', 'Ligue primeiro a sua caixa de correio no Perfil.');
        $this->mailbox($this->seller, ['status' => Mailbox::STATUS_PENDING]);
        $this->send()->assertStatus(422)->assertJsonPath('message', 'Teste primeiro a sua caixa de correio no Perfil (Testar envio).');
        $this->assertSame(0, MailMessage::count());
        Mail::assertNothingQueued();
    }

    public function test_it_never_uses_another_persons_mailbox(): void
    {
        $this->mailbox($this->root); // o root tem caixa; quem envia (Matilde) não
        Sanctum::actingAs($this->seller);
        $this->send()->assertStatus(422);
        $this->assertCount(0, FakeMailboxClient::$sent);
    }

    public function test_contact_consent_is_required(): void
    {
        $this->mailbox($this->seller);
        $this->sale->forceFill(['contact_consent' => false])->save();
        Sanctum::actingAs($this->seller);
        $this->send()->assertStatus(422);
        $this->assertSame(0, MailMessage::count());
        $this->assertCount(0, FakeMailboxClient::$sent);
    }

    public function test_the_limit_per_mailbox_is_30_per_hour_and_200_per_day(): void
    {
        $mailbox = $this->mailbox($this->seller);
        $make = fn (int $n, $at) => collect(range(1, $n))->each(fn () => MailMessage::forceCreate(['company_id' => $this->company->id, 'mailbox_id' => $mailbox->id,
            'direction' => MailMessage::OUT, 'from_address' => 'matilde@exemplo.pt', 'to_address' => 'x@cliente.invalid', 'subject' => 'S',
            'status' => MailMessage::STATUS_SENT, 'created_at' => $at, 'updated_at' => $at]));
        Sanctum::actingAs($this->seller);

        $make(29, now()->subMinutes(30));
        $this->send()->assertOk(); // a 30.ª da hora
        $this->send()->assertStatus(422)->assertJsonPath('message', 'A sua caixa chegou ao limite de 30 envios por hora. Tente mais tarde.');

        MailMessage::query()->update(['created_at' => now()->subHours(2)]);
        $make(170, now()->subHours(5)); // 200 nas últimas 24 horas
        $this->send()->assertStatus(422)->assertJsonPath('message', 'A sua caixa chegou ao limite de 200 envios por dia. Tente amanhã.');
        $this->assertCount(1, FakeMailboxClient::$sent);
    }

    public function test_during_impersonation_the_persons_mailbox_is_not_used(): void
    {
        $this->mailbox($this->seller);
        $token = $this->seller->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->seller->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])->postJson(
            "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/satisfaction-report/send-email", ['via' => 'mailbox'])->assertStatus(422);
        $this->assertCount(0, FakeMailboxClient::$sent);
        $this->assertSame(0, MailMessage::count());
    }

    public function test_a_failed_send_keeps_the_reason_on_the_message(): void
    {
        $this->mailbox($this->seller);
        FakeMailboxClient::$failWith = 'O servidor recusou o utilizador ou a palavra-passe.';
        Sanctum::actingAs($this->seller);
        $this->send()->assertOk(); // o pedido entra na fila; o erro fica na mensagem
        $message = MailMessage::sole();
        $this->assertSame(MailMessage::STATUS_ERROR, $message->status);
        $this->assertSame('O servidor recusou o utilizador ou a palavra-passe.', $message->error);
    }

    public function test_the_saved_message_id_is_ours_even_if_the_server_answers_with_its_queue_id(): void
    {
        // O SMTP responde "250 Ok: queued as XYZ"; o fio das respostas usa o Message-ID da mensagem.
        $this->app->bind(MailboxClient::class, fn () => new class implements MailboxClient {
            public function send(\App\Models\Mailbox $mailbox, \App\Services\Mailbox\OutgoingMail $mail): \App\Services\Mailbox\MailboxSendResult
            {
                return \App\Services\Mailbox\MailboxSendResult::sent('<queued-as-XYZ>');
            }
        });
        $this->mailbox($this->seller);
        Sanctum::actingAs($this->seller);
        $this->send()->assertOk();
        $message = MailMessage::sole();
        $this->assertSame(MailMessage::STATUS_SENT, $message->status);
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@exemplo\.pt>$/', $message->message_id);
    }

    public function test_outside_production_it_goes_only_to_the_mailbox_itself_with_the_client_in_the_subject(): void
    {
        $this->assertFalse(app()->environment('production'));
        $this->mailbox($this->seller);
        Sanctum::actingAs($this->seller);
        $this->send()->assertOk()->assertJsonPath('message', 'Em dev, a mensagem foi só para a sua própria caixa (matilde@exemplo.pt), com o cliente no assunto.');

        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame('matilde@exemplo.pt', $sent['to']); // nunca para a cliente
        $this->assertSame('[dev, para: joana@cliente.invalid] A sua nova viatura — Quebom Exemplo Lda', $sent['subject']);
        $this->assertSame('matilde@exemplo.pt', $sent['from']);
        $this->assertSame('joana@cliente.invalid', MailMessage::sole()->to_address); // o registo guarda a quem se destinava
    }

    public function test_in_production_it_goes_to_the_real_recipient(): void
    {
        $this->app['env'] = 'production';
        $this->mailbox($this->seller);
        Sanctum::actingAs($this->seller);
        $this->send()->assertOk();
        $this->assertSame('joana@cliente.invalid', FakeMailboxClient::$sent[0]['to']);
        $this->assertStringStartsNotWith('[dev', FakeMailboxClient::$sent[0]['subject']);
    }

    public function test_the_real_client_never_reaches_an_internal_server(): void
    {
        config(['mailboxes.driver' => 'smtp']);
        $this->mailbox($this->seller, ['smtp_host' => '192.168.1.20']);
        Sanctum::actingAs($this->seller);
        $this->send()->assertOk();
        $message = MailMessage::sole();
        $this->assertSame(MailMessage::STATUS_ERROR, $message->status);
        $this->assertSame('O servidor 192.168.1.20 não foi encontrado ou não é um servidor de e-mail público.', $message->error);
    }
}
