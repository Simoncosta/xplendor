<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\DocxTemplateFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * DMS — Caminho B (Metade 1): modelos de documento .docx.
 * Cobre upload, o PRÉ-PASSO de variáveis partidas (fill correcto), deteção de
 * variáveis não reconhecidas (missing), geração por venda e tenant isolation.
 */
class DocumentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000200', 'fiscal_name' => 'Stand Docs Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->car = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold', 'license_plate' => 'AA-00-BB']);
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Carlos Quintão', 'nif' => '304879681']);
        CarSale::create([
            'car_id' => $this->car->id, 'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'sale_price' => 20000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(),
        ]);
    }

    /** Cria um .docx real com o corpo dado (runs controlados) num ficheiro temp. */
    private function craftDocx(string $bodyInner): string
    {
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p>' . $bodyInner . '</w:p><w:sectPr/></w:body></w:document>';
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.docx';
        $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $doc);
        $zip->close();
        return $path;
    }

    private function wr(string $t): string
    {
        return '<w:r><w:t xml:space="preserve">' . $t . '</w:t></w:r>';
    }

    private function upload(string $path, string $name = 'Contrato')
    {
        $file = new UploadedFile($path, 'modelo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        return $this->actingAs($this->user, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/document-templates",
            ['name' => $name, 'file' => $file],
            ['Accept' => 'application/json']
        );
    }

    public function test_upload_creates_template_and_stores_file(): void
    {
        // Variável partida ({{ | cliente_nome | }}) — simula Word/GDocs.
        $body = $this->wr('{{') . $this->wr('cliente_nome') . $this->wr('}}');
        $res = $this->upload($this->craftDocx($body), 'Contrato de venda');
        $res->assertStatus(200)->assertJsonPath('data.name', 'Contrato de venda');

        $tpl = DocumentTemplate::first();
        $this->assertNotNull($tpl);
        Storage::disk('local')->assertExists($tpl->original_path);
    }

    public function test_upload_rejects_non_docx(): void
    {
        $txt = UploadedFile::fake()->create('nota.txt', 5, 'text/plain');
        $this->actingAs($this->user, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/document-templates",
            ['name' => 'x', 'file' => $txt],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }

    /** O PRÉ-PASSO das variáveis partidas — provado no código real (Filler). */
    public function test_filler_substitutes_split_variables(): void
    {
        // pior caso: delimitador atomizado { | {cliente_nome} | }  +  var limpa
        $body = $this->wr('{') . $this->wr('{cliente_nome}') . $this->wr('}')
            . $this->wr(' — ')
            . $this->wr('{{viatura_matricula}}');
        $src = $this->craftDocx($body);
        $out = tempnam(sys_get_temp_dir(), 'out') . '.docx';

        $res = DocxTemplateFiller::fill($src, ['cliente_nome' => 'Carlos Quintão', 'viatura_matricula' => 'AA-00-BB'], $out);
        $this->assertSame([], $res['missing']);

        $zip = new ZipArchive(); $zip->open($out); $xml = $zip->getFromName('word/document.xml'); $zip->close();
        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $xml, $m);
        $visible = implode('', $m[1]);
        $this->assertSame('Carlos Quintão — AA-00-BB', $visible);
    }

    public function test_filler_detects_unrecognised_variables(): void
    {
        $body = $this->wr('{{cliente_nome}} {{xpto}}');
        $src = $this->craftDocx($body);
        $out = tempnam(sys_get_temp_dir(), 'out') . '.docx';
        $res = DocxTemplateFiller::fill($src, ['cliente_nome' => 'Carlos'], $out);
        $this->assertContains('xpto', $res['missing']);
        $this->assertNotContains('cliente_nome', $res['missing']);
    }

    public function test_generate_downloads_docx_when_all_known(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}} {{viatura_matricula}}')),
        ]);
        $res = $this->actingAs($this->user, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/document-templates/{$tpl->id}/generate",
            [], ['Accept' => 'application/json']
        );
        $res->assertStatus(200);
        $this->assertStringContainsString('wordprocessingml', $res->headers->get('content-type'));
    }

    public function test_generate_warns_on_unrecognised_variable(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}} {{xpto}}')),
        ]);
        $res = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/document-templates/{$tpl->id}/generate"
        );
        $res->assertStatus(422)->assertJsonPath('errors.missing', ['xpto']);

        // Com force → gera na mesma (deixa a variável desconhecida como está).
        $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/document-templates/{$tpl->id}/generate?force=1"
        )->assertStatus(200);
    }

    public function test_variables_catalog_lists_known_vars(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/document-templates/variables");
        $res->assertStatus(200);
        $keys = collect($res->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('cliente_nome'));
        $this->assertTrue($keys->contains('viatura_matricula'));
        $this->assertFalse($keys->contains('fornecedor_nome')); // fornecedor fora (decisão Simon)
    }

    private function visibleOf(string $path): string
    {
        $zip = new ZipArchive(); $zip->open($path); $xml = $zip->getFromName('word/document.xml'); $zip->close();
        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $xml, $m);
        return implode('', $m[1]);
    }

    public function test_preview_leaves_valid_empty_and_unknown_as_literal(): void
    {
        // cliente sem nacionalidade (válida-vazia) + {{xpto}} (não reconhecida).
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}}|{{cliente_nacionalidade}}|{{xpto}}')),
        ]);
        $service = app(\App\Services\DocumentTemplateService::class);
        $res = $service->generate($tpl, $this->company->id, $this->car->id, [], true);

        $vis = $this->visibleOf($res['path']);
        $this->assertStringContainsString('Carlos Quintão', $vis);           // preenchida
        $this->assertStringContainsString('{{cliente_nacionalidade}}', $vis); // válida-vazia → lacuna
        $this->assertStringContainsString('{{xpto}}', $vis);                  // não reconhecida → lacuna
        $this->assertSame(['xpto'], $res['unknown']);
    }

    public function test_download_applies_ephemeral_extra(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}}|{{cliente_nacionalidade}}')),
        ]);
        $service = app(\App\Services\DocumentTemplateService::class);
        // Valor efémero preenchido no preview.
        $res = $service->generate($tpl, $this->company->id, $this->car->id, ['cliente_nacionalidade' => 'Portuguesa'], false);

        $vis = $this->visibleOf($res['path']);
        $this->assertStringContainsString('Portuguesa', $vis);
        $this->assertStringNotContainsString('{{cliente_nacionalidade}}', $vis);
    }

    public function test_download_applies_custom_unknown_variable_from_extra(): void
    {
        // {{garagem}} não existe no catálogo (personalizada). Preenchida à mão.
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}}|{{garagem}}|{{piscina}}')),
        ]);
        $service = app(\App\Services\DocumentTemplateService::class);
        // garagem preenchida; piscina fica por preencher.
        $res = $service->generate($tpl, $this->company->id, $this->car->id, ['garagem' => 'Sim, com portão automático'], false);

        $vis = $this->visibleOf($res['path']);
        $this->assertStringContainsString('Carlos Quintão', $vis);
        $this->assertStringContainsString('Sim, com portão automático', $vis);   // personalizada preenchida entra
        $this->assertStringNotContainsString('{{garagem}}', $vis);
        $this->assertStringContainsString('{{piscina}}', $vis);                   // não preenchida → literal
    }

    public function test_preview_endpoint_never_422s_on_unknown(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}} {{xpto}}')),
        ]);
        $this->actingAs($this->user, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/document-templates/{$tpl->id}/generate?preview=1",
            [], ['Accept' => 'application/json']
        )->assertStatus(200);
    }

    public function test_replace_swaps_file_and_deletes_old(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}} — versão antiga')),
        ]);
        $oldPath = $tpl->original_path;
        Storage::disk('local')->assertExists($oldPath);

        // Novo .docx com conteúdo diferente.
        $newDocx = $this->craftDocx($this->wr('{{cliente_nome}} — VERSÃO NOVA'));
        $file = new UploadedFile($newDocx, 'novo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $res = $this->actingAs($this->user, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/document-templates/{$tpl->id}/replace",
            ['file' => $file],
            ['Accept' => 'application/json']
        );
        $res->assertStatus(200)
            ->assertJsonPath('data.id', $tpl->id)       // mantém id
            ->assertJsonPath('data.name', 'Contrato');  // mantém nome

        $fresh = $tpl->fresh();
        $this->assertNotSame($oldPath, $fresh->original_path);   // aponta para o novo
        Storage::disk('local')->assertMissing($oldPath);         // antigo apagado (sem órfãos)
        Storage::disk('local')->assertExists($fresh->original_path);

        // Geração passa a usar o novo ficheiro.
        $gen = app(\App\Services\DocumentTemplateService::class)->generate($fresh, $this->company->id, $this->car->id, [], false);
        $this->assertStringContainsString('VERSÃO NOVA', $this->visibleOf($gen['path']));
    }

    public function test_replace_tenant_isolation(): void
    {
        $tpl = DocumentTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Contrato',
            'original_path' => $this->storeTemplate($this->wr('{{cliente_nome}}')),
        ]);
        $other = Company::create(['nipc' => '500000202', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);

        $file = new UploadedFile($this->craftDocx($this->wr('x')), 'x.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->actingAs($intruder, 'sanctum')->post(
            "/api/v1/companies/{$this->company->id}/document-templates/{$tpl->id}/replace",
            ['file' => $file],
            ['Accept' => 'application/json']
        )->assertStatus(403);
    }

    public function test_tenant_isolation_on_list(): void
    {
        $other = Company::create(['nipc' => '500000201', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/document-templates")->assertStatus(403);
    }

    /** Guarda um .docx crafted no disco 'local' fake e devolve o path relativo. */
    private function storeTemplate(string $bodyInner): string
    {
        $src = $this->craftDocx($bodyInner);
        $rel = "company_{$this->company->id}/document_templates/" . basename($src);
        Storage::disk('local')->put($rel, file_get_contents($src));
        return $rel;
    }
}
