<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CountMailboxUnreadJob;
use App\Jobs\DispatchMailboxUnreadCountsJob;
use App\Jobs\SendMailboxMessageJob;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use App\Services\Mailbox\MailboxImap;
use App\Services\Mailbox\MailboxSender;
use App\Services\Mailbox\SentFolder;
use App\Services\Mailbox\WebklexMailboxImap;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 3 · IMAP com o cliente falso: "Testar IMAP" descobre a pasta de Enviados (marca \Sent) e
 * conta os não lidos; depois de cada envio, a cópia em Enviados (APPEND) no cPanel e nunca no
 * smtp.gmail.com; os não lidos de 5 em 5 minutos, um job por caixa na fila mail, dentro do
 * SCHEDULE_EXTERNAL_JOBS; os erros com o motivo; o número só para o próprio.
 */
class MailboxImapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $owner;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500019002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'name' => 'Matilde Exemplo']);
        $this->root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
    }

    private function mailbox(array $over = []): Mailbox
    {
        return Mailbox::create($over + ['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'save_to_sent' => true, 'status' => Mailbox::STATUS_ACTIVE]);
    }

    private function gmail(): Mailbox
    {
        return $this->mailbox(['account_type' => 'gmail', 'email' => 'matilde.exemplo@gmail.com', 'username' => 'matilde.exemplo@gmail.com',
            'smtp_host' => 'smtp.gmail.com', 'imap_host' => 'imap.gmail.com', 'save_to_sent' => false]);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->company->id}/my-mailbox{$suffix}";
    }

    private function queueSend(Mailbox $mailbox): MailMessage
    {
        return app(MailboxSender::class)->queue($mailbox, $this->owner, 'joana@cliente.invalid', 'Assunto', 'Texto', '<p>Texto</p>');
    }

    public function test_test_imap_finds_the_sent_folder_by_the_special_use_flag_and_counts_the_unread(): void
    {
        $mailbox = $this->mailbox();
        FakeMailboxImap::$unseen = 4;
        FakeMailboxImap::$uidvalidity = 1690000000;
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url('/test-imap'))->assertOk()
            ->assertJsonPath('message', 'IMAP ligado: 4 por ler na Entrada. Pasta de Enviados: INBOX.Sent.')
            ->assertJsonPath('data.mailbox.imap_status', Mailbox::STATUS_ACTIVE)
            ->assertJsonPath('data.mailbox.sent_folder', 'INBOX.Sent')
            ->assertJsonPath('data.mailbox.unread_count', 4)
            ->assertJsonMissingPath('data.mailbox.password');
        $this->assertSame(1690000000, $mailbox->fresh()->uidvalidity);
    }

    public function test_the_sent_folder_without_the_flag_comes_from_the_usual_names(): void
    {
        $this->assertSame('INBOX.Sent', SentFolder::find(['INBOX' => [], 'INBOX.Sent' => ['\\HasNoChildren'], 'INBOX.Drafts' => []]));
        $this->assertSame('Itens Enviados', SentFolder::find(['INBOX' => [], 'Itens Enviados' => []]));
        $this->assertSame('[Gmail]/Enviados', SentFolder::find(['INBOX' => [], '[Gmail]/Enviados' => ['\\HasNoChildren', '\\sent'], 'Sent' => []]));
        $this->assertNull(SentFolder::find(['INBOX' => [], 'Arquivo' => []]));
    }

    public function test_an_imap_error_keeps_the_reason_without_the_password(): void
    {
        $mailbox = $this->mailbox();
        FakeMailboxImap::$failWith = 'O servidor IMAP recusou o utilizador ou a palavra-passe.';
        Sanctum::actingAs($this->owner);
        $res = $this->postJson($this->url('/test-imap'))->assertStatus(422)
            ->assertJsonPath('message', 'O servidor IMAP recusou o utilizador ou a palavra-passe.')
            ->assertJsonPath('errors.mailbox.imap_status', Mailbox::STATUS_ERROR);
        $this->assertStringNotContainsString('segredo-de-teste', $res->getContent());
        $this->assertSame('O servidor IMAP recusou o utilizador ou a palavra-passe.', $mailbox->fresh()->imap_error);
    }

    public function test_after_each_send_cpanel_gets_the_copy_in_sent_and_gmail_does_not(): void
    {
        $cpanel = $this->mailbox();
        $message = $this->queueSend($cpanel);
        $this->assertCount(1, FakeMailboxImap::$appended);
        $this->assertSame('INBOX.Sent', FakeMailboxImap::$appended[0]['folder']);
        $this->assertStringContainsString('Message-ID: ' . $message->message_id, FakeMailboxImap::$appended[0]['raw']);
        $this->assertSame('INBOX.Sent', $cpanel->fresh()->sent_folder); // descoberta no primeiro envio e guardada
        $this->assertSame([MailMessage::STATUS_SENT, null], [$message->fresh()->status, $message->fresh()->error]);

        $cpanel->delete();
        $this->queueSend($this->gmail());
        $this->assertCount(1, FakeMailboxImap::$appended); // o Gmail já guarda a cópia
        $this->assertCount(2, FakeMailboxClient::$sent);
    }

    public function test_without_save_to_sent_there_is_no_copy_and_the_test_message_also_gets_one(): void
    {
        $mailbox = $this->mailbox(['save_to_sent' => false]);
        $this->queueSend($mailbox);
        $this->assertCount(0, FakeMailboxImap::$appended);

        $mailbox->forceFill(['save_to_sent' => true])->save();
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url('/test'))->assertOk()->assertJsonPath('data.sent_copy_error', null);
        $this->assertCount(1, FakeMailboxImap::$appended);
    }

    public function test_a_failed_copy_keeps_the_message_sent_with_the_reason(): void
    {
        $mailbox = $this->mailbox(['sent_folder' => 'INBOX.Sent']);
        FakeMailboxImap::$failWith = 'A pasta de Enviados não existe no servidor.';
        $message = $this->queueSend($mailbox)->fresh();
        $this->assertSame(MailMessage::STATUS_SENT, $message->status);
        $this->assertSame('Enviada. A cópia em Enviados falhou: A pasta de Enviados não existe no servidor.', $message->error);
    }

    public function test_the_unread_count_job_runs_per_mailbox_on_the_mail_queue(): void
    {
        $active = $this->mailbox();
        $pending = $this->mailbox(['user_id' => User::factory()->create(['company_id' => $this->company->id])->id, 'status' => Mailbox::STATUS_PENDING]);
        Queue::fake();
        (new DispatchMailboxUnreadCountsJob())->handle();
        Queue::assertPushedOn('mail', CountMailboxUnreadJob::class, fn (CountMailboxUnreadJob $job) => $job->mailboxId === $active->id);
        Queue::assertNotPushed(CountMailboxUnreadJob::class, fn (CountMailboxUnreadJob $job) => $job->mailboxId === $pending->id);
        $this->assertSame('mail', (new SendMailboxMessageJob(1))->queue);
        $this->assertSame('mail', (new DispatchMailboxUnreadCountsJob())->queue);
    }

    public function test_the_count_job_saves_the_number_or_the_reason(): void
    {
        $mailbox = $this->mailbox();
        FakeMailboxImap::$unseen = 12;
        (new CountMailboxUnreadJob($mailbox->id))->handle(app(MailboxImap::class));
        $mailbox->refresh();
        $this->assertSame([12, Mailbox::STATUS_ACTIVE], [$mailbox->unread_count, $mailbox->imap_status]);
        $this->assertNotNull($mailbox->unread_checked_at);

        FakeMailboxImap::$failWith = 'O servidor mail.exemplo.pt:993 não respondeu a tempo.';
        (new CountMailboxUnreadJob($mailbox->id))->handle(app(MailboxImap::class));
        $mailbox->refresh();
        $this->assertSame([12, Mailbox::STATUS_ERROR, 'O servidor mail.exemplo.pt:993 não respondeu a tempo.'], [$mailbox->unread_count, $mailbox->imap_status, $mailbox->imap_error]);
    }

    public function test_the_count_is_scheduled_every_five_minutes_inside_the_external_jobs_switch(): void
    {
        $this->assertContains('mailboxes-unread-count', config('scheduler.external_job_names'));
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'mailboxes-unread-count');
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        config(['scheduler.external_jobs' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['scheduler.external_jobs' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_unread_only_for_the_owner_and_only_with_a_mailbox(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/unread'))->assertOk()->assertJsonPath('data.has_mailbox', false)->assertJsonPath('data.unread_count', null);
        $this->mailbox(['unread_count' => 7, 'unread_checked_at' => now()]);
        $this->getJson($this->url('/unread'))->assertOk()->assertJsonPath('data.has_mailbox', true)->assertJsonPath('data.unread_count', 7);

        // O root noutra empresa, a agência e a impersonation: 403 (e nada sobre a caixa).
        Sanctum::actingAs($this->root);
        $this->getJson($this->url('/unread'))->assertStatus(403);
        $this->postJson($this->url('/test-imap'))->assertStatus(403);
        $agency = Company::create(['nipc' => '500019003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->company->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->company->id, 'team_scope' => 'all', 'requested_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']));
        $this->getJson($this->url('/unread'))->assertStatus(403);

        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken];
        $this->withHeaders($headers)->getJson($this->url('/unread'))->assertStatus(403);
        $this->withHeaders($headers)->postJson($this->url('/test-imap'))->assertStatus(403);
    }

    public function test_dev_guard_the_real_imap_never_connects_outside_production(): void
    {
        config(['mailboxes.real_send' => false]);
        $result = app(WebklexMailboxImap::class)->check($this->mailbox());
        $this->assertFalse($result->ok);
        $this->assertSame('Em dev, a XPLENDOR não liga ao servidor de e-mail por IMAP.', $result->error);
    }
}
