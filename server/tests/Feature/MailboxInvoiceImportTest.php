<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\OcrInvoice;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Mailbox\FakeMailboxClient;
use App\Services\Mailbox\FakeMailboxImap;
use App\Services\Mailbox\MailParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeVirusScanner;
use Tests\TestCase;

/**
 * E-mail 7 · "Importar para as Faturas", com o IMAP falso e o ClamAV simulado: o anexo PDF entra no
 * fluxo do carregamento manual (a fatura em "processing" e a leitura na fila), guarda a caixa e o
 * Message-ID (sem o conteúdo do e-mail); o mesmo ficheiro é "Já existe"; um vírus é recusado antes de
 * tudo; só quem pode carregar faturas; a caixa é só do próprio, na própria empresa; o duplicado
 * conta só dentro da empresa. Os pedidos vão em JSON, como o ecrã os envia.
 */
class MailboxInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $owner;
    private Mailbox $mailbox;
    private FakeVirusScanner $scanner;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Cache::flush();
        Bus::fake([ProcessInvoiceOcrJob::class]);
        FakeMailboxClient::reset();
        FakeMailboxImap::reset();
        $this->scanner = new FakeVirusScanner();
        $this->app->instance(VirusScanner::class, $this->scanner);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500040001', 'fiscal_name' => 'Restaurante Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500040002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
        }
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Adriana Exemplo']);
        $this->mailbox = $this->mailboxFor($this->owner);
        FakeMailboxImap::$folders = ['INBOX' => []];
        $this->uid = FakeMailboxImap::put('INBOX', (string) file_get_contents(base_path('tests/Fixtures/mail/anexos.eml')));
    }

    private function mailboxFor(User $user): Mailbox
    {
        return Mailbox::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'account_type' => 'cpanel', 'email' => "pessoa{$user->id}@exemplo.pt",
            'smtp_host' => 'mail.exemplo.pt', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'imap_host' => 'mail.exemplo.pt', 'imap_port' => 993,
            'imap_encryption' => 'ssl', 'username' => "pessoa{$user->id}@exemplo.pt", 'password' => 'segredo-de-teste', 'status' => Mailbox::STATUS_ACTIVE]);
    }

    /** O pedido tal como o ecrã o envia (importMailboxAttachment, JSON). */
    private function import(int $index, ?Company $c = null, ?int $uid = null)
    {
        return $this->postJson('/api/v1/companies/' . ($c ?? $this->company)->id . '/my-mailbox/messages/' . ($uid ?? $this->uid) . "/attachments/{$index}/import-invoice", ['folder' => 'INBOX']);
    }

    private function pdfBytes(): string
    {
        return MailParser::attachment((string) file_get_contents(base_path('tests/Fixtures/mail/anexos.eml')), 1)['content'];
    }

    public function test_a_pdf_attachment_becomes_an_invoice_in_the_manual_upload_flow_with_its_origin(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->import(1)->assertOk()->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('message', 'Fatura criada. A leitura corre agora; recebe um aviso quando terminar.')->json('data.id');

        $invoice = OcrInvoice::findOrFail($id);
        $this->assertSame($this->company->id, $invoice->company_id);
        $this->assertSame('application/pdf', $invoice->image_mime);
        $this->assertSame(hash('sha256', $this->pdfBytes()), $invoice->file_sha256);
        $this->assertSame('email', $invoice->origin);
        $this->assertSame($this->mailbox->id, $invoice->origin_mailbox_id);
        $this->assertSame($this->mailbox->email, $invoice->origin_mailbox_email);
        $this->assertSame('<anexos-7@fornecedor.invalid>', $invoice->origin_message_id);
        $this->assertStringEndsWith('.pdf', $invoice->image_path);
        Storage::disk('local')->assertExists($invoice->image_path);
        $this->assertSame($this->pdfBytes(), Storage::disk('local')->get($invoice->image_path));
        // A leitura (QR primeiro, depois a IA, o fornecedor pelo NIF) vai para a fila, como no manual.
        Bus::assertDispatched(ProcessInvoiceOcrJob::class, fn ($j) => $j->companyId === $this->company->id && $j->invoiceId === $id);
        // O antivírus correu, o temporário foi apagado, e o e-mail não ficou guardado.
        $this->assertSame(1, $this->scanner->calls);
        $this->assertSame([], Storage::disk('local')->files('antivirus-scan'));
        $this->assertSame(0, MailMessage::count());

        // Uma imagem também; o SVG não.
        $this->import(2)->assertOk();
        $this->import(3)->assertStatus(422)->assertJsonPath('message', 'Só se importam faturas em PDF ou imagem (JPG, PNG ou WEBP).');
        $this->import(9)->assertStatus(422)->assertJsonPath('message', 'O anexo já não existe na mensagem.');
    }

    public function test_the_same_file_is_already_there_with_the_link_to_the_existing_invoice(): void
    {
        Sanctum::actingAs($this->owner);
        $first = $this->import(1)->assertOk()->json('data.id');
        $this->import(1)->assertStatus(409)->assertJsonPath('errors.code', 'ficheiro_duplicado')->assertJsonPath('errors.existing_id', $first)
            ->assertJsonPath('message', "Já carregada: abrir a fatura #{$first}.");
        // O mesmo ficheiro carregado à mão também conta (o conteúdo é o mesmo).
        OcrInvoice::query()->delete(); // apagada: pode voltar a entrar (como no manual)
        $manual = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr-invoices/x.pdf', 'image_size_bytes' => 1, 'image_mime' => 'application/pdf',
            'file_sha256' => hash('sha256', $this->pdfBytes()), 'status' => 'por_validar', 'synced_to_pingwin' => false, 'origin' => 'upload']);
        $this->import(1)->assertStatus(409)->assertJsonPath('errors.existing_id', $manual->id);
        $this->assertSame(2, OcrInvoice::withTrashed()->count());
    }

    public function test_a_virus_is_refused_before_anything_and_nothing_is_stored(): void
    {
        $this->scanner->mode = 'infected';
        Sanctum::actingAs($this->owner);
        $this->import(1)->assertStatus(422)->assertJsonPath('message', 'O antivírus encontrou um vírus neste ficheiro. A fatura não foi carregada.');
        $this->scanner->mode = 'down';
        $this->import(1)->assertStatus(503)->assertJsonPath('message', 'O antivírus não está disponível neste momento, por isso a fatura não foi carregada. Tente daqui a pouco.');
        $this->scanner->mode = 'none';
        $this->import(1)->assertStatus(503)->assertJsonPath('message', 'Não foi possível analisar o ficheiro com o antivírus, por isso a fatura não foi carregada.');

        $this->assertSame(0, OcrInvoice::withTrashed()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Bus::assertNotDispatched(ProcessInvoiceOcrJob::class);
    }

    public function test_the_manual_upload_uses_the_same_entry_and_records_its_origin(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->post("/api/v1/companies/{$this->company->id}/ocr/invoices", ['file' => UploadedFile::fake()->createWithContent('fatura.pdf', $this->pdfBytes())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'processing')->json('data.id');
        $invoice = OcrInvoice::findOrFail($id);
        $this->assertSame('upload', $invoice->origin);
        $this->assertNull($invoice->origin_mailbox_id);
        // Depois, o mesmo ficheiro vindo da caixa é "Já existe".
        $this->import(1)->assertStatus(409)->assertJsonPath('errors.existing_id', $id);
    }

    public function test_the_manual_upload_also_goes_through_clamav_with_the_reason_on_screen(): void
    {
        Sanctum::actingAs($this->owner);
        $upload = fn () => $this->post("/api/v1/companies/{$this->company->id}/ocr/invoices", ['file' => UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 fatura')], ['Accept' => 'application/json']);
        $this->scanner->mode = 'infected';
        $upload()->assertStatus(422)->assertJsonPath('message', 'O antivírus encontrou um vírus neste ficheiro. A fatura não foi carregada.');
        $this->scanner->mode = 'down';
        $upload()->assertStatus(503)->assertJsonPath('message', 'O antivírus não está disponível neste momento, por isso a fatura não foi carregada. Tente daqui a pouco.');
        $this->scanner->mode = 'none'; // sem analisador configurado: também recusa
        $upload()->assertStatus(503);
        $this->assertSame(0, OcrInvoice::withTrashed()->count());
        Bus::assertNotDispatched(ProcessInvoiceOcrJob::class);
        $this->scanner->mode = 'clean';
        $upload()->assertOk();
        $this->assertSame(4, $this->scanner->calls);
    }

    public function test_the_invoice_page_shows_came_from_email_with_the_mailbox_address_and_nothing_for_manual_uploads(): void
    {
        Sanctum::actingAs($this->owner);
        $fromMail = $this->import(1)->assertOk()->json('data.id');
        $this->mailbox->delete(); // a caixa removida: o selo continua com o endereço
        $this->getJson("/api/v1/companies/{$this->company->id}/ocr/invoices/{$fromMail}")->assertOk()
            ->assertJsonPath('data.invoice.origin', 'email')->assertJsonPath('data.invoice.origin_mailbox_email', "pessoa{$this->owner->id}@exemplo.pt");
        $manual = $this->post("/api/v1/companies/{$this->company->id}/ocr/invoices", ['file' => UploadedFile::fake()->createWithContent('outra.pdf', '%PDF-1.4 outra')], ['Accept' => 'application/json'])->json('data.id');
        $this->getJson("/api/v1/companies/{$this->company->id}/ocr/invoices/{$manual}")->assertOk()
            ->assertJsonPath('data.invoice.origin', 'upload')->assertJsonPath('data.invoice.origin_mailbox_email', null);
    }

    public function test_the_monthly_cap_applies_as_in_the_manual_upload(): void
    {
        config(['services.openai.ocr_monthly_cap' => 0]);
        Sanctum::actingAs($this->owner);
        $this->import(1)->assertStatus(429);
        $this->assertSame(0, OcrInvoice::count());
    }

    public function test_403_without_the_permission_to_upload_invoices_even_with_the_own_mailbox(): void
    {
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Sala', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['empresa.ver', 'restauracao.ver']);
        $waiter = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
        $this->mailboxFor($waiter);
        Sanctum::actingAs($waiter);
        $this->getJson("/api/v1/companies/{$this->company->id}/my-mailbox/messages?folder=INBOX")->assertOk(); // a caixa, sim
        $this->import(1)->assertStatus(403);
        $this->assertSame(0, OcrInvoice::count());
        $this->assertSame(0, $this->scanner->calls);
    }

    public function test_only_the_owner_in_its_own_company_and_the_duplicate_is_per_company(): void
    {
        // Outra empresa (com as Faturas), o root e a impersonation: 403.
        $outsider = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        foreach ([$outsider, $root] as $who) {
            Sanctum::actingAs($who);
            $this->import(1)->assertStatus(403);
        }
        $token = $this->owner->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $root->id, 'target_user_id' => $this->owner->id, 'company_id' => $this->company->id,
            'token_id' => $token->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->postJson("/api/v1/companies/{$this->company->id}/my-mailbox/messages/{$this->uid}/attachments/1/import-invoice", ['folder' => 'INBOX'])->assertStatus(403);
        $this->assertSame(0, OcrInvoice::count());

        // O mesmo ficheiro já nas Faturas de outra empresa não é duplicado nesta.
        OcrInvoice::create(['company_id' => $this->other->id, 'image_path' => 'ocr-invoices/y.pdf', 'image_size_bytes' => 1, 'image_mime' => 'application/pdf',
            'file_sha256' => hash('sha256', $this->pdfBytes()), 'status' => 'por_validar', 'synced_to_pingwin' => false]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        $id = $this->import(1)->assertOk()->json('data.id');
        $this->assertSame($this->company->id, OcrInvoice::find($id)->company_id);
    }
}
