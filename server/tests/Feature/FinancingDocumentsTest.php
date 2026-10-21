<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Access\Access;
use App\Access\ProfileSuggestions;
use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\FileFolder;
use App\Models\FinancingConsent;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\PermissionProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Financing\FinancingRetention;
use App\Services\Storage\CompanyStorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Envio seguro de documentos para financiamento: o vendedor cria o link; o cliente (sem conta)
 * aceita o aviso e o consentimento da cópia do CC e envia; só PDF, JPG, PNG e HEIC pelo conteúdo;
 * links expirados, revogados e desconhecidos; o cliente não lista nem descarrega; a descarga no
 * painel é assinada, como anexo e registada; o root fica registado; tenancy; ACL; o apagamento
 * aos 90 dias com aviso 7 dias antes; o antivírus; nada de dados pessoais nos registos.
 */
class FinancingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = 'Joana Fictícia Teste';

    private Company $company;
    private Company $other;
    private User $admin;
    private User $user;
    private User $root;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config(['storage_targets.private_disk' => 'local', 'financing.require_antivirus' => false]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019321', 'fiscal_name' => 'Autocaravanas Exemplo Lda', 'trade_name' => 'Exemplo', 'plan_id' => $plan,
            'subscription_status' => 'active', 'email' => 'geral@exemplo.pt', 'address' => 'Rua A, 1', 'postal_code' => '4450-001']);
        $this->other = Company::create(['nipc' => '500019322', 'fiscal_name' => 'Outro Stand Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'admin@exemplo.pt']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'vendedor@exemplo.pt']);
        $this->root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        \App\Access\CompatibilityMigration::run();
        ProfileSuggestions::ensure();
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'vehicle_type' => 'motorhome', 'status' => 'active', 'seller_user_id' => $this->user->id]);
    }

    private function base(?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id;
    }

    /** @return array{0: FinancingLink, 1: string} */
    private function createLink(array $items = ['cc', 'recibos', 'outro'], ?User $as = null): array
    {
        $res = $this->actingAs($as ?? $this->user, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links", [
            'customer_name' => self::CUSTOMER, 'items' => $items, 'other' => 'Declaração da entidade patronal', 'days' => 7,
        ])->assertCreated();
        $url = (string) $res->json('data.url');
        $this->assertStringContainsString('/enviar-documentos#', $url);

        return [FinancingLink::findOrFail($res->json('data.id')), substr($url, strpos($url, '#') + 1)];
    }

    private function pub(string $method, string $token, string $path = '', array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Financing-Token' => $token])->json($method, "/api/public/financing{$path}", $data);
    }

    private function upload(string $token, string $kind, UploadedFile $file)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Financing-Token' => $token, 'Accept' => 'application/json'])->post('/api/public/financing/documents', ['kind' => $kind, 'file' => $file]);
    }

    private function consent(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Financing-Token' => $token, 'Accept' => 'application/json'])->postJson('/api/public/financing/consent', ['privacy' => true, 'cc_copy' => true])->assertOk();
    }

    private static function fileWith(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fin');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private static function pdf(): UploadedFile
    {
        return self::fileWith('o meu cc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n");
    }

    private static function jpg(): UploadedFile
    {
        return self::fileWith('IMG_0001.JPG', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64));
    }

    private static function png(): UploadedFile
    {
        return self::fileWith('recibo.png', "\x89PNG\r\n\x1A\n" . str_repeat("\0", 64));
    }

    private static function heic(): UploadedFile
    {
        return self::fileWith('IMG_0002.HEIC', "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64));
    }

    public function test_the_seller_creates_a_link_and_the_client_sees_only_the_list_with_the_company_brand(): void
    {
        [$link, $token] = $this->createLink();
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame(self::CUSTOMER, $link->customer_name);
        $this->assertTrue($link->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame(FinancingLink::ACTIVE, $link->state());

        $res = $this->pub('GET', $token)->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame('Exemplo', $res->json('data.company.name'));
        $this->assertSame('Joana', $res->json('data.customer_first_name'));
        $this->assertSame(['cc', 'recibos', 'outro'], collect($res->json('data.items'))->pluck('key')->all());
        $this->assertSame('Declaração da entidade patronal', $res->json('data.items.2.label'));
        $this->assertStringContainsString('Lei n.º 7/2007', (string) $res->json('data.notice.cc_copy'));
        $this->assertStringContainsString('90 dias', implode(' ', $res->json('data.notice.privacy.paragraphs')));
        $this->assertStringNotContainsString(self::CUSTOMER, $res->getContent(), 'o nome completo não volta para a página');
    }

    public function test_consents_are_stored_with_the_text_and_the_date_before_any_upload(): void
    {
        [$link, $token] = $this->createLink();
        $this->upload($token, 'cc', self::pdf())->assertStatus(422)->assertJsonValidationErrors('consent');
        $this->pub('POST', $token, '/consent', ['privacy' => true, 'cc_copy' => false])->assertStatus(422);
        $this->consent($token);

        $consents = FinancingConsent::where('financing_link_id', $link->id)->get()->keyBy('kind');
        $this->assertCount(2, $consents);
        $this->assertStringContainsString('Quem trata os seus dados: Autocaravanas Exemplo Lda (NIPC 500019321)', $consents['privacidade']->text);
        $this->assertStringContainsString('instituição de crédito', $consents['privacidade']->text);
        $this->assertStringContainsString('artigo 5.º da Lei n.º 7/2007', $consents['copia_cc']->text);
        $this->assertSame('2026-10', $consents['copia_cc']->version);
        $this->assertNotNull($consents['copia_cc']->accepted_at);
        $this->assertNotNull($link->fresh()->consented_at);
    }

    public function test_pdf_jpg_png_and_heic_by_content_go_to_the_private_disk_and_the_system_folder(): void
    {
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::jpg())->assertCreated();
        $this->upload($token, 'cc', self::heic())->assertCreated();
        $this->upload($token, 'recibos', self::pdf())->assertCreated();
        $res = $this->upload($token, 'recibos', self::png())->assertCreated();

        $this->assertSame(['Cartão de Cidadão 1.jpg', 'Cartão de Cidadão 2.heic'], collect($res->json('data.items.0.sent'))->pluck('name')->all());
        $docs = FinancingDocument::where('financing_link_id', $link->id)->orderBy('id')->get();
        $this->assertSame(['image/jpeg', 'image/heic', 'application/pdf', 'image/png'], $docs->pluck('mime')->all());
        $this->assertSame(FinancingDocument::SCAN_NONE, $docs[0]->scan_status);
        foreach ($docs as $doc) {
            $file = StoredFile::findOrFail($doc->stored_file_id);
            $this->assertSame('local', $file->disk);
            $this->assertTrue($file->is_system);
            $this->assertSame('financiamento', $file->source);
            Storage::disk('local')->assertExists($file->path);
            $this->assertStringStartsWith("financiamento/company_{$this->company->id}/link_{$link->id}/", $file->path);
            $this->assertStringNotContainsString('IMG_', $file->name, 'o nome original do cliente não se guarda');
            $folder = FileFolder::findOrFail($file->folder_id);
            $this->assertSame("financiamento:link_{$link->id}", $folder->system_key);
            $this->assertStringNotContainsString('Joana', $folder->name);
        }
        $this->assertSame((int) $docs->sum('size_bytes'), CompanyStorageUsage::forCompany($this->company->id)['bytes']['financiamento'], 'conta no espaço da empresa');
        $this->assertNotNull($link->fresh()->last_upload_at);
    }

    public function test_refused_types_sizes_and_limits(): void
    {
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::fileWith('cc.pdf', "MZ\x90\0" . str_repeat("\0", 64)))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($token, 'cc', self::fileWith('cc.jpg', '<html><script>alert(1)</script>%PDF-1.4</html>'))->assertStatus(422);
        $this->upload($token, 'cc', self::fileWith('cc.pdf', 'só texto, não é um PDF'))->assertStatus(422);
        $this->upload($token, 'cc', UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'))->assertStatus(422);
        $this->upload($token, 'iban', self::pdf())->assertStatus(422)->assertJsonValidationErrors('kind');
        $link->forceFill(['max_files' => 1])->save();
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $this->upload($token, 'cc', self::pdf())->assertStatus(422);
        $this->assertSame(1, FinancingDocument::count());
    }

    public function test_expired_revoked_and_unknown_links_give_the_same_404(): void
    {
        [$link, $token] = $this->createLink();
        $this->pub('GET', str_repeat('a', 64))->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.');
        $this->pub('GET', 'curto')->assertNotFound();
        $this->travel(8)->days();
        $this->pub('GET', $token)->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.');
        $this->travelBack();

        [$link2, $token2] = $this->createLink();
        $this->pub('GET', $token2)->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/financing-links/{$link2->id}/revoke")->assertOk()->assertJsonPath('data.state', 'revogado');
        $this->pub('GET', $token2)->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.');
        $this->upload($token2, 'cc', self::pdf())->assertNotFound();
    }

    public function test_the_client_cannot_list_or_download_files(): void
    {
        [, $token] = $this->createLink();
        $this->consent($token);
        $res = $this->upload($token, 'cc', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $this->assertStringNotContainsString('/files/stored', $res->getContent());
        $this->assertStringNotContainsString('"id"', $res->getContent(), 'sem números de ficheiros na página');
        $this->pub('GET', $token, '/documents')->assertStatus(405);
        $this->pub('GET', $token, '/documents/' . $doc->id)->assertNotFound();
        $this->get("/api/files/stored/{$doc->stored_file_id}")->assertStatus(403); // sem assinatura
    }

    public function test_the_panel_download_is_signed_as_attachment_and_logged(): void
    {
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();

        $list = $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $this->assertSame(self::CUSTOMER, $list->json('data.links.0.customer_name'));
        $this->assertSame('Cartão de Cidadão 1.pdf', $list->json('data.links.0.documents.0.name'));
        $this->assertTrue($list->json('data.links.0.documents.0.downloadable'));

        $url = (string) $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/financing-documents/{$doc->id}/download")->assertOk()->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('download=1', $url);
        $file = $this->get($url)->assertOk();
        $this->assertStringStartsWith('attachment', (string) $file->headers->get('Content-Disposition'));

        $audit = DB::table('audits')->where('event', 'financiamento.descarga')->sole();
        $this->assertSame($this->user->id, (int) $audit->user_id);
        $this->assertStringNotContainsString('Joana', (string) $audit->new_values);
        $this->assertStringNotContainsString('Cartão', (string) $audit->new_values);

        // Nos Ficheiros: sem endereços diretos, e sem atalhos nem Linha Editorial.
        app(CompanyModuleService::class)->enable($this->company->id, 'ficheiros');
        $files = $this->actingAs($this->admin, 'sanctum')->getJson($this->base() . "/files?folder_id={$link->fresh()->folder_id}")->assertOk();
        $row = $files->json('data.files.0');
        $this->assertNull($row['url']);
        $this->assertNull($row['download_url']);
        $this->assertSame($doc->id, $row['financing_document_id']);
        $this->assertNull(app(\App\Services\Files\FilesService::class)->signedUrl(StoredFile::findOrFail($doc->stored_file_id)));
    }

    public function test_root_access_to_another_company_is_logged(): void
    {
        $this->createLink();
        $this->actingAs($this->root, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $audit = DB::table('audits')->where('event', 'financiamento.acesso_root')->sole();
        $this->assertSame($this->root->id, (int) $audit->user_id);
        $this->assertSame('financiamento,root', $audit->tags);
    }

    public function test_tenancy(): void
    {
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $otherCar = Car::factory()->create(['company_id' => $this->other->id]);

        $this->actingAs($stranger, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base($this->other) . "/cars/{$this->car->id}/financing-links")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base($this->other) . "/financing-documents/{$doc->id}/download")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson($this->base($this->other) . "/financing-links/{$link->id}")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->base() . "/cars/{$otherCar->id}/financing-links", ['customer_name' => 'X Y', 'items' => ['cc']])->assertNotFound();
        $this->assertSame(1, FinancingDocument::count());
    }

    public function test_acl_admin_everything_user_view_and_create_agency_and_read_only_nothing(): void
    {
        $access = app(Access::class);
        $can = fn (User $u, string $p) => $access->can($u, $this->company->id, $p)->allowed;
        $this->assertTrue($can($this->admin, 'financiamento.ver') && $can($this->admin, 'financiamento.criar') && $can($this->admin, 'financiamento.apagar'));
        $this->assertTrue($can($this->user, 'financiamento.ver') && $can($this->user, 'financiamento.criar'));
        $this->assertFalse($can($this->user, 'financiamento.apagar'));
        foreach ([ProfileSuggestions::READ_ONLY, ProfileSuggestions::GUEST_AGENCY] as $key) {
            $this->assertSame([], array_values(array_filter(PermissionProfile::where('system_key', $key)->firstOrFail()->permissionKeys(),
                fn ($p) => str_starts_with($p, 'financiamento.'))), $key);
        }
        foreach ([PermissionProfile::CEILING_COMPAT, PermissionProfile::AGENCY_ADMIN_COMPAT, PermissionProfile::AGENCY_MEMBER_COMPAT] as $key) {
            $this->assertSame([], array_values(array_filter(PermissionProfile::system($key)->permissionKeys(), fn ($p) => str_starts_with($p, 'financiamento.'))), $key);
        }

        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->base() . "/financing-documents/{$doc->id}")->assertForbidden();
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->base() . "/financing-links/{$link->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->base() . "/financing-documents/{$doc->id}")->assertOk();
        $this->assertSame(0, StoredFile::where('source', 'financiamento')->count());
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->base() . "/financing-links/{$link->id}")->assertOk();
        $this->assertSame(0, FinancingLink::count());
    }

    public function test_a_link_from_a_lead(): void
    {
        $lead = CarLead::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'name' => 'Lead', 'email' => 'l@exemplo.invalid', 'type' => 'proposta']);
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/leads/{$lead->id}/financing-links", ['customer_name' => 'Lead Teste', 'items' => ['iban']])->assertCreated();
        $link = FinancingLink::sole();
        $this->assertSame($lead->id, $link->car_lead_id);
        $this->assertSame($this->car->id, $link->car_id);
        $this->assertCount(1, $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/leads/{$lead->id}/financing-links")->assertOk()->json('data.links'));
    }

    public function test_the_seller_is_notified_once_per_hour_when_documents_arrive(): void
    {
        [, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $this->upload($token, 'cc', self::jpg())->assertCreated();
        $this->assertSame(1, Alert::where('title', 'Documentos para financiamento recebidos')->count());
        Mail::assertQueued(AgencyNoticeMail::class, 1);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@exemplo.pt') && ! str_contains(implode(' ', $m->lines), 'Joana'));
    }

    public function test_automatic_deletion_90_days_after_the_last_upload_with_a_warning_7_days_before(): void
    {
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $path = StoredFile::where('source', 'financiamento')->sole()->path;
        $retention = app(FinancingRetention::class);

        $this->travel(82)->days();
        $this->assertSame(['avisados' => 0, 'apagados' => 0, 'links_sem_uso' => 0], $retention->run());
        $this->travel(1)->days(); // dia 83: faltam 7
        $this->assertSame(1, $retention->run()['avisados']);
        $this->assertNotNull($link->fresh()->purge_warned_at);
        $this->assertSame(1, Alert::where('title', 'Documentos para financiamento a apagar')->count());
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('admin@exemplo.pt') && $m->title === 'Documentos para financiamento a apagar');
        $this->assertSame(0, $retention->run()['avisados'], 'avisa uma só vez');

        $this->travel(7)->days(); // dia 90
        $this->assertSame(1, $retention->run()['apagados']);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, StoredFile::where('source', 'financiamento')->count());
        $this->assertSame(0, FinancingDocument::count());
        $fresh = $link->fresh();
        $this->assertNull($fresh->customer_name);
        $this->assertSame(FinancingLink::PURGED, $fresh->state());
        $this->assertSame(2, FinancingConsent::where('financing_link_id', $link->id)->count(), 'as datas dos consentimentos ficam');
        $this->pub('GET', $token)->assertNotFound();
    }

    public function test_retention_is_configurable_per_company_and_unused_links_go_away(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 10])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 30])->assertOk()->assertJsonPath('data.retention.days', 30);
        [, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        [$unused] = $this->createLink();

        $this->travel(30)->days();
        $stats = app(FinancingRetention::class)->run();
        $this->assertSame(1, $stats['apagados']);
        $this->travel(8)->days(); // o link sem uso expirou há 30 dias
        $this->assertSame(1, app(FinancingRetention::class)->run()['links_sem_uso']);
        $this->assertNull(FinancingLink::find($unused->id));
    }

    public function test_antivirus_infected_files_are_removed(): void
    {
        $this->app->instance(VirusScanner::class, new class implements VirusScanner {
            public function configured(): bool { return true; }
            public function ping(): bool { return true; }
            public function scan(string $disk, string $path): string { return self::INFECTED; }
            public function lastSignature(): ?string { return 'Teste-Simulado'; }
        });
        [, $token] = $this->createLink();
        $this->consent($token);
        $res = $this->upload($token, 'cc', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $this->assertSame(FinancingDocument::SCAN_INFECTED, $doc->scan_status);
        $this->assertNull($doc->stored_file_id);
        $this->assertSame(0, StoredFile::where('source', 'financiamento')->count());
        $this->assertTrue($this->pub('GET', $token)->json('data.items.0.sent.0.refused'));
        $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/financing-documents/{$doc->id}/download")->assertStatus(422);
        $this->assertSame(1, Alert::where('title', 'Documento recusado pelo antivírus')->count());
    }

    public function test_in_production_without_an_active_antivirus_no_links_and_no_uploads(): void
    {
        [$link, $token] = $this->createLink(); // criado antes de ligar a exigência
        $this->consent($token);
        config(['financing.require_antivirus' => true]); // como em produção, com ANTIVIRUS_DRIVER=none
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links", ['customer_name' => 'X Y', 'items' => ['cc']])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->upload($token, 'cc', self::pdf())->assertStatus(503);
        $this->assertTrue($this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->json('data.antivirus_blocks'));
        $this->assertSame(0, FinancingDocument::count());
    }

    public function test_no_personal_data_in_the_application_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message . ' ' . json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });
        [$link, $token] = $this->createLink();
        $this->consent($token);
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $this->upload($token, 'cc', self::fileWith('x.pdf', 'MZ'))->assertStatus(422);
        $this->travel(91)->days();
        app(FinancingRetention::class)->run();

        $all = implode("\n", $logged);
        $this->assertNotSame('', $all, 'a retenção regista o resumo');
        foreach (['Joana', 'Fictícia', 'Cartão de Cidadão', 'geral@exemplo.pt', $token] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }
}
