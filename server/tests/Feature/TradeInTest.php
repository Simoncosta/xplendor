<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\FinancingDocument;
use App\Models\FinancingRequest;
use App\Models\PermissionProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Financing\FinancingRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fotografias e DUA da retoma no "Pedir proposta": o código de envio de 24 horas, só dessa lead;
 * o que o GET devolve ao site; os tipos (imagens, e PDF só no DUA) e os limites; o código da
 * retoma só envia itens da retoma e o do financiamento só os do financiamento; as observações;
 * o antivírus; o CORS e as origens da empresa; no painel, as fotografias seguem a lead e o DUA a
 * área "Financiamento"; o vendedor pede as fotografias depois; a retenção.
 */
class TradeInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $admin;
    private User $seller;
    private User $marketing;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config(['storage_targets.private_disk' => 'local', 'financing.require_antivirus' => false]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019351', 'fiscal_name' => 'Quebom Exemplo Lda', 'trade_name' => 'Quebom', 'plan_id' => $plan,
            'subscription_status' => 'active', 'email' => 'geral@quebom.invalid', 'public_api_token' => (string) Str::uuid(),
            'website' => 'https://quebom.pt', 'vehicle_url_template' => 'https://quebom.pt/vehicles/{id}']);
        $this->other = Company::create(['nipc' => '500019352', 'fiscal_name' => 'Outro Lda', 'plan_id' => $plan, 'subscription_status' => 'active',
            'public_api_token' => (string) Str::uuid(), 'website' => 'https://paautomoveis.pt']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
            app(CompanyModuleService::class)->enable($c->id, 'commercial_crm');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'admin@quebom.invalid']);
        $this->seller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'vendedor@quebom.invalid']);
        $this->marketing = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'marketing@quebom.invalid']);
        \App\Access\CompatibilityMigration::run();
        // Quem vê e trata as leads, mas não tem a área "Financiamento" (o marketing).
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => PermissionProfile::SIDE_CLIENT, 'name' => 'Leads sem financiamento', 'is_system' => false]);
        $profile->syncPermissions(['automovel.ver', 'automovel.editar']);
        $this->marketing->forceFill(['profile_id' => $profile->id])->save();
        $this->car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->company->id, 'status' => 'active', 'seller_user_id' => $this->seller->id]));
    }

    private function propose(array $over = [], ?Company $company = null, string $ip = '10.0.0.1')
    {
        $company ??= $this->company;

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson("/api/public/car-proposal?token={$company->public_api_token}", array_replace([
            'car_id' => $this->car->id, 'name' => 'Cliente Retoma', 'phone' => '910 000 000', 'email' => 'cliente@exemplo.invalid',
            'wants_financing' => false, 'has_trade_in' => true, 'trade_in' => ['brand' => 'Renault', 'model' => 'Clio', 'year' => 2014, 'km' => 142000],
            'consent_contact' => true, 'consent_contact_text' => 'Autorizo o contacto.', 'policy_version' => '2026-10', 'event_id' => (string) Str::uuid(),
        ], $over));
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

    private function tradeInToken(): string
    {
        $res = $this->propose()->assertCreated();

        return (string) $res->json('data.trade_in_upload.token');
    }

    private function base(): string
    {
        return "/api/v1/companies/{$this->company->id}";
    }

    public function test_a_proposal_with_trade_in_returns_a_24_hour_code_only_for_that_lead(): void
    {
        $this->propose(['has_trade_in' => false, 'trade_in' => null])->assertCreated()->assertExactJson(['success' => true, 'message' => 'Pedido recebido.', 'data' => null]);
        $p = ['event_id' => (string) Str::uuid()];
        $res = $this->propose($p)->assertCreated();
        $upload = $res->json('data.trade_in_upload');
        $this->assertSame(64, strlen($upload['token']));
        $this->assertStringContainsString('/enviar-documentos#' . $upload['token'], $upload['url']);
        $this->assertTrue(now()->addHours(23)->lt($upload['expires_at']) && now()->addHours(25)->gt($upload['expires_at']));
        $lead = CarLead::latest('id')->first();
        $request = FinancingRequest::where('purpose', 'retoma')->sole();
        $this->assertSame($lead->id, $request->car_lead_id);
        $this->assertSame($upload['token'], $this->propose($p)->json('data.trade_in_upload.token'), 'o mesmo pedido devolve o mesmo código');

        $page = $this->pub('GET', $upload['token'])->assertOk()->json('data');
        $this->assertSame('retoma', $page['purpose']);
        $this->assertSame(['dua', 'exterior', 'interior', 'odometro'], array_column($page['items'], 'kind'));
        $this->assertSame(['kind' => 'dua', 'name' => 'DUA (frente e verso)', 'hint' => 'Imagem ou PDF.', 'required' => true, 'max_files' => 4,
            'accepted_types' => ['pdf', 'jpg', 'png', 'heic'], 'max_file_bytes' => 10485760, 'sent_count' => 0], $page['items'][0]);
        $this->assertSame(['jpg', 'png', 'heic'], $page['items'][1]['accepted_types']);
        $this->assertSame([12, 8, 2], [$page['items'][1]['max_files'], $page['items'][2]['max_files'], $page['items'][3]['max_files']]);
        $this->assertFalse($page['items'][3]['required'], 'o conta-quilómetros é opcional');
        $this->assertSame([], $page['files']);
        $this->assertFalse($page['notice']['accepted']);
        $this->assertNull($page['notice']['id_copy']);
        $this->assertStringContainsString('DUA tem o nome e a morada do proprietário', $page['notice']['text']);
        $this->assertNull($page['notes']);
        $this->assertSame(25, $page['limits']['max_files']);

        $this->travel(25)->hours();
        $this->pub('GET', $upload['token'])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_the_site_sends_the_dua_photos_and_notes_and_follows_the_antivirus(): void
    {
        $token = $this->tradeInToken();
        $this->upload($token, 'exterior', FinancingDocumentsTest::jpg())->assertStatus(409)->assertJsonPath('code', 'consent_required');
        $this->pub('POST', $token, '/consent', ['privacy' => true])->assertOk()->assertJsonPath('data.notice.accepted', true);
        $this->upload($token, 'dua', FinancingDocumentsTest::pdf())->assertCreated()->assertJsonPath('data.kind', 'dua')->assertJsonPath('data.state', 'recebido');
        $this->upload($token, 'exterior', FinancingDocumentsTest::jpg())->assertCreated();
        $this->upload($token, 'interior', FinancingDocumentsTest::png())->assertCreated();
        $this->upload($token, 'odometro', FinancingDocumentsTest::heic())->assertCreated();
        $this->upload($token, 'exterior', FinancingDocumentsTest::pdf())->assertStatus(422)->assertJsonPath('code', 'invalid_type');
        $this->upload($token, 'exterior', FinancingDocumentsTest::fileWith('x.jpg', 'texto'))->assertStatus(422)->assertJsonPath('code', 'invalid_type');
        $this->upload($token, 'odometro', FinancingDocumentsTest::jpg())->assertCreated();
        $this->upload($token, 'odometro', FinancingDocumentsTest::jpg())->assertStatus(422)->assertJsonPath('code', 'limit_reached');
        $this->upload($token, 'recibos', FinancingDocumentsTest::pdf())->assertStatus(422)->assertJsonPath('code', 'kind_not_requested');
        $this->pub('PUT', $token, '/notes', ['notes' => str_repeat('a', 2001)])->assertStatus(422)->assertJsonPath('code', 'too_long');
        $this->pub('PUT', $token, '/notes', ['notes' => 'Pequeno risco na porta traseira.'])->assertOk()->assertJsonPath('data.notes', 'Pequeno risco na porta traseira.');
        $this->pub('PUT', $token, '/details', ['phone' => '910000000', 'email' => 'a@b.pt', 'housing' => 'arrendada'])->assertStatus(422)->assertJsonPath('code', 'not_available');

        $files = $this->pub('GET', $token)->json('data.files');
        $this->assertSame(['dua', 'exterior', 'interior', 'odometro', 'odometro'], array_column($files, 'kind'));
        $this->assertSame(['id', 'kind', 'name', 'size_bytes', 'state', 'reason', 'reason_text', 'sent_at'], array_keys($files[0]));
        $dua = StoredFile::findOrFail(FinancingDocument::where('kind', 'dua')->sole()->stored_file_id);
        $photo = StoredFile::findOrFail(FinancingDocument::where('kind', 'exterior')->sole()->stored_file_id);
        $this->assertSame('financiamento', $dua->source, 'o DUA fica com os documentos de financiamento');
        $this->assertSame('retoma', $photo->source);
        Mail::assertQueued(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('vendedor@quebom.invalid') && $m->title === 'Fotografias da retoma recebidas');
        $this->assertSame(1, Alert::where('title', 'Fotografias da retoma recebidas')->count());

        // Antivírus: o site acompanha o estado do ficheiro pelo id.
        $this->app->instance(VirusScanner::class, new class implements VirusScanner {
            public function configured(): bool { return true; }
            public function ping(): bool { return true; }
            public function scan(string $disk, string $path): string { return self::INFECTED; }
            public function lastSignature(): ?string { return 'Teste'; }
        });
        $this->app->forgetInstance(\App\Services\Financing\FinancingPublicService::class);
        $this->refreshApplicationRoutesControllers();
        $bad = $this->upload($token, 'interior', FinancingDocumentsTest::jpg())->assertCreated();
        $id = $bad->json('data.id');
        $this->assertSame('recusado', collect($this->pub('GET', $token)->json('data.files'))->firstWhere('id', $id)['state']);
        $this->assertSame('antivirus', collect($this->pub('GET', $token)->json('data.files'))->firstWhere('id', $id)['reason']);
    }

    /** Os controladores ficam em cache nas rotas entre pedidos do mesmo teste: volta-se a resolver. */
    private function refreshApplicationRoutesControllers(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }
    }

    public function test_a_trade_in_code_only_sends_trade_in_items_and_a_financing_code_only_financing_items(): void
    {
        $tradeIn = $this->tradeInToken();
        $this->pub('POST', $tradeIn, '/consent', ['privacy' => true])->assertOk();
        foreach (['identificacao', 'recibos', 'iban'] as $kind) {
            $this->upload($tradeIn, $kind, FinancingDocumentsTest::pdf())->assertStatus(422)->assertJsonPath('code', 'kind_not_requested');
        }
        $res = $this->actingAs($this->seller, 'sanctum')->postJson($this->base() . "/cars/{$this->car->id}/financing-links",
            ['customer_name' => 'Cliente Retoma', 'items' => ['identificacao', 'recibos']])->assertCreated();
        $url = (string) $res->json('data.urls.1');
        $financing = substr($url, strpos($url, '#') + 1);
        $this->pub('POST', $financing, '/consent', ['privacy' => true, 'id_copy' => true, 'name' => 'Cliente Retoma'])->assertOk();
        foreach (['dua', 'exterior', 'interior', 'odometro'] as $kind) {
            $this->upload($financing, $kind, FinancingDocumentsTest::jpg())->assertStatus(422)->assertJsonPath('code', 'kind_not_requested');
        }
        $this->pub('PUT', $financing, '/notes', ['notes' => 'x'])->assertStatus(422)->assertJsonPath('code', 'not_available');
    }

    public function test_the_code_of_another_lead_or_company_sees_nothing_of_this_one(): void
    {
        $a = $this->tradeInToken();
        $this->pub('POST', $a, '/consent', ['privacy' => true])->assertOk();
        $this->upload($a, 'exterior', FinancingDocumentsTest::jpg())->assertCreated();
        $b = $this->tradeInToken();
        $this->assertSame([], $this->pub('GET', $b)->json('data.files'), 'cada código só vê a sua retoma');
        $leadB = CarLead::latest('id')->first();
        $docA = FinancingDocument::sole();
        // No painel, a fotografia da lead A não sai pela retoma da lead B nem por outra empresa.
        $stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$this->other->id}/trade-in-files/{$docA->id}/download")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson($this->base() . "/leads/{$leadB->id}/trade-in")->assertForbidden();
        $this->assertSame([], $this->actingAs($this->seller, 'sanctum')->getJson($this->base() . "/leads/{$leadB->id}/trade-in")->json('data.requests.0.holders.0.documents'));
    }

    public function test_cors_preflight_accepts_the_header_only_from_authorized_origins_and_the_request_only_from_the_company_sites(): void
    {
        $token = $this->tradeInToken();
        $pre = fn (string $origin) => $this->call('OPTIONS', '/api/public/financing', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-financing-token',
        ]);
        $ok = $pre('https://quebom.pt');
        $this->assertSame('https://quebom.pt', $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('x-financing-token', (string) $ok->headers->get('Access-Control-Allow-Headers'));
        $this->assertNull($pre('https://malicioso.example')->headers->get('Access-Control-Allow-Origin'), 'uma origem não autorizada não recebe autorização');

        $this->pub('GET', $token, '', [], ['Origin' => 'https://www.quebom.pt'])->assertOk();
        $this->pub('GET', $token, '', [], ['Origin' => 'http://localhost'])->assertOk(); // a página da XPLENDOR (APP_URL)
        $this->pub('GET', $token, '', [], ['Origin' => 'https://paautomoveis.pt'])->assertStatus(403)->assertJsonPath('code', 'origin_not_allowed');
    }

    public function test_in_the_panel_photos_follow_the_lead_and_the_dua_follows_financing(): void
    {
        $token = $this->tradeInToken();
        $lead = CarLead::latest('id')->first();
        $this->pub('POST', $token, '/consent', ['privacy' => true])->assertOk();
        $this->upload($token, 'dua', FinancingDocumentsTest::pdf())->assertCreated();
        $this->upload($token, 'exterior', FinancingDocumentsTest::jpg())->assertCreated();
        $this->pub('PUT', $token, '/notes', ['notes' => 'Revisões em dia.'])->assertOk();
        $dua = FinancingDocument::where('kind', 'dua')->sole();
        $photo = FinancingDocument::where('kind', 'exterior')->sole();

        $seen = $this->actingAs($this->seller, 'sanctum')->getJson($this->base() . "/leads/{$lead->id}/trade-in")->assertOk();
        $this->assertSame(['brand' => 'Renault', 'model' => 'Clio', 'year' => 2014, 'km' => 142000], $seen->json('data.trade_in'));
        $this->assertSame('Revisões em dia.', $seen->json('data.requests.0.notes'));
        $this->assertSame(['dua', 'exterior'], array_column($seen->json('data.requests.0.holders.0.documents'), 'kind'));
        $this->assertNotNull($seen->json('data.requests.0.holders.0.documents.1.thumb_url'));

        $mk = $this->actingAs($this->marketing, 'sanctum')->getJson($this->base() . "/leads/{$lead->id}/trade-in")->assertOk();
        $this->assertSame(['exterior'], array_column($mk->json('data.requests.0.holders.0.documents'), 'kind'), 'sem a área Financiamento, sem o DUA');
        $this->assertSame(1, $mk->json('data.requests.0.holders.0.hidden_documents'));
        $this->assertFalse($mk->json('data.can.see_dua'));
        $this->actingAs($this->marketing, 'sanctum')->getJson($this->base() . "/trade-in-files/{$photo->id}/download")->assertOk();
        $this->actingAs($this->marketing, 'sanctum')->getJson($this->base() . "/trade-in-files/{$dua->id}/download")->assertNotFound();
        $this->actingAs($this->marketing, 'sanctum')->getJson($this->base() . "/financing-documents/{$dua->id}/download")->assertForbidden();
        $this->actingAs($this->seller, 'sanctum')->getJson($this->base() . "/financing-documents/{$dua->id}/download")->assertOk();
        $this->actingAs($this->seller, 'sanctum')->getJson($this->base() . "/financing-documents/{$photo->id}/download")->assertNotFound();
        $this->assertSame(1, DB::table('audits')->where('event', 'financiamento.descarga')->count(), 'a descarga do DUA fica registada');

        // As fotografias: o marketing (automovel.editar) apaga; o DUA não se apaga por aqui.
        $this->actingAs($this->marketing, 'sanctum')->deleteJson($this->base() . "/trade-in-files/{$dua->id}")->assertNotFound();
        $this->actingAs($this->marketing, 'sanctum')->deleteJson($this->base() . "/trade-in-files/{$photo->id}")->assertOk();
    }

    public function test_the_seller_asks_for_the_photos_later_and_retention_applies(): void
    {
        $this->propose(['event_id' => (string) Str::uuid()])->assertCreated();
        $lead = CarLead::latest('id')->first();
        $this->travel(2)->days(); // o código de 24 horas já expirou
        $res = $this->actingAs($this->seller, 'sanctum')->postJson($this->base() . "/leads/{$lead->id}/trade-in/link", ['days' => 5])->assertCreated();
        $url = (string) $res->json('data.url');
        $token = substr($url, strpos($url, '#') + 1);
        $this->pub('POST', $token, '/consent', ['privacy' => true])->assertOk();
        $this->upload($token, 'exterior', FinancingDocumentsTest::jpg())->assertCreated();
        $this->pub('PUT', $token, '/notes', ['notes' => 'Pneus novos.'])->assertOk();
        $this->assertSame(2, FinancingRequest::where('purpose', 'retoma')->where('car_lead_id', $lead->id)->count());
        $path = StoredFile::where('source', 'retoma')->sole()->path;

        $this->travel(90)->days();
        app(FinancingRetention::class)->run();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, FinancingDocument::count());
        $this->assertNull(FinancingRequest::where('car_lead_id', $lead->id)->whereNotNull('purged_at')->sole()->notes);
    }

    public function test_acl_trade_in_routes_follow_the_lead_permissions(): void
    {
        $lead = CarLead::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'name' => 'L', 'email' => 'l@x.invalid']);
        $readOnly = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => PermissionProfile::SIDE_CLIENT, 'name' => 'Só ver leads', 'is_system' => false]);
        $profile->syncPermissions(['automovel.ver']);
        $readOnly->forceFill(['profile_id' => $profile->id])->save();
        $this->actingAs($readOnly, 'sanctum')->getJson($this->base() . "/leads/{$lead->id}/trade-in")->assertOk();
        $this->actingAs($readOnly, 'sanctum')->postJson($this->base() . "/leads/{$lead->id}/trade-in/link")->assertForbidden();
        $this->actingAs($this->marketing, 'sanctum')->postJson($this->base() . "/leads/{$lead->id}/trade-in/link")->assertCreated();
    }
}
