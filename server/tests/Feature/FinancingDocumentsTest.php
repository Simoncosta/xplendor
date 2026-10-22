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
use App\Models\FinancingRequest;
use App\Models\PermissionProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\FinancingRequestBackfill;
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
 * Envio seguro de documentos para financiamento, com a lista da Quebom e dois titulares: o
 * vendedor cria o pedido (1 ou 2 titulares, o ano do IRS); cada titular tem o seu link, os seus
 * dados (telemóvel, email, habitação e valor), os seus documentos e o SEU consentimento (com o
 * nome escrito); o primeiro titular pode incluir o segundo; só PDF, JPG, PNG e HEIC pelo
 * conteúdo; links expirados, revogados e desconhecidos; o cliente não lista nem descarrega; a
 * descarga no painel é assinada, como anexo e registada; o root fica registado; tenancy; ACL; o
 * apagamento aos 90 dias com aviso 7 dias antes; os pedidos antigos; nada de dados pessoais nos
 * registos.
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
        $this->car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id, 'vehicle_type' => 'motorhome', 'status' => 'active', 'seller_user_id' => $this->user->id]));
    }

    private function base(?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id;
    }

    /** @return array{0: FinancingRequest, 1: string, 2: ?string} [pedido, código do titular 1, código do titular 2] */
    private function createRequest(array $over = [], ?User $as = null): array
    {
        $res = $this->actingAs($as ?? $this->user, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links", array_replace([
            'customer_name' => self::CUSTOMER, 'items' => ['identificacao', 'recibos', 'irs_declaracao', 'outro'], 'other' => 'Declaração da entidade patronal', 'days' => 7,
        ], $over))->assertCreated();
        $token = fn (?string $url) => $url ? substr($url, strpos($url, '#') + 1) : null;
        $this->assertStringContainsString('/enviar-documentos#', (string) $res->json('data.urls.1'));

        return [FinancingRequest::findOrFail($res->json('data.id')), $token($res->json('data.urls.1')), $token($res->json('data.urls.2'))];
    }

    private function pub(string $method, string $token, string $path = '', array $data = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Financing-Token' => $token] + $headers)->json($method, "/api/public/financing{$path}", $data);
    }

    private function upload(string $token, string $kind, UploadedFile $file)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Financing-Token' => $token, 'Accept' => 'application/json'])->post('/api/public/financing/documents', ['kind' => $kind, 'file' => $file]);
    }

    private function consent(string $token, string $name = self::CUSTOMER): void
    {
        $this->pub('POST', $token, '/consent', ['privacy' => true, 'id_copy' => true, 'name' => $name])->assertOk();
    }

    public static function fileWith(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fin');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function pdf(): UploadedFile
    {
        return self::fileWith('o meu cc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n");
    }

    public static function jpg(): UploadedFile
    {
        return self::fileWith('IMG_0001.JPG', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64));
    }

    public static function png(): UploadedFile
    {
        return self::fileWith('recibo.png', "\x89PNG\r\n\x1A\n" . str_repeat("\0", 64));
    }

    public static function heic(): UploadedFile
    {
        return self::fileWith('IMG_0002.HEIC', "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64));
    }

    public function test_the_seller_creates_a_request_and_the_client_sees_only_the_list_with_the_company_brand(): void
    {
        [$request, $token] = $this->createRequest();
        $link = $request->links()->sole();
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame(self::CUSTOMER, $link->customer_name);
        $this->assertSame(1, $link->holder);
        $this->assertTrue($link->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $res = $this->pub('GET', $token)->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame('financiamento', $res->json('data.purpose'));
        $this->assertSame('Exemplo', $res->json('data.company.name'));
        $this->assertSame('Joana', $res->json('data.customer_first_name'));
        $this->assertSame(['identificacao', 'recibos', 'irs_declaracao', 'outro'], collect($res->json('data.items'))->pluck('kind')->all());
        $first = $res->json('data.items.0');
        $this->assertSame(['kind' => 'identificacao', 'name' => 'Documento de identificação', 'hint' => 'Frente e verso.', 'required' => true, 'max_files' => 4,
            'accepted_types' => ['pdf', 'jpg', 'png', 'heic'], 'max_file_bytes' => 10485760, 'sent_count' => 0], $first);
        $this->assertSame('Declaração da entidade patronal', $res->json('data.items.3.name'));
        $this->assertStringContainsString('documento de identificação', (string) $res->json('data.notice.id_copy'));
        $this->assertStringContainsString('artigo 5.º da Lei n.º 7/2007', (string) $res->json('data.notice.id_copy'));
        $this->assertTrue($res->json('data.notice.name_required'));
        $this->assertSame('2026-11', $res->json('data.notice.version'));
        $this->assertSame('Vai incluir um segundo titular?', $res->json('data.second_holder.question'));
        $this->assertFalse($res->json('data.second_holder.included'));
        $this->assertStringNotContainsString(self::CUSTOMER, $res->getContent(), 'o nome completo não volta para a página');
    }

    public function test_the_default_list_comes_from_the_company_and_the_irs_year_can_be_changed(): void
    {
        $settings = $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $this->assertSame(['identificacao', 'iban', 'extratos', 'morada', 'recibos', 'irs_declaracao', 'contrato'], $settings->json('data.default_items'), 'a lista da Quebom é a da plataforma');
        $year = (int) now('Europe/Lisbon')->format('Y') - 1;
        $this->assertSame($year, $settings->json('data.defaults.irs_year'), 'por omissão, o ano anterior');
        $this->assertContains('irs_nota', collect($settings->json('data.items'))->pluck('key')->all(), 'a nota de liquidação continua disponível para marcar');
        $this->assertNotContains('irs_nota', $settings->json('data.default_items'));

        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 90, 'default_items' => ['identificacao', 'mapa_bp']])->assertOk()
            ->assertJsonPath('data.default_items', ['identificacao', 'mapa_bp']);
        $this->actingAs($this->user, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 90, 'default_items' => ['iban']])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 90, 'default_items' => ['cc']])->assertStatus(422);

        [$request, $token] = $this->createRequest(['items' => ['irs_declaracao'], 'irs_year' => $year - 1]);
        $this->assertSame($year - 1, $request->irs_year);
        $this->assertSame('Declaração de IRS de ' . ($year - 1), $this->pub('GET', $token)->json('data.items.0.name'));
        $this->assertStringContainsString('referente aos rendimentos de ' . ($year - 1), $this->pub('GET', $token)->json('data.items.0.hint'));
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links", ['customer_name' => 'X Y', 'items' => ['cc']])->assertStatus(422);
    }

    public function test_the_client_fills_the_phone_email_housing_and_monthly_amount(): void
    {
        [$request, $token] = $this->createRequest();
        $this->pub('PUT', $token, '/details', ['phone' => '91', 'email' => 'a@b.pt', 'housing' => 'arrendada'])->assertStatus(422)->assertJsonPath('code', 'invalid_phone');
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'nao', 'housing' => 'arrendada'])->assertStatus(422)->assertJsonPath('code', 'invalid_email');
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'a@b.pt', 'housing' => 'castelo'])->assertStatus(422)->assertJsonPath('code', 'invalid_housing');
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'a@b.pt', 'housing' => 'arrendada'])->assertStatus(422)
            ->assertJsonPath('code', 'invalid_amount')->assertJsonPath('message', 'Indique o valor mensal da renda.');
        $res = $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'Joana@Exemplo.Invalid', 'housing' => 'propria_credito', 'housing_monthly_eur' => '450,50'])->assertOk();
        $this->assertTrue($res->json('data.details.completed'));
        $link = $request->links()->sole();
        $this->assertSame('joana@exemplo.invalid', $link->email);
        $this->assertSame('450.50', (string) $link->housing_monthly_eur);
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'a@b.pt', 'housing' => 'propria_sem_credito', 'housing_monthly_eur' => '300'])->assertOk();
        $this->assertNull($link->fresh()->housing_monthly_eur, 'sem crédito, sem valor mensal');
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'a@b.pt', 'housing' => 'outra', 'housing_other' => 'Vivo com os meus pais'])->assertOk();
        $this->assertSame('Vivo com os meus pais', $link->fresh()->housing_other);

        $panel = $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $this->assertSame('Outra (Vivo com os meus pais)', $panel->json('data.requests.0.holders.0.housing_label'));
        $this->assertSame('910 000 000', $panel->json('data.requests.0.holders.0.phone'));
    }

    public function test_two_holders_each_with_their_own_link_documents_and_consent(): void
    {
        [$request, $token1, $token2] = $this->createRequest(['holders' => 2, 'second_customer_name' => 'Rui Segundo Teste', 'relationship' => 'conjuge']);
        $this->assertNotNull($token2);
        $this->assertNotSame($token1, $token2);
        $page2 = $this->pub('GET', $token2)->assertOk();
        $this->assertSame(2, $page2->json('data.holder'));
        $this->assertSame('Esta página destina-se ao segundo titular. Se não é a pessoa indicada, não continue.', $page2->json('data.holder_notice'));
        $this->assertNull($page2->json('data.second_holder'), 'o segundo titular não inclui outro');
        $this->assertSame('Rui', $page2->json('data.customer_first_name'));

        // O consentimento é pessoal: o do primeiro não vale pelo segundo.
        $this->consent($token1);
        $this->upload($token2, 'identificacao', self::pdf())->assertStatus(409)->assertJsonPath('code', 'consent_required');
        $this->pub('POST', $token2, '/consent', ['privacy' => true, 'id_copy' => true])->assertStatus(422)->assertJsonPath('code', 'name_required');
        $this->consent($token2, 'Rui Segundo Teste');
        $this->upload($token1, 'identificacao', self::pdf())->assertCreated();
        $this->upload($token2, 'identificacao', self::jpg())->assertCreated();

        [$l1, $l2] = [$request->links()->where('holder', 1)->sole(), $request->links()->where('holder', 2)->sole()];
        $this->assertSame(self::CUSTOMER, FinancingConsent::where('financing_link_id', $l1->id)->where('kind', 'copia_cc')->sole()->signed_name);
        $this->assertSame('Rui Segundo Teste', FinancingConsent::where('financing_link_id', $l2->id)->where('kind', 'copia_cc')->sole()->signed_name);
        $this->assertSame(1, $l1->documents()->count());
        $this->assertSame(1, $l2->documents()->count());
        $this->assertStringStartsWith('Titular 2 · ', $l2->documents()->sole()->name);
        $this->assertCount(1, $this->pub('GET', $token1)->json('data.files'), 'cada titular vê só o que enviou');

        $panel = $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $holders = $panel->json('data.requests.0.holders');
        $this->assertSame([1, 2], array_column($holders, 'holder'));
        $this->assertSame('Cônjuge', $holders[1]['relationship_label']);
        $this->assertSame('Rui Segundo Teste', $holders[1]['signed_name']);
        $this->assertCount(1, $holders[1]['documents']);
    }

    public function test_the_first_holder_includes_a_second_holder_and_the_seller_is_warned(): void
    {
        [$request, $token1] = $this->createRequest();
        $this->pub('POST', $token1, '/second-holder', ['relationship' => 'amigo'])->assertStatus(422)->assertJsonPath('code', 'invalid_relationship');
        $this->pub('POST', $token1, '/second-holder', ['relationship' => 'outro'])->assertStatus(422)->assertJsonPath('code', 'invalid_relationship');
        $res = $this->pub('POST', $token1, '/second-holder', ['relationship' => 'outro', 'relationship_other' => 'Sogro'])->assertOk();
        $this->assertTrue($res->json('data.second_holder.included'));
        $url = (string) $res->json('data.second_holder.url');
        $token2 = substr($url, strpos($url, '#') + 1);
        $this->assertSame(2, $this->pub('GET', $token2)->json('data.holder'));
        $this->assertSame('Sogro', $request->links()->where('holder', 2)->sole()->relationshipLabel());
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@exemplo.pt') && $m->title === 'Segundo titular incluído'
            && ! str_contains(implode(' ', $m->lines), 'Joana'));
        $this->assertSame(1, Alert::where('title', 'Segundo titular incluído')->count());
        // Repetir não cria outro link nem outro aviso.
        $this->pub('POST', $token1, '/second-holder', ['relationship' => 'conjuge'])->assertOk();
        $this->assertSame(1, $request->links()->where('holder', 2)->count());
        $this->assertSame(1, Alert::where('title', 'Segundo titular incluído')->count());
        // O segundo titular não pode incluir um terceiro.
        $this->pub('POST', $token2, '/second-holder', ['relationship' => 'conjuge'])->assertStatus(422)->assertJsonPath('code', 'not_available');
        // O vendedor também o pode acrescentar depois (devolve o link que já existe).
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/financing-requests/{$request->id}/second-holder", [])->assertCreated();
        $this->assertSame(1, $request->links()->where('holder', 2)->count());
    }

    public function test_pdf_jpg_png_and_heic_by_content_go_to_the_private_disk_and_the_system_folder(): void
    {
        [$request, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::jpg())->assertCreated()->assertJsonPath('data.state', 'recebido');
        $this->upload($token, 'identificacao', self::heic())->assertCreated();
        $this->upload($token, 'recibos', self::pdf())->assertCreated();
        $res = $this->upload($token, 'recibos', self::png())->assertCreated();
        $this->assertIsInt($res->json('data.id'));

        $page = $this->pub('GET', $token)->json('data');
        $this->assertSame(['Documento de identificação 1.jpg', 'Documento de identificação 2.heic'], collect($page['files'])->where('kind', 'identificacao')->pluck('name')->values()->all());
        $this->assertSame(2, $page['items'][0]['sent_count']);
        $docs = FinancingDocument::orderBy('id')->get();
        $this->assertSame(['image/jpeg', 'image/heic', 'application/pdf', 'image/png'], $docs->pluck('mime')->all());
        foreach ($docs as $doc) {
            $file = StoredFile::findOrFail($doc->stored_file_id);
            $this->assertSame('financiamento', $file->source);
            Storage::disk('local')->assertExists($file->path);
            $this->assertStringStartsWith("financiamento/company_{$this->company->id}/pedido_{$request->id}/", $file->path);
            $this->assertStringNotContainsString('IMG_', $file->name);
            $folder = FileFolder::findOrFail($file->folder_id);
            $this->assertSame("financiamento:pedido_{$request->id}", $folder->system_key);
            $this->assertStringNotContainsString('Joana', $folder->name);
        }
        $this->assertSame((int) $docs->sum('size_bytes'), CompanyStorageUsage::forCompany($this->company->id)['bytes']['financiamento']);
        $this->assertNotNull($request->fresh()->last_upload_at, 'a retenção conta no pedido');
    }

    public function test_refused_types_sizes_and_limits_with_a_code(): void
    {
        [$request, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::fileWith('cc.pdf', "MZ\x90\0" . str_repeat("\0", 64)))->assertStatus(422)->assertJsonPath('code', 'invalid_type');
        $this->upload($token, 'identificacao', self::fileWith('cc.jpg', '<html><script>alert(1)</script>%PDF-1.4</html>'))->assertStatus(422)->assertJsonPath('code', 'invalid_type');
        $this->upload($token, 'identificacao', UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'))->assertStatus(422)->assertJsonPath('code', 'too_large');
        $this->upload($token, 'iban', self::pdf())->assertStatus(422)->assertJsonPath('code', 'kind_not_requested');
        $this->upload($token, 'exterior', self::jpg())->assertStatus(422)->assertJsonPath('code', 'kind_not_requested');
        for ($i = 0; $i < 4; $i++) {
            $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        }
        $this->upload($token, 'identificacao', self::pdf())->assertStatus(422)->assertJsonPath('code', 'limit_reached');
        $request->links()->sole()->forceFill(['max_files' => 5])->save();
        $this->upload($token, 'recibos', self::pdf())->assertCreated();
        $this->upload($token, 'recibos', self::pdf())->assertStatus(422)->assertJsonPath('code', 'limit_reached');
        $this->assertSame(5, FinancingDocument::count());
    }

    public function test_expired_revoked_and_unknown_links_give_the_same_404(): void
    {
        [, $token] = $this->createRequest();
        $this->pub('GET', str_repeat('a', 64))->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.')->assertJsonPath('code', 'not_found');
        $this->pub('GET', 'curto')->assertNotFound();
        $this->travel(8)->days();
        $this->pub('GET', $token)->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.');
        $this->travelBack();

        [$request2, $token2] = $this->createRequest();
        $link2 = $request2->links()->sole();
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/financing-links/{$link2->id}/revoke")->assertOk()->assertJsonPath('data.holders.0.state', 'revogado');
        $this->pub('GET', $token2)->assertNotFound()->assertJsonPath('message', 'Este link já não está disponível.');
        $this->upload($token2, 'identificacao', self::pdf())->assertNotFound();
    }

    public function test_the_client_cannot_list_or_download_files(): void
    {
        [, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $page = $this->pub('GET', $token);
        $this->assertStringNotContainsString('/files/stored', $page->getContent());
        $this->pub('GET', $token, '/documents')->assertStatus(405);
        $this->pub('GET', $token, '/documents/' . $doc->id)->assertNotFound();
        $this->get("/api/files/stored/{$doc->stored_file_id}")->assertStatus(403);
    }

    public function test_the_panel_download_is_signed_as_attachment_and_logged(): void
    {
        [$request, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();

        $list = $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $this->assertSame(self::CUSTOMER, $list->json('data.requests.0.holders.0.customer_name'));
        $this->assertSame('Documento de identificação 1.pdf', $list->json('data.requests.0.holders.0.documents.0.name'));
        $this->assertTrue($list->json('data.requests.0.holders.0.documents.0.downloadable'));

        $url = (string) $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/financing-documents/{$doc->id}/download")->assertOk()->json('data.url');
        $this->assertStringContainsString('download=1', $url);
        $this->assertStringStartsWith('attachment', (string) $this->get($url)->assertOk()->headers->get('Content-Disposition'));
        $audit = DB::table('audits')->where('event', 'financiamento.descarga')->sole();
        $this->assertStringNotContainsString('Joana', (string) $audit->new_values);

        app(CompanyModuleService::class)->enable($this->company->id, 'ficheiros');
        $files = $this->actingAs($this->admin, 'sanctum')->getJson($this->base() . "/files?folder_id={$request->fresh()->folder_id}")->assertOk();
        $row = $files->json('data.files.0');
        $this->assertNull($row['url']);
        $this->assertSame($doc->id, $row['financing_document_id']);
    }

    public function test_root_access_to_another_company_is_logged(): void
    {
        $this->createRequest();
        $this->actingAs($this->root, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertOk();
        $this->assertSame('financiamento,root', DB::table('audits')->where('event', 'financiamento.acesso_root')->sole()->tags);
    }

    public function test_tenancy(): void
    {
        [$request, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $link = $request->links()->sole();
        $stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $otherCar = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->other->id]));

        $this->actingAs($stranger, 'sanctum')->getJson($this->base() . "/cars/{$this->car->id}/financing-links")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base($this->other) . "/cars/{$this->car->id}/financing-links")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base($this->other) . "/financing-documents/{$doc->id}/download")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson($this->base($this->other) . "/financing-links/{$link->id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson($this->base($this->other) . "/financing-requests/{$request->id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson($this->base($this->other) . "/financing-requests/{$request->id}/second-holder", [])->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->base() . "/cars/{$otherCar->id}/financing-links", ['customer_name' => 'X Y', 'items' => ['iban']])->assertNotFound();
        $this->assertSame(1, FinancingDocument::count());
    }

    public function test_acl_admin_everything_user_view_and_create_agency_and_read_only_nothing(): void
    {
        $access = app(Access::class);
        $can = fn (User $u, string $p) => $access->can($u, $this->company->id, $p)->allowed;
        $this->assertTrue($can($this->admin, 'financiamento.apagar'));
        $this->assertTrue($can($this->user, 'financiamento.ver') && $can($this->user, 'financiamento.criar'));
        $this->assertFalse($can($this->user, 'financiamento.apagar'));
        foreach ([ProfileSuggestions::READ_ONLY, ProfileSuggestions::GUEST_AGENCY] as $key) {
            $this->assertSame([], array_values(array_filter(PermissionProfile::where('system_key', $key)->firstOrFail()->permissionKeys(), fn ($p) => str_starts_with($p, 'financiamento.'))), $key);
        }
        foreach ([PermissionProfile::CEILING_COMPAT, PermissionProfile::AGENCY_ADMIN_COMPAT, PermissionProfile::AGENCY_MEMBER_COMPAT] as $key) {
            $this->assertSame([], array_values(array_filter(PermissionProfile::system($key)->permissionKeys(), fn ($p) => str_starts_with($p, 'financiamento.'))), $key);
        }

        [$request, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        $doc = FinancingDocument::sole();
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->base() . "/financing-documents/{$doc->id}")->assertForbidden();
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->base() . "/financing-requests/{$request->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->base() . "/financing-documents/{$doc->id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->base() . "/financing-requests/{$request->id}")->assertOk();
        $this->assertSame(0, FinancingRequest::count());
        $this->assertSame(0, FinancingLink::count());
    }

    public function test_a_request_from_a_lead(): void
    {
        $lead = CarLead::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'name' => 'Lead', 'email' => 'l@exemplo.invalid', 'type' => 'proposta']);
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/leads/{$lead->id}/financing-links", ['customer_name' => 'Lead Teste', 'items' => ['iban']])->assertCreated();
        $request = FinancingRequest::sole();
        $this->assertSame($lead->id, $request->car_lead_id);
        $this->assertSame($this->car->id, $request->car_id);
        $this->assertCount(1, $this->actingAs($this->user, 'sanctum')->getJson($this->base() . "/leads/{$lead->id}/financing-links")->assertOk()->json('data.requests'));
    }

    public function test_the_seller_is_notified_once_per_hour_when_documents_arrive(): void
    {
        [, $token1, $token2] = $this->createRequest(['holders' => 2]);
        $this->consent($token1);
        $this->consent($token2, 'Segundo Titular');
        $this->upload($token1, 'identificacao', self::pdf())->assertCreated();
        $this->upload($token2, 'identificacao', self::jpg())->assertCreated();
        $this->assertSame(1, Alert::where('title', 'Documentos para financiamento recebidos')->count(), 'um aviso por pedido e por hora');
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@exemplo.pt') && ! str_contains(implode(' ', $m->lines), 'Joana'));
    }

    public function test_automatic_deletion_counts_from_the_last_upload_of_any_holder_with_a_warning_7_days_before(): void
    {
        [$request, $token1, $token2] = $this->createRequest(['holders' => 2, 'days' => 30]);
        $this->consent($token1);
        $this->consent($token2, 'Segundo Titular');
        $this->pub('PUT', $token1, '/details', ['phone' => '910 000 000', 'email' => 'a@b.pt', 'housing' => 'arrendada', 'housing_monthly_eur' => 500])->assertOk();
        $this->upload($token1, 'identificacao', self::pdf())->assertCreated();
        $retention = app(FinancingRetention::class);
        $this->travel(10)->days();
        $this->upload($token2, 'identificacao', self::pdf())->assertCreated(); // o último envio conta para os dois
        $paths = StoredFile::where('source', 'financiamento')->pluck('path');

        $this->travel(82)->days(); // 82 dias depois do último envio
        $this->assertSame(0, $retention->run()['avisados']);
        $this->travel(1)->days();
        $this->assertSame(1, $retention->run()['avisados']);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('admin@exemplo.pt') && $m->title === 'Documentos para financiamento a apagar');

        $this->travel(7)->days();
        $this->assertSame(1, $retention->run()['apagados']);
        foreach ($paths as $p) {
            Storage::disk('local')->assertMissing($p);
        }
        $this->assertSame(0, FinancingDocument::count());
        foreach ($request->links()->get() as $link) {
            $this->assertNull($link->customer_name);
            $this->assertNull($link->phone);
            $this->assertNull($link->housing_monthly_eur);
            $this->assertSame(FinancingLink::PURGED, $link->state());
        }
        $this->assertSame(4, FinancingConsent::count(), 'as datas dos consentimentos ficam');
        $this->pub('GET', $token1)->assertNotFound();
    }

    public function test_retention_is_configurable_per_company_and_unused_requests_go_away(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 10])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->base() . '/financing-settings', ['retention_days' => 30])->assertOk()->assertJsonPath('data.retention.days', 30);
        [, $token] = $this->createRequest();
        $this->consent($token);
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        [$unused] = $this->createRequest();

        $this->travel(30)->days();
        $this->assertSame(1, app(FinancingRetention::class)->run()['apagados']);
        $this->travel(8)->days();
        $this->assertSame(1, app(FinancingRetention::class)->run()['links_sem_uso']);
        $this->assertNull(FinancingRequest::find($unused->id));
    }

    public function test_requests_created_before_the_holders_keep_working_as_one_holder(): void
    {
        [$token, $hash] = FinancingLink::newToken();
        $legacy = FinancingLink::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'created_by_user_id' => $this->user->id,
            'customer_name' => 'Cliente Antigo', 'requested' => ['items' => ['cc', 'irs'], 'other' => null], 'expires_at' => now()->addDays(5),
            'max_files' => 20, 'max_bytes' => 104857600, ...$hash]);
        $this->assertNull($legacy->financing_request_id);
        $this->assertSame(1, FinancingRequestBackfill::run());
        $legacy->refresh();
        $this->assertSame(1, $legacy->holder);
        $request = $legacy->request;
        $this->assertSame(['cc', 'irs'], $request->items);

        $page = $this->pub('GET', $token)->assertOk()->json('data');
        $this->assertSame(['Cartão de Cidadão', 'Declaração de IRS e nota de liquidação'], array_column($page['items'], 'name'), 'os nomes antigos nos pedidos antigos');
        $this->assertNotNull($page['notice']['id_copy'], 'o CC continua a pedir o consentimento da cópia');
        $this->consent($token, 'Cliente Antigo');
        $this->upload($token, 'cc', self::pdf())->assertCreated();
        $this->assertSame(0, FinancingRequestBackfill::run(), 'idempotente');
    }

    public function test_in_production_without_an_active_antivirus_no_requests_and_no_uploads(): void
    {
        [, $token] = $this->createRequest();
        $this->consent($token);
        config(['financing.require_antivirus' => true]);
        $this->actingAs($this->user, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links", ['customer_name' => 'X Y', 'items' => ['iban']])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->upload($token, 'identificacao', self::pdf())->assertStatus(503)->assertJsonPath('code', 'unavailable');
        $this->assertSame(0, FinancingDocument::count());
    }

    public function test_no_personal_data_in_the_application_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message . ' ' . json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });
        [, $token] = $this->createRequest();
        $this->consent($token);
        $this->pub('PUT', $token, '/details', ['phone' => '910 000 000', 'email' => 'joana@exemplo.invalid', 'housing' => 'arrendada', 'housing_monthly_eur' => 500])->assertOk();
        $this->upload($token, 'identificacao', self::pdf())->assertCreated();
        $this->upload($token, 'identificacao', self::fileWith('x.pdf', 'MZ'))->assertStatus(422);
        $this->travel(91)->days();
        app(FinancingRetention::class)->run();

        $all = implode("\n", $logged);
        $this->assertNotSame('', $all);
        foreach (['Joana', 'Fictícia', 'joana@exemplo.invalid', '910 000 000', 'Documento de identificação', $token] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }
}
