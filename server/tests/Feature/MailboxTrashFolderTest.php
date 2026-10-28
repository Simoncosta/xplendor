<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E-mail 10b · A pasta do Lixo nas definições da caixa: a que o servidor marca com \Trash (só para
 * informação) ou, sem marca, uma escolhida à mão entre as que já existem. Apagar usa a marcada,
 * depois a escolhida; sem nenhuma, não apaga e diz onde escolher. Nunca cria pastas nem apaga de
 * vez, e o "Desfazer" funciona igual. Só com o IMAP falso.
 */
class MailboxTrashFolderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500020201', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->mailbox = Mailbox::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'account_type' => 'other', 'email' => 'matilde@exemplo.pt',
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => 'matilde@exemplo.pt', 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
        FakeMailboxImap::put('INBOX', "From: Cliente <cliente@exemplo.invalid>\r\nTo: matilde@exemplo.pt\r\nSubject: Apagar\r\nMessage-ID: <apagar@exemplo.invalid>\r\n"
            . "Date: Mon, 26 Oct 2026 10:00:00 +0000\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nCorpo.\r\n");
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->company->id}/my-mailbox{$suffix}";
    }

    /** Um servidor sem nenhuma pasta marcada com \Trash, mas com uma pasta "Lixo antigo". */
    private function serverWithoutTrashMark(): void
    {
        FakeMailboxImap::$folders = ['INBOX' => ['\\HasNoChildren'], 'INBOX.Sent' => ['\\Sent'], 'INBOX.Lixo antigo' => ['\\HasNoChildren']];
    }

    private function trash()
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/trash'), ['folder' => 'INBOX', 'uids' => [1], 'message_ids' => ['<apagar@exemplo.invalid>']]);
    }

    public function test_with_a_trash_marked_by_the_server_it_is_shown_and_cannot_be_changed(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('/trash-folder'))->assertOk()
            ->assertJsonPath('data.marked', 'INBOX.Trash')->assertJsonPath('data.chosen', null)->assertJsonPath('data.folders', []);
        $this->actingAs($this->owner, 'sanctum')->putJson($this->url('/trash-folder'), ['folder' => 'INBOX.Sent'])->assertStatus(422);
        $this->assertNull($this->mailbox->fresh()->trash_folder);

        $this->trash()->assertOk();
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX.Trash']);
    }

    public function test_without_the_mark_the_chosen_folder_is_used_and_undo_works_the_same(): void
    {
        $this->serverWithoutTrashMark();
        $offer = $this->actingAs($this->owner, 'sanctum')->getJson($this->url('/trash-folder'))->assertOk()->json('data');
        $this->assertNull($offer['marked']);
        $this->assertSame(['INBOX.Sent', 'INBOX.Lixo antigo'], array_column($offer['folders'], 'path')); // sem a Entrada

        $this->actingAs($this->owner, 'sanctum')->putJson($this->url('/trash-folder'), ['folder' => 'INBOX.Lixo antigo'])->assertOk()->assertJsonPath('data.chosen', 'INBOX.Lixo antigo');
        $this->assertSame('INBOX.Lixo antigo', $this->mailbox->fresh()->trash_folder);

        $undo = $this->trash()->assertOk()->json('data.undo');
        $this->assertSame([], FakeMailboxImap::$messages['INBOX']);
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX.Lixo antigo']);

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/restore'), $undo)->assertOk()->assertJsonPath('data.restored', 1);
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX']);
        $this->assertSame([], FakeMailboxImap::$messages['INBOX.Lixo antigo']);
        $this->assertSame(['INBOX', 'INBOX.Sent', 'INBOX.Lixo antigo'], array_keys(FakeMailboxImap::$folders)); // nenhuma pasta criada
    }

    public function test_without_any_trash_nothing_is_deleted_and_the_settings_link_is_offered(): void
    {
        $this->serverWithoutTrashMark();
        $this->trash()->assertStatus(422)->assertJsonPath('errors.code', 'no_trash_folder')
            ->assertJsonPath('message', 'A sua caixa não tem uma pasta de Lixo: o servidor não marca nenhuma com \\Trash e ainda não escolheu uma nas definições da caixa. Por segurança, a XPLENDOR não apaga mensagens de vez: nada foi apagado.');
        $this->assertCount(1, FakeMailboxImap::$messages['INBOX']);
        $this->assertEmpty(array_filter(FakeMailboxImap::$calls, fn ($c) => str_starts_with($c, 'move:')));
    }

    public function test_a_folder_that_does_not_exist_is_refused_and_never_created(): void
    {
        $this->serverWithoutTrashMark();
        $folders = FakeMailboxImap::$folders;
        foreach (['Inventada', 'INBOX'] as $folder) {
            $this->actingAs($this->owner, 'sanctum')->putJson($this->url('/trash-folder'), ['folder' => $folder])->assertStatus(422);
        }
        $this->assertNull($this->mailbox->fresh()->trash_folder);
        $this->assertSame($folders, FakeMailboxImap::$folders);

        // Uma escolhida que entretanto deixou de existir no servidor conta como nenhuma.
        $this->mailbox->forceFill(['trash_folder' => 'INBOX.Desapareceu'])->save();
        $this->trash()->assertStatus(422)->assertJsonPath('errors.code', 'no_trash_folder');
    }
}
