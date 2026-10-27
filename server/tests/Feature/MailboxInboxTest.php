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
use App\Models\MailboxOpenedUid;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use App\Services\Mailbox\MailboxInbox;
use App\Services\Mailbox\UnreadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E-mail 4 · A caixa de entrada na XPLENDOR, com o IMAP falso e as mensagens .eml de
 * tests/Fixtures/mail: pastas, lista, mensagem com o HTML perigoso limpo, lida e por ler, anexos
 * vistos e descarregados sem ficarem guardados, "Ligar a um cliente", a resposta ligada pelo fio (e
 * a sem relação não guardada), a mudança de UIDVALIDITY, a "Conversa" da ficha e da venda,
 * isolamento por empresa e 403.
 */
class MailboxInboxTest extends TestCase
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
        Storage::fake('local');
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500020001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500020002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach (['finance', 'stock', 'aftersales'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $this->mailbox = Mailbox::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Joana Cliente']);
    }

    private function eml(string $name, array $replace = []): string
    {
        return strtr((string) file_get_contents(base_path("tests/Fixtures/mail/{$name}")), $replace);
    }

    private function url(string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . "/my-mailbox{$suffix}";
    }

    private function sentToCustomer(): MailMessage
    {
        return MailMessage::create(['company_id' => $this->company->id, 'mailbox_id' => $this->mailbox->id, 'user_id' => $this->owner->id,
            'direction' => MailMessage::OUT, 'message_id' => '<pos-venda-1@exemplo.pt>', 'from_address' => 'matilde@exemplo.pt',
            'to_address' => 'joana@cliente.invalid', 'subject' => 'A sua nova viatura', 'body_text' => 'O link', 'status' => MailMessage::STATUS_SENT,
            'sent_at' => '2026-10-27 12:00:00', 'customer_id' => $this->customer->id]); // antes da resposta (18:30 do .eml)
    }

    public function test_folders_come_from_the_list_with_portuguese_names_and_the_special_ones_first(): void
    {
        FakeMailboxImap::$folders = ['INBOX' => ['\\HasChildren'], 'INBOX.Fornecedores' => [], '[Gmail]' => ['\\Noselect'], '[Gmail]/Enviados' => ['\\Sent'],
            'INBOX.Trash' => ['\\Trash'], 'INBOX.spam' => ['\\Junk'], 'Rascunhos' => ['\\Drafts']];
        Sanctum::actingAs($this->owner);
        $folders = $this->getJson($this->url('/folders'))->assertOk()->json('data.folders');
        $this->assertSame(['Entrada', 'Enviados', 'Rascunhos', 'Spam', 'Lixo', 'Fornecedores'], array_column($folders, 'name'));
        $this->assertSame(['INBOX', '[Gmail]/Enviados', 'Rascunhos', 'INBOX.spam', 'INBOX.Trash', 'INBOX.Fornecedores'], array_column($folders, 'path'));
    }

    public function test_the_list_is_read_live_newest_first_with_seen_and_attachments(): void
    {
        FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'), ['\\Seen']);
        FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        FakeMailboxImap::$unseen = 1;
        Sanctum::actingAs($this->owner);
        $res = $this->getJson($this->url('/messages?folder=INBOX'))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.unread', 1);
        $items = $res->json('data.items');
        $this->assertSame(['Orçamento da reparação', 'As novidades da semana'], array_column($items, 'subject'));
        $this->assertSame([false, true], array_column($items, 'seen'));
        $this->assertSame([true, false], array_column($items, 'has_attachments'));
        $this->assertSame('oficina@fornecedor.invalid', $items[0]['from']['address']);
        $this->assertSame(0, MailMessage::count()); // nada se copia para a XPLENDOR
        $this->getJson($this->url('/messages?folder=Inventada'))->assertStatus(422);
    }

    public function test_encoded_subjects_and_names_are_decoded_in_the_list_and_in_the_message(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('codificado.eml'));
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/messages?folder=INBOX'))->assertOk()
            ->assertJsonPath('data.items.0.subject', 'A avaliação da viatura está pronta')
            ->assertJsonPath('data.items.0.from.name', 'João Exemplo');
        $this->getJson($this->url("/messages/{$uid}?folder=INBOX"))->assertOk()
            ->assertJsonPath('data.message.subject', 'A avaliação da viatura está pronta')
            ->assertJsonPath('data.message.from.name', 'João Exemplo')
            ->assertJsonPath('data.message.text', 'A avaliação já está disponível.');
    }

    public function test_the_list_groups_messages_by_conversation_like_gmail(): void
    {
        $a1 = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));                                       // Orçamento da reparação
        $b1 = FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));                                  // As novidades da semana
        $a2 = FakeMailboxImap::put('INBOX', str_replace(['Subject: Orçamento da reparação', 'Message-ID: <anexos-7@fornecedor.invalid>'],
            ['Subject: RES: Orçamento da reparação', 'Message-ID: <anexos-8@fornecedor.invalid>'], $this->eml('anexos.eml')));   // sem fio, pelo assunto
        $c1 = FakeMailboxImap::put('INBOX', $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<boletim-99@novidades.invalid>'])); // pelo fio
        $d1 = FakeMailboxImap::put('INBOX', $this->eml('codificado.eml'));                                   // sozinha
        Sanctum::actingAs($this->owner);
        $items = collect($this->getJson($this->url('/messages?folder=INBOX'))->assertOk()->json('data.items'))->keyBy('uid');
        $this->assertSame($items[$a1]['thread'], $items[$a2]['thread']);
        $this->assertSame($items[$b1]['thread'], $items[$c1]['thread']);
        $this->assertNotSame($items[$a1]['thread'], $items[$b1]['thread']);
        $this->assertNotSame($items[$d1]['thread'], $items[$a1]['thread']);
        $this->assertNotSame($items[$d1]['thread'], $items[$b1]['thread']);
        $this->assertArrayNotHasKey('references', $items[$a1]);
        $this->assertTrue($items[$a1]['has_attachments']);
        $this->assertFalse($items[$d1]['has_attachments']);
    }

    private function gmail(bool $markOnServer): void
    {
        $this->mailbox->forceFill(['account_type' => 'gmail', 'email' => 'matilde.exemplo@gmail.com', 'smtp_host' => 'smtp.gmail.com', 'imap_host' => 'imap.gmail.com',
            'mark_seen_on_server' => $markOnServer])->save();
    }

    private function seenCalls(): array
    {
        return array_values(array_filter(FakeMailboxImap::$calls, fn ($c) => str_starts_with($c, 'seen:')));
    }

    public function test_gmail_with_the_option_off_reads_without_marking_on_the_server_and_counts_only_what_was_not_opened_here(): void
    {
        $this->gmail(false);
        $a = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        $b = FakeMailboxImap::put('INBOX', $this->eml('codificado.eml'));
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/messages?folder=INBOX'))->assertOk()->assertJsonPath('data.unread', 2);

        $this->getJson($this->url("/messages/{$a}?folder=INBOX"))->assertOk()->assertJsonPath('data.message.seen', false);
        $this->postJson($this->url("/messages/{$a}/seen"), ['folder' => 'INBOX', 'seen' => true])->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertSame([], $this->seenCalls()); // nada foi marcado no Gmail
        $this->assertNotContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$a]['flags']);
        $row = MailboxOpenedUid::sole();
        $this->assertSame(['INBOX', 1, $a], [$row->folder, $row->uidvalidity, $row->uid]); // só o UID, sem conteúdo
        $this->assertSame(['id', 'mailbox_id', 'folder', 'uidvalidity', 'uid', 'created_at'], array_keys($row->getAttributes()));

        // A lista e a mensagem mostram-na lida; o número é o das por ler no servidor ainda não abertas aqui.
        $items = collect($this->getJson($this->url('/messages?folder=INBOX&page=1'))->json('data.items'))->keyBy('uid');
        $this->assertTrue($items[$a]['seen']);
        $this->assertFalse($items[$b]['seen']);
        $this->getJson($this->url("/messages/{$a}?folder=INBOX"))->assertJsonPath('data.message.seen', true);
        $this->assertSame(1, $this->mailbox->fresh()->unread_count);

        // "Marcar como por ler": no servidor (por ler) e o UID aberto deixa de contar.
        $this->postJson($this->url("/messages/{$a}/seen"), ['folder' => 'INBOX', 'seen' => false])->assertOk()->assertJsonPath('data.unread', 2);
        $this->assertSame(["seen:INBOX:{$a}:0"], $this->seenCalls());
        $this->assertSame(0, MailboxOpenedUid::count());
    }

    public function test_gmail_with_the_option_on_and_cpanel_always_mark_as_read_on_the_server(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        Sanctum::actingAs($this->owner);
        $this->gmail(true);
        $this->postJson($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => true])->assertOk();
        $this->assertSame(["seen:INBOX:{$uid}:1"], $this->seenCalls());
        $this->assertSame(0, MailboxOpenedUid::count());

        // No cPanel a opção não existe: mesmo gravada desligada, marca no servidor.
        FakeMailboxImap::$calls = [];
        $this->mailbox->forceFill(['account_type' => 'cpanel', 'mark_seen_on_server' => false])->save();
        $this->assertTrue($this->mailbox->fresh()->marksSeenOnServer());
        $this->postJson($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => true])->assertOk();
        $this->assertSame(["seen:INBOX:{$uid}:1"], $this->seenCalls());
        $this->assertSame(0, MailboxOpenedUid::count());
    }

    public function test_the_option_is_saved_only_for_gmail(): void
    {
        Sanctum::actingAs($this->owner);
        $base = ['email' => 'matilde.exemplo@gmail.com', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'imap.gmail.com',
            'imap_port' => 993, 'imap_encryption' => 'ssl', 'username' => 'matilde.exemplo@gmail.com'];
        $this->putJson($this->url(''), $base + ['account_type' => 'gmail', 'mark_seen_on_server' => false])->assertOk()->assertJsonPath('data.mailbox.mark_seen_on_server', false);
        $this->putJson($this->url(''), $base + ['account_type' => 'gmail'])->assertOk()->assertJsonPath('data.mailbox.mark_seen_on_server', true); // ligada por omissão
        $this->putJson($this->url(''), ['account_type' => 'cpanel', 'email' => 'matilde@exemplo.pt', 'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'mark_seen_on_server' => false])
            ->assertOk()->assertJsonPath('data.mailbox.mark_seen_on_server', true);
    }

    public function test_opened_uids_of_an_old_uidvalidity_stop_counting(): void
    {
        $this->gmail(false);
        $uid = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        MailboxOpenedUid::create(['mailbox_id' => $this->mailbox->id, 'folder' => 'INBOX', 'uidvalidity' => 1, 'uid' => $uid]);
        app(UnreadCounter::class)->refresh($this->mailbox);
        $this->assertSame(0, $this->mailbox->fresh()->unread_count);
        FakeMailboxImap::$uidvalidity = 2; // o servidor renumerou: o UID guardado já não é a mesma mensagem
        app(UnreadCounter::class)->refresh($this->mailbox->fresh());
        $this->assertSame(1, $this->mailbox->fresh()->unread_count);
        $this->assertSame(0, MailboxOpenedUid::count());
    }

    public function test_opening_the_inbox_uses_a_single_imap_connection(): void
    {
        $this->sentToCustomer();
        FakeMailboxImap::put('INBOX', $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<pos-venda-1@exemplo.pt>']));
        FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        FakeMailboxImap::$unseen = 2;
        Sanctum::actingAs($this->owner);
        app(MailboxInbox::class)->folders($this->mailbox); // as pastas ficam em cache (5 min)
        FakeMailboxImap::$calls = [];
        $this->getJson($this->url('/messages?folder=INBOX'))->assertOk()->assertJsonPath('data.unread', 2)->assertJsonPath('data.total', 2);
        // Uma ligação para tudo; só a resposta que liga traz a mensagem inteira.
        $this->assertSame(['open-inbox', 'message:INBOX:1'], FakeMailboxImap::$calls);
        $this->assertSame(1, MailMessage::where('direction', MailMessage::IN)->count());
    }

    public function test_mark_as_read_accepts_the_multipart_request_of_the_app_with_text_booleans(): void
    {
        // O axios da app envia os POST em multipart/form-data: o true chega como "true" (antes dava 422).
        $uid = FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        Sanctum::actingAs($this->owner);
        $this->post($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => 'true'], ['Accept' => 'application/json'])->assertOk();
        $this->assertContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$uid]['flags']);
        $this->post($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => 'false'], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$uid]['flags']);
        $this->post($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => 'talvez'], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonValidationErrors('seen');
    }

    public function test_the_conversation_opens_with_one_imap_connection_and_is_marked_read_with_one_more(): void
    {
        $a = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        $b = FakeMailboxImap::put('INBOX', $this->eml('codificado.eml'));
        $c = FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'), ['\\Seen']);
        Sanctum::actingAs($this->owner);
        app(MailboxInbox::class)->folders($this->mailbox); // as pastas em cache
        FakeMailboxImap::$calls = [];

        $messages = $this->getJson($this->url("/thread?folder=INBOX&uids={$c},{$b},{$a}"))->assertOk()->json('data.messages');
        $this->assertSame([$c, $b, $a], array_column($messages, 'uid'));
        $this->assertSame(['As novidades da semana', 'A avaliação da viatura está pronta', 'Orçamento da reparação'], array_column($messages, 'subject'));
        $this->assertSame(["messages:INBOX:{$c},{$b},{$a}"], FakeMailboxImap::$calls); // uma só ligação para as três

        // Marcar as duas por ler como lidas: um pedido, uma ligação (com a contagem dos não lidos na mesma).
        FakeMailboxImap::$calls = [];
        $this->postJson($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [$b, $a], 'seen' => true])->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame(["seen-many:INBOX:{$b},{$a}:1"], FakeMailboxImap::$calls);
        $this->assertContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$a]['flags']);
        $this->assertSame(0, $this->mailbox->fresh()->unread_count);

        // Por ler, de uma vez (também o pedido multipart da app, com "false" em texto).
        $this->post($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [$a, $b], 'seen' => 'false'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.unread', 2);

        // A conversa reaberta vem da cache curta: nenhuma ligação nova.
        FakeMailboxImap::$calls = [];
        $this->getJson($this->url("/thread?folder=INBOX&uids={$c}"))->assertOk();
        $this->assertSame([], FakeMailboxImap::$calls);

        $this->getJson($this->url('/thread?folder=INBOX&uids=abc'))->assertStatus(422);
        $this->postJson($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [], 'seen' => true])->assertStatus(422);
    }

    public function test_gmail_with_the_option_off_marks_the_conversation_read_only_in_xplendor(): void
    {
        $this->gmail(false);
        $a = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        $b = FakeMailboxImap::put('INBOX', $this->eml('codificado.eml'));
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url("/thread?folder=INBOX&uids={$a},{$b}"))->assertOk();
        FakeMailboxImap::$calls = [];
        $this->postJson($this->url('/seen'), ['folder' => 'INBOX', 'uids' => [$a, $b], 'seen' => true])->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame([], array_values(array_filter(FakeMailboxImap::$calls, fn ($x) => str_starts_with($x, 'seen'))));
        $this->assertSame(2, MailboxOpenedUid::count());
        $this->assertNotContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$a]['flags']);
    }

    public function test_dangerous_html_is_cleaned_and_external_images_are_blocked(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('perigoso.eml'));
        Sanctum::actingAs($this->owner);
        $m = $this->getJson($this->url("/messages/{$uid}?folder=INBOX"))->assertOk()->json('data.message');
        $html = (string) $m['html'];
        foreach (['<script', 'onerror', 'onload', 'javascript:', '<iframe', '<form', '<input', '<svg', '<meta', '<base', 'url('] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $html, $bad);
        }
        $this->assertStringContainsString('data-xp-src="https://rastreio.invalid/p.gif"', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<!-)src="https:\/\/rastreio/', $html); // só o data-xp-src
        $this->assertStringContainsString('href="https://exemplo.pt/ok" target="_blank" rel="noreferrer noopener"', $html);
        $this->assertSame(1, $m['external_images']);
        $this->assertSame('Veja isto', $m['subject']);
    }

    public function test_attachments_are_seen_and_downloaded_live_and_never_stored(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        Sanctum::actingAs($this->owner);
        $m = $this->getJson($this->url("/messages/{$uid}?folder=INBOX"))->assertOk()->json('data.message');
        $this->assertStringContainsString('src="data:image/png;base64,', (string) $m['html']); // a imagem do cid: entra no HTML
        $this->assertSame(['orcamento.pdf', 'fotografia.png', 'desenho.svg'], array_column($m['attachments'], 'name'));
        $this->assertSame([true, true, false], array_column($m['attachments'], 'previewable'));
        [$pdf, $png, $svg] = $m['attachments'];

        $view = $this->get($this->url("/messages/{$uid}/attachments/{$pdf['index']}?folder=INBOX&inline=1"))->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $view->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $view->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $view->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $view->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-1.4', $view->getContent());

        $download = $this->get($this->url("/messages/{$uid}/attachments/{$png['index']}?folder=INBOX"))->assertOk();
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="fotografia.png"', (string) $download->headers->get('Content-Disposition'));

        // O SVG (pode ter scripts) nunca se mostra: descarrega-se, mesmo pedindo para ver.
        $svgRes = $this->get($this->url("/messages/{$uid}/attachments/{$svg['index']}?folder=INBOX&inline=1"))->assertOk();
        $this->assertSame('application/octet-stream', $svgRes->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $svgRes->headers->get('Content-Disposition'));

        // Ao vivo: cada pedido vai à caixa; nada fica na base de dados nem no disco.
        $this->assertSame(4, count(array_filter(FakeMailboxImap::$calls, fn ($c) => $c === "message:INBOX:{$uid}")));
        $this->assertSame(0, MailMessage::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get($this->url("/messages/{$uid}/attachments/9?folder=INBOX"))->assertStatus(422);
    }

    public function test_mark_as_read_and_unread(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        FakeMailboxImap::$unseen = 0;
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => true])->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$uid]['flags']);
        FakeMailboxImap::$unseen = 1;
        $this->postJson($this->url("/messages/{$uid}/seen"), ['folder' => 'INBOX', 'seen' => false])->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertNotContains('\\Seen', FakeMailboxImap::$messages['INBOX'][$uid]['flags']);
        $this->assertSame(1, $this->mailbox->fresh()->unread_count);
    }

    public function test_link_to_a_customer_stores_the_message_without_attachments_and_it_shows_in_the_conversation(): void
    {
        $uid = FakeMailboxImap::put('INBOX', $this->eml('anexos.eml'));
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/messages/{$uid}/link"), ['folder' => 'INBOX', 'customer_id' => $this->customer->id])->assertOk()
            ->assertJsonPath('data.customer.name', 'Joana Cliente');
        $stored = MailMessage::sole();
        $this->assertSame([MailMessage::IN, '<anexos-7@fornecedor.invalid>', $this->customer->id, 'oficina@fornecedor.invalid'],
            [$stored->direction, $stored->message_id, $stored->customer_id, $stored->from_address]);
        $this->assertStringNotContainsString('%PDF', (string) $stored->body_html . $stored->body_text);
        $this->getJson($this->url("/messages/{$uid}?folder=INBOX"))->assertJsonPath('data.message.customer.name', 'Joana Cliente');

        // De novo (outro cliente): atualiza, não duplica.
        $other = Customer::create(['company_id' => $this->company->id, 'name' => 'Outro Cliente']);
        $this->postJson($this->url("/messages/{$uid}/link"), ['folder' => 'INBOX', 'customer_id' => $other->id])->assertOk();
        $this->assertSame($other->id, MailMessage::sole()->customer_id);

        // Um cliente de outra empresa não serve.
        $foreign = Customer::create(['company_id' => $this->other->id, 'name' => 'Cliente Alheio']);
        $this->postJson($this->url("/messages/{$uid}/link"), ['folder' => 'INBOX', 'customer_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->getJson("/api/v1/companies/{$this->company->id}/customers/{$other->id}/mail-messages")->assertOk()
            ->assertJsonPath('data.messages.0.subject', 'Orçamento da reparação')->assertJsonPath('data.messages.0.direction', 'in');
    }

    public function test_a_reply_is_linked_by_the_thread_and_an_unrelated_message_is_not_stored(): void
    {
        $original = $this->sentToCustomer();
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id]));
        $sale = CarSale::create(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 1, 'buyer_gender' => 'female',
            'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now(), 'customer_id' => $this->customer->id]);
        $original->forceFill(['car_sale_id' => $sale->id])->save();
        FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        FakeMailboxImap::put('INBOX', $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<pos-venda-1@exemplo.pt>']));

        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/messages?folder=INBOX'))->assertOk(); // abrir a caixa liga as respostas novas

        $reply = MailMessage::where('direction', MailMessage::IN)->sole();
        $this->assertSame(['<resposta-1@cliente.invalid>', '<pos-venda-1@exemplo.pt>', $this->customer->id, $sale->id, MailMessage::STATUS_RECEIVED],
            [$reply->message_id, $reply->in_reply_to, $reply->customer_id, $reply->car_sale_id, $reply->status]);
        $this->assertStringContainsString('Já vi as fotografias', (string) $reply->body_text);
        $this->assertSame(0, MailMessage::where('message_id', '<boletim-99@novidades.invalid>')->count());
        $this->assertSame(2, $this->mailbox->fresh()->last_uid);

        // Outra vez: nada de novo, nada duplicado.
        app(MailboxInbox::class)->refreshInbox($this->mailbox->fresh());
        $this->assertSame(1, MailMessage::where('direction', MailMessage::IN)->count());

        // A "Conversa" da venda: a mensagem enviada e a resposta, pela ordem.
        $this->getJson("/api/v1/companies/{$this->company->id}/cars/{$car->id}/mail-messages")->assertOk()
            ->assertJsonPath('data.messages.0.direction', 'out')->assertJsonPath('data.messages.1.direction', 'in')
            ->assertJsonPath('data.messages.1.from', 'joana@cliente.invalid');
    }

    public function test_after_a_uidvalidity_change_nothing_is_duplicated_and_new_replies_still_link(): void
    {
        $this->sentToCustomer();
        FakeMailboxImap::put('INBOX', $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<pos-venda-1@exemplo.pt>']));
        app(MailboxInbox::class)->refreshInbox($this->mailbox);
        $this->assertSame([1, 1], [$this->mailbox->fresh()->last_uid, $this->mailbox->fresh()->uidvalidity]);

        // O servidor renumerou a caixa: UIDs novos (a mesma resposta noutro UID e uma resposta nova).
        FakeMailboxImap::$uidvalidity = 77;
        FakeMailboxImap::$messages['INBOX'] = [];
        FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        FakeMailboxImap::put('INBOX', $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<pos-venda-1@exemplo.pt>']));
        FakeMailboxImap::put('INBOX', str_replace('<resposta-1@cliente.invalid>', '<resposta-2@cliente.invalid>',
            $this->eml('resposta.eml', ['{{IN_REPLY_TO}}' => '<pos-venda-1@exemplo.pt>'])));
        app(MailboxInbox::class)->refreshInbox($this->mailbox->fresh());

        $this->assertSame(['<resposta-1@cliente.invalid>', '<resposta-2@cliente.invalid>'],
            MailMessage::where('direction', MailMessage::IN)->orderBy('id')->pluck('message_id')->all());
        $this->assertSame([3, 77], [$this->mailbox->fresh()->last_uid, $this->mailbox->fresh()->uidvalidity]);
    }

    public function test_only_the_owner_reaches_the_inbox(): void
    {
        FakeMailboxImap::put('INBOX', $this->eml('sem-relacao.eml'));
        $routes = [['getJson', '/folders'], ['getJson', '/messages?folder=INBOX'], ['getJson', '/messages/1?folder=INBOX'],
            ['postJson', '/messages/1/seen'], ['getJson', '/messages/1/attachments/0?folder=INBOX'], ['postJson', '/messages/1/link']];
        $agency = Company::create(['nipc' => '500020003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->company->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->company->id, 'team_scope' => 'all', 'requested_at' => now()]);
        foreach ([$this->root, User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']), User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin'])] as $who) {
            Sanctum::actingAs($who);
            foreach ($routes as [$method, $suffix]) {
                $this->{$method}($this->url($suffix), ['folder' => 'INBOX', 'seen' => true, 'customer_id' => $this->customer->id])->assertStatus(403);
            }
        }
        // Outra pessoa da mesma empresa, sem caixa: só a sua (nenhuma).
        Sanctum::actingAs(User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']));
        $this->getJson($this->url('/messages?folder=INBOX'))->assertStatus(422)->assertJsonPath('message', 'Ligue primeiro a sua caixa de correio no Perfil.');

        // Impersonation: 403 mesmo na caixa da própria pessoa.
        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])->getJson($this->url('/messages?folder=INBOX'))->assertStatus(403);
        $this->assertSame([], array_values(array_filter(FakeMailboxImap::$calls, fn ($c) => str_starts_with($c, 'message'))));
    }

    public function test_the_conversation_is_never_shown_to_root_outside_its_company_nor_in_impersonation(): void
    {
        $this->sentToCustomer();
        $url = "/api/v1/companies/{$this->company->id}/customers/{$this->customer->id}/mail-messages";
        Sanctum::actingAs($this->owner);
        $this->getJson($url)->assertOk()->assertJsonPath('data.messages.0.subject', 'A sua nova viatura');

        Sanctum::actingAs($this->root);
        $this->getJson($url)->assertStatus(403)->assertJsonPath('reason', 'conteudo_do_email');

        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])->getJson($url)->assertStatus(403)->assertJsonPath('reason', 'impersonacao');

        // Isolamento: o cliente de outra empresa não aparece.
        $foreign = Customer::create(['company_id' => $this->other->id, 'name' => 'Cliente Alheio']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/companies/{$this->company->id}/customers/{$foreign->id}/mail-messages")->assertStatus(404);
    }
}
