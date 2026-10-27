<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\FileFolder;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailAttachmentUpload;
use App\Models\MailMessage;
use App\Models\PermissionProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use App\Services\Mailbox\MailParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeVirusScanner;
use Tests\TestCase;

/**
 * E-mail 5b · Os anexos no envio, com o cliente, o IMAP e o ClamAV falsos: do computador (multipart,
 * como o ecrã) e dos Ficheiros (JSON, só o que a pessoa pode ver e só da empresa); até 10 ficheiros e
 * 20 MB por mensagem; executáveis recusados pela extensão e pelo conteúdo; vírus recusado; a cópia em
 * Enviados leva os anexos; depois do envio fica só o nome e o tamanho; a limpeza das 24 horas; 403.
 */
class MailboxAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $owner;
    private Mailbox $mailbox;
    private FakeVirusScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Cache::flush();
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $this->scanner = new FakeVirusScanner();
        $this->app->instance(VirusScanner::class, $this->scanner);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500050001', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500050002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'ficheiros');
        }
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Matilde Exemplo']);
        $this->mailbox = $this->mailboxFor($this->owner);
        FakeMailboxImap::$folders = ['INBOX' => [], 'INBOX.Sent' => ['\\Sent']];
    }

    private function mailboxFor(User $user): Mailbox
    {
        return Mailbox::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'account_type' => 'cpanel', 'email' => "pessoa{$user->id}@exemplo.pt",
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => "pessoa{$user->id}@exemplo.pt", 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE,
            'save_to_sent' => true, 'sent_folder' => 'INBOX.Sent']);
    }

    private function url(string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . "/my-mailbox{$suffix}";
    }

    /** O carregamento tal como o ecrã o envia: multipart, com o ficheiro. */
    private function upload(string $name, string $content, ?Company $c = null)
    {
        return $this->post($this->url('/attachments', $c), ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
    }

    /** O envio tal como o ecrã o envia: JSON, com os uuid dos anexos. */
    private function send(array $attachments, string $to = 'fornecedor@exemplo.invalid')
    {
        return $this->postJson($this->url('/send'), ['to' => $to, 'cc' => '', 'subject' => 'Orçamento', 'body' => '<p>Segue em anexo.</p>', 'attachments' => $attachments]);
    }

    private function storedFile(Company $c, string $name, string $content, array $over = []): StoredFile
    {
        $path = "files/company_{$c->id}/" . md5($name . $c->id) . '.pdf';
        Storage::disk('local')->put($path, $content);

        return StoredFile::create($over + ['company_id' => $c->id, 'name' => $name, 'extension' => 'pdf', 'mime' => 'application/pdf', 'size_bytes' => strlen($content),
            'disk' => 'local', 'path' => $path, 'source' => 'upload', 'is_system' => false]);
    }

    public function test_from_the_computer_and_from_files_the_send_and_the_sent_copy_carry_them_and_only_name_and_size_stay(): void
    {
        Sanctum::actingAs($this->owner);
        $pdf = $this->upload('orçamento.pdf', '%PDF-1.4 orçamento')->assertOk()
            ->assertJsonPath('data.attachment.name', 'orçamento.pdf')->assertJsonPath('data.attachment.mime', 'application/pdf')->json('data.attachment');
        $file = $this->storedFile($this->company, 'tabela de preços.pdf', '%PDF-1.4 tabela');
        $fromFiles = $this->postJson($this->url('/attachments/from-files'), ['file_id' => $file->id])->assertOk()
            ->assertJsonPath('data.attachment.source', 'files')->json('data.attachment');
        $this->assertSame(2, $this->scanner->calls); // o ClamAV em cada anexo, ao carregar
        $this->assertCount(2, Storage::disk('local')->files("mail-attachments/{$this->company->id}"));

        $this->send([$pdf['id'], $fromFiles['id']])->assertOk()->assertJsonPath('data.message.attachments.0.name', 'orçamento.pdf');

        // Saiu com os dois anexos (em dev, para a própria caixa) e a cópia em Enviados leva-os.
        $sent = FakeMailboxClient::$sent[0];
        $this->assertSame(['orçamento.pdf', 'tabela de preços.pdf'], array_column($sent['attachments'], 'name'));
        $copy = MailParser::full(FakeMailboxImap::$appended[0]['raw']);
        $this->assertSame(['orçamento.pdf', 'tabela de preços.pdf'], array_column($copy['attachments'], 'name'));
        $this->assertSame('%PDF-1.4 orçamento', MailParser::attachment(FakeMailboxImap::$appended[0]['raw'], 0)['content']);

        // Na XPLENDOR fica só o nome e o tamanho; os ficheiros temporários apagaram-se.
        $message = MailMessage::sole();
        $this->assertSame([['name' => 'orçamento.pdf', 'size' => strlen('%PDF-1.4 orçamento')], ['name' => 'tabela de preços.pdf', 'size' => strlen('%PDF-1.4 tabela')]], $message->attachments);
        $this->assertSame(0, MailAttachmentUpload::count());
        $this->assertSame([], Storage::disk('local')->files("mail-attachments/{$this->company->id}"));
        // O original dos Ficheiros não é tocado.
        Storage::disk('local')->assertExists($file->path);
        // Um anexo já enviado não se volta a usar.
        $this->send([$pdf['id']])->assertStatus(422);
    }

    public function test_limits_ten_files_and_twenty_mb_per_message(): void
    {
        Sanctum::actingAs($this->owner);
        $ids = [];
        foreach (range(1, 11) as $i) {
            $ids[] = $this->upload("doc{$i}.pdf", "%PDF-1.4 documento {$i}")->assertOk()->json('data.attachment.id');
        }
        $this->send($ids)->assertStatus(422)->assertJsonPath('message', 'No máximo 10 anexos por mensagem.');

        config(['mailboxes.attachments.max_bytes' => 30]);
        $this->upload('grande.pdf', '%PDF-1.4 ' . str_repeat('x', 40))->assertStatus(422);
        $this->send([$ids[0], $ids[1], $ids[2]])->assertStatus(422)->assertJsonPath('message', 'Os anexos passam de 0 MB no total (o máximo por mensagem).');
        $this->assertSame(0, MailMessage::count());
        $this->assertSame([], FakeMailboxClient::$sent);
    }

    public function test_executables_are_refused_by_extension_and_by_content(): void
    {
        Sanctum::actingAs($this->owner);
        $this->upload('fatura.pdf.exe', '%PDF-1.4 não interessa')->assertStatus(422)->assertJsonPath('message', 'Os ficheiros .exe não se podem anexar (são programas ou scripts).');
        foreach (['instalar.bat', 'script.ps1', 'app.apk', 'pacote.jar', 'macro.vbs', 'imagem.dmg', 'setup.msi'] as $name) {
            $this->upload($name, 'conteúdo qualquer')->assertStatus(422);
        }
        // Programas com o nome trocado: Windows, Linux, macOS, script e um pacote Java.
        $this->upload('fatura.pdf', "MZ\x90\x00 programa")->assertStatus(422)->assertJsonPath('message', 'O ficheiro "fatura.pdf" é um programa ou um script e não se pode anexar.');
        $this->upload('foto.jpg', "\x7fELF binário")->assertStatus(422);
        $this->upload('nota.txt', "#!/bin/sh\nrm -rf /")->assertStatus(422);
        $this->upload('documento.pdf', "\xcf\xfa\xed\xfe mach-o")->assertStatus(422);
        $zip = tempnam(sys_get_temp_dir(), 'jar');
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::OVERWRITE);
        $z->addFromString('META-INF/MANIFEST.MF', "Main-Class: X\n");
        $z->addFromString('X.class', "\xca\xfe\xba\xbe");
        $z->close();
        $this->upload('fotografias.zip', (string) file_get_contents($zip))->assertStatus(422);
        @unlink($zip);
        // Um ZIP normal passa.
        $plain = tempnam(sys_get_temp_dir(), 'zip');
        $z = new \ZipArchive();
        $z->open($plain, \ZipArchive::OVERWRITE);
        $z->addFromString('a.txt', 'olá');
        $z->close();
        $this->upload('fotografias.zip', (string) file_get_contents($plain))->assertOk();
        @unlink($plain);
        $this->assertSame(1, MailAttachmentUpload::count());
    }

    public function test_a_virus_or_the_antivirus_down_is_refused_and_nothing_stays(): void
    {
        Sanctum::actingAs($this->owner);
        $this->scanner->mode = 'infected';
        $this->upload('orçamento.pdf', '%PDF-1.4 x')->assertStatus(422)->assertJsonPath('message', 'O antivírus encontrou um vírus em "orçamento.pdf". O anexo não foi carregado.');
        $this->scanner->mode = 'down';
        $this->upload('orçamento.pdf', '%PDF-1.4 x')->assertStatus(503);
        $this->scanner->mode = 'none';
        $this->upload('orçamento.pdf', '%PDF-1.4 x')->assertStatus(503);
        $this->assertSame(0, MailAttachmentUpload::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_files_only_from_the_company_and_only_what_the_person_can_see_and_never_financing(): void
    {
        Sanctum::actingAs($this->owner);
        $foreign = $this->storedFile($this->other, 'alheio.pdf', '%PDF-1.4 alheio');
        $this->postJson($this->url('/attachments/from-files'), ['file_id' => $foreign->id])->assertStatus(404);
        $financing = $this->storedFile($this->company, 'cc.pdf', '%PDF-1.4 cc', ['source' => 'financiamento']);
        $this->postJson($this->url('/attachments/from-files'), ['file_id' => $financing->id])->assertStatus(422)
            ->assertJsonPath('message', 'Os documentos de financiamento e as fotografias das retomas não se enviam por e-mail.');

        // Quem vê os Ficheiros mas não a pasta do sistema "Suporte": esse ficheiro não existe para ela.
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Vendas', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['empresa.ver', 'ficheiros.ver']);
        $seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
        $this->mailboxFor($seller);
        $folder = FileFolder::create(['company_id' => $this->company->id, 'name' => 'Suporte', 'system_key' => 'suporte']);
        $hidden = $this->storedFile($this->company, 'pedido.pdf', '%PDF-1.4 pedido', ['is_system' => true, 'folder_id' => $folder->id, 'source' => 'suporte', 'source_ref' => 'x1']);
        $visible = $this->storedFile($this->company, 'catalogo.pdf', '%PDF-1.4 catálogo');
        Sanctum::actingAs($seller);
        $this->postJson($this->url('/attachments/from-files'), ['file_id' => $hidden->id])->assertStatus(404);
        $this->postJson($this->url('/attachments/from-files'), ['file_id' => $visible->id])->assertOk();

        // Sem ficheiros.ver: 403 (o computador continua a servir).
        $profile2 = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Oficina', 'is_system' => false, 'is_suggestion' => false]);
        $profile2->syncPermissions(['empresa.ver']);
        $mechanic = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile2->id]);
        $this->mailboxFor($mechanic);
        Sanctum::actingAs($mechanic);
        $this->postJson($this->url('/attachments/from-files'), ['file_id' => $visible->id])->assertStatus(403);
        $this->upload('orçamento.pdf', '%PDF-1.4 x')->assertOk();
    }

    public function test_unsent_attachments_are_purged_after_24_hours(): void
    {
        Sanctum::actingAs($this->owner);
        $old = $this->upload('antigo.pdf', '%PDF-1.4 antigo')->json('data.attachment.id');
        $this->travel(23)->hours();
        $recent = $this->upload('recente.pdf', '%PDF-1.4 recente')->json('data.attachment.id');
        $this->travel(2)->hours(); // o primeiro passou das 24 horas
        $this->artisan('mailbox:purge-attachments')->expectsOutput('Anexos apagados: 1')->assertSuccessful();
        $this->assertSame([$recent], MailAttachmentUpload::pluck('uuid')->all());
        $this->assertCount(1, Storage::disk('local')->files("mail-attachments/{$this->company->id}"));
        // O expirado já não se envia; o recente sim.
        $this->send([$old])->assertStatus(422)->assertJsonPath('message', 'Um dos anexos já não está disponível (passaram 24 horas ou foi removido). Volte a anexá-lo.');
        $this->send([$recent])->assertOk();
    }

    public function test_removing_and_using_attachments_is_only_for_the_owner_and_403_outside(): void
    {
        Sanctum::actingAs($this->owner);
        $mine = $this->upload('orçamento.pdf', '%PDF-1.4 x')->json('data.attachment.id');

        // Outra pessoa da mesma empresa, com a sua caixa: não envia nem apaga o anexo da Matilde.
        $colleague = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->mailboxFor($colleague);
        Sanctum::actingAs($colleague);
        $this->send([$mine])->assertStatus(422);
        $this->deleteJson($this->url("/attachments/{$mine}"))->assertOk();
        $this->assertSame(1, MailAttachmentUpload::count());

        // Outra empresa, o root e a agência: 403.
        $agency = Company::create(['nipc' => '500050003', 'fiscal_name' => 'Agência Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $agency->forceFill(['agency_enabled_at' => now()])->save();
        CompanyManagement::create(['agency_company_id' => $agency->id, 'managed_company_id' => $this->company->id, 'origin' => 'platform', 'status' => 'active',
            'active_key' => $this->company->id, 'team_scope' => 'all', 'requested_at' => now()]);
        foreach ([User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']), User::factory()->create(['company_id' => $agency->id, 'role' => 'admin']),
            User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin'])] as $who) {
            Sanctum::actingAs($who);
            $this->upload('x.pdf', '%PDF-1.4 x')->assertStatus(403);
            $this->postJson($this->url('/attachments/from-files'), ['file_id' => 1])->assertStatus(403);
            $this->deleteJson($this->url("/attachments/{$mine}"))->assertStatus(403);
        }
        // Impersonation: 403.
        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $colleague->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'])
            ->post($this->url('/attachments'), ['file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x')])->assertStatus(403);

        // A própria remove o seu.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url("/attachments/{$mine}"))->assertOk();
        $this->assertSame(0, MailAttachmentUpload::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
