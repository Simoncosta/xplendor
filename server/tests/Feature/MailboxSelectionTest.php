<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E-mail 10 · Seleção, lidas e por ler, estrela (\Flagged), as com estrela no topo da Entrada e o
 * filtro "Com estrela", apagar para o Lixo da caixa (marca \Trash; nunca de vez) e desfazer. Tudo
 * no servidor de e-mail, com o IMAP falso (nunca liga a nenhum servidor), e só o dono da caixa age.
 */
class MailboxSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $owner;
    private User $colleague;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500020101', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500020102', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->colleague = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Colega']);
        $this->mailbox = Mailbox::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
    }

    private function msg(string $subject, string $id, string $date = 'Mon, 26 Oct 2026 10:00:00 +0000'): string
    {
        return "From: Cliente <cliente@exemplo.invalid>\r\nTo: matilde@exemplo.pt\r\nSubject: {$subject}\r\nMessage-ID: <{$id}@exemplo.invalid>\r\n"
            . "Date: {$date}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nCorpo.\r\n";
    }

    private function url(string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . "/my-mailbox{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** As três mensagens da Entrada (uid 1 a mais antiga, uid 3 a mais recente). */
    private function threeInInbox(): void
    {
        FakeMailboxImap::put('INBOX', $this->msg('Primeira', 'um', 'Fri, 23 Oct 2026 10:00:00 +0000'));
        FakeMailboxImap::put('INBOX', $this->msg('Segunda', 'dois', 'Sat, 24 Oct 2026 10:00:00 +0000'));
        FakeMailboxImap::put('INBOX', $this->msg('Terceira', 'tres', 'Sun, 25 Oct 2026 10:00:00 +0000'));
    }

    private function flags(string $folder, int $uid): array
    {
        return FakeMailboxImap::$messages[$folder][$uid]['flags'] ?? [];
    }

    public function test_the_selection_marks_read_and_unread_on_the_mail_server(): void
    {
        $this->threeInInbox();
        $this->as($this->owner)->postJson($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [1, 2], 'seen' => true])->assertOk();
        $this->assertContains('\\Seen', $this->flags('INBOX', 1));
        $this->assertContains('\\Seen', $this->flags('INBOX', 2));
        $this->assertNotContains('\\Seen', $this->flags('INBOX', 3));

        $this->as($this->owner)->postJson($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [1, 2], 'seen' => false])->assertOk();
        $this->assertNotContains('\\Seen', $this->flags('INBOX', 1));
        $this->assertContains('seen-many:INBOX:1,2:0', FakeMailboxImap::$calls); // as duas num só pedido ao servidor
    }

    public function test_the_star_is_the_flagged_mark_on_the_server(): void
    {
        $this->threeInInbox();
        $this->as($this->owner)->postJson($this->url('/flag'), ['folder' => 'INBOX', 'uids' => [1, 3], 'flagged' => true])->assertOk()->assertJsonPath('data.flagged', true);
        $this->assertContains('\\Flagged', $this->flags('INBOX', 1));
        $this->assertContains('\\Flagged', $this->flags('INBOX', 3));
        $this->as($this->owner)->postJson($this->url('/flag'), ['folder' => 'INBOX', 'uids' => [3], 'flagged' => false])->assertOk();
        $this->assertNotContains('\\Flagged', $this->flags('INBOX', 3));
        $this->as($this->owner)->postJson($this->url('/flag'), ['folder' => 'INBOX', 'uids' => [3]])->assertStatus(422);
    }

    public function test_starred_messages_go_on_top_of_the_first_inbox_page_and_have_their_own_filter(): void
    {
        $this->threeInInbox();
        $this->as($this->owner)->postJson($this->url('/flag'), ['folder' => 'INBOX', 'uids' => [1], 'flagged' => true])->assertOk();

        $page = $this->as($this->owner)->getJson($this->url('/messages?folder=INBOX&page=1'))->assertOk()->json('data');
        $this->assertSame([1], array_column($page['starred'], 'uid'));          // no topo, mesmo sendo a mais antiga
        $this->assertSame([3, 2], array_column($page['items'], 'uid'));         // depois as outras, por data
        $this->assertSame([2, 1], [$page['total'], $page['starred_total']]);    // a paginação conta as sem estrela
        $this->assertTrue($page['starred'][0]['flagged']);

        $this->as($this->owner)->getJson($this->url('/messages?folder=INBOX&page=2'))->assertOk();
        $this->assertContains('page:INBOX:2:unflagged', FakeMailboxImap::$calls); // as páginas seguintes, só as sem estrela

        $filter = $this->as($this->owner)->getJson($this->url('/messages?folder=INBOX&starred=1'))->assertOk()->json('data');
        $this->assertSame([1], array_column($filter['items'], 'uid'));
        $this->assertArrayNotHasKey('starred', $filter);
    }

    public function test_the_gmail_starred_folder_is_hidden_because_the_filter_replaces_it(): void
    {
        FakeMailboxImap::$folders['[Gmail]/Com estrela'] = ['\\HasNoChildren', '\\Flagged'];
        $folders = $this->as($this->owner)->getJson($this->url('/folders'))->assertOk()->json('data.folders');
        $this->assertNotContains('[Gmail]/Com estrela', array_column($folders, 'path'));
    }

    public function test_delete_moves_to_the_trash_found_by_its_mark_and_keeps_the_customer_copy(): void
    {
        $this->threeInInbox();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Joana Cliente']);
        $copy = MailMessage::create(['company_id' => $this->company->id, 'mailbox_id' => $this->mailbox->id, 'user_id' => $this->owner->id, 'direction' => MailMessage::IN,
            'message_id' => '<dois@exemplo.invalid>', 'from_address' => 'cliente@exemplo.invalid', 'to_address' => 'matilde@exemplo.pt', 'subject' => 'Segunda',
            'body_text' => 'Corpo.', 'status' => MailMessage::STATUS_RECEIVED, 'received_at' => now(), 'customer_id' => $customer->id]);

        $data = $this->as($this->owner)->postJson($this->url('/trash'), ['folder' => 'INBOX', 'uids' => [2], 'message_ids' => ['<dois@exemplo.invalid>']])
            ->assertOk()->json('data');

        $this->assertSame(1, $data['moved']);
        $this->assertSame(['folder' => 'INBOX', 'message_ids' => ['<dois@exemplo.invalid>']], $data['undo']);
        $this->assertArrayNotHasKey(2, FakeMailboxImap::$messages['INBOX']);
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX.Trash']);       // no Lixo, nunca apagada de vez
        $this->assertContains('move:INBOX:2:INBOX.Trash', FakeMailboxImap::$calls);
        $this->assertNotNull($copy->fresh());                                    // a cópia do histórico do cliente fica
    }

    public function test_without_a_trash_folder_nothing_is_deleted_and_the_reason_is_shown(): void
    {
        $this->threeInInbox();
        unset(FakeMailboxImap::$folders['INBOX.Trash']);
        FakeMailboxImap::$folders['Lixo sem marca'] = ['\\HasNoChildren']; // o nome não basta: só a marca \Trash

        $this->as($this->owner)->postJson($this->url('/trash'), ['folder' => 'INBOX', 'uids' => [2], 'message_ids' => ['<dois@exemplo.invalid>']])
            ->assertStatus(422)->assertJsonPath('message', 'A sua caixa não tem uma pasta de Lixo (marca \\Trash). Por segurança, a XPLENDOR não apaga mensagens de vez: nada foi apagado.');
        $this->assertCount(3, FakeMailboxImap::$messages['INBOX']);
        $this->assertEmpty(array_filter(FakeMailboxImap::$calls, fn ($c) => str_starts_with($c, 'move:')));
    }

    public function test_already_in_the_trash_is_never_deleted_for_good(): void
    {
        $uid = FakeMailboxImap::put('INBOX.Trash', $this->msg('No lixo', 'lixo'));
        $this->as($this->owner)->postJson($this->url('/trash'), ['folder' => 'INBOX.Trash', 'uids' => [$uid], 'message_ids' => ['<lixo@exemplo.invalid>']])
            ->assertStatus(422);
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX.Trash']);
    }

    public function test_undo_brings_the_messages_back_from_the_trash(): void
    {
        $this->threeInInbox();
        $undo = $this->as($this->owner)->postJson($this->url('/trash'), ['folder' => 'INBOX', 'uids' => [2, 3], 'message_ids' => ['<dois@exemplo.invalid>', '<tres@exemplo.invalid>']])
            ->assertOk()->json('data.undo');

        $this->as($this->owner)->postJson($this->url('/restore'), $undo)->assertOk()->assertJsonPath('data.restored', 2);

        $this->assertSame([], FakeMailboxImap::$messages['INBOX.Trash']);
        $subjects = array_map(fn ($m) => preg_match('/^Subject: (.+)$/m', $m['raw'], $x) ? trim($x[1]) : '', FakeMailboxImap::$messages['INBOX']);
        sort($subjects);
        $this->assertSame(['Primeira', 'Segunda', 'Terceira'], $subjects);

        // Já repostas: desfazer outra vez não encontra nada no Lixo.
        $this->as($this->owner)->postJson($this->url('/restore'), $undo)->assertStatus(422);
    }

    public function test_a_server_error_changes_nothing_and_says_why(): void
    {
        $this->threeInInbox();
        $this->as($this->owner)->getJson($this->url('/folders'))->assertOk(); // as pastas ficam em cache
        FakeMailboxImap::$failWith = 'O servidor de e-mail não respondeu.';
        $this->as($this->owner)->postJson($this->url('/flag'), ['folder' => 'INBOX', 'uids' => [1], 'flagged' => true])
            ->assertStatus(422)->assertJsonPath('message', 'O servidor de e-mail não respondeu.');
        $this->as($this->owner)->postJson($this->url('/trash'), ['folder' => 'INBOX', 'uids' => [1], 'message_ids' => ['<um@exemplo.invalid>']])->assertStatus(422);
        $this->assertCount(3, FakeMailboxImap::$messages['INBOX']);
    }

    public function test_only_the_mailbox_owner_acts(): void
    {
        $this->threeInInbox();
        $body = ['folder' => 'INBOX', 'uids' => [1], 'message_ids' => ['<um@exemplo.invalid>']];

        // Um colega da mesma empresa só chega à sua própria caixa (que não tem): nada muda na da Matilde.
        $this->as($this->colleague)->postJson($this->url('/trash'), $body)->assertStatus(422);
        $this->as($this->colleague)->postJson($this->url('/flag'), $body + ['flagged' => true])->assertStatus(422);

        // Outra empresa: 403. A sessão como cliente (impersonation): também 403, nunca na caixa de alguém.
        $stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->as($stranger)->postJson($this->url('/trash'), $body)->assertForbidden();
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'teste', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url('/trash'), $body, ['Authorization' => 'Bearer ' . $token->plainTextToken])->assertForbidden();
        $this->postJson($this->url('/restore'), ['folder' => 'INBOX', 'message_ids' => ['<um@exemplo.invalid>']], ['Authorization' => 'Bearer ' . $token->plainTextToken])->assertForbidden();

        $this->assertCount(3, FakeMailboxImap::$messages['INBOX']);
        $this->assertNotContains('\\Flagged', $this->flags('INBOX', 1));
        $this->assertEmpty(array_filter(FakeMailboxImap::$calls, fn ($c) => str_starts_with($c, 'move:') || str_starts_with($c, 'flag:')));
    }
}
