<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\UpdatePingwinCatalogJob;
use App\Jobs\WriteArticleSupplierCodeJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCatalogWrite;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Services\OcrLineArticleService;
use App\Services\PingwinService;
use App\Services\SupplierArticleMapService;
use App\Services\SupplierCodesService;
use App\Support\Gtin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F2d: vários códigos do fornecedor para o mesmo artigo (principal no PingWin +
 * alternativos na XPLENDOR, sem substituir por omissão; "Tornar principal"), validação dos códigos
 * (dígito de controlo EAN-8/13, UPC-A, GTIN-14), sugestão GTIN-14 ↔ EAN-13, aviso de IVA e
 * "Corrigir o IVA do artigo" (releitura), relatório "IVA a rever" e tenancy. PingWin simulado.
 *
 * O caso real: a Makro fatura os ovos (artigo 100287) com o EAN-13 5601660999588 e com o GTIN-14
 * 65601660982506 (embalagem) — os dois válidos, os dois reais.
 */
class OcrSupplierCodesTest extends TestCase
{
    use RefreshDatabase;

    private const MAKRO_NIF = '502030712';
    private const EAN = '5601660999588';
    private const GTIN = '65601660982506';

    private Company $yuko;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinSupplier $makro;
    private PingwinCatalogItem $ovos;
    public array $pwCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Ferreira & Cambas, Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->yuko, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        }
        $this->user = User::factory()->create(['company_id' => $this->yuko->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->makro = PingwinSupplier::create(['company_id' => $this->yuko->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPM', 'name' => 'Makro', 'tax_number' => 'PT502030712', 'is_active' => true]);
        $this->ovos = $this->article('100287', 'MC OVOS M IND', ['taxgroup' => 'Reduzida']);

        // ⚠️ PingWin simulado: qualquer chamada real ao Python falha o teste.
        $this->app->instance(PingwinService::class, new class extends PingwinService {
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
        });
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function article(string $code, string $desc, array $extra = [], ?Company $c = null): PingwinCatalogItem
    {
        return PingwinCatalogItem::create(array_merge(['company_id' => ($c ?? $this->yuko)->id, 'pingwin_id' => "P{$code}", 'code' => $code,
            'description' => $desc, 'is_active' => true, 'product_status' => 'Ativo', 'purchaseunit' => 'UN'], $extra));
    }

    /** O código do fornecedor que o artigo tem no PingWin (espelho tbsupprice) = o principal. */
    private function pingwinCode(PingwinCatalogItem $a, string $code, string $line = 'L1'): void
    {
        PingwinSupplierPrice::create(['company_id' => $this->yuko->id, 'catalog_item_id' => $a->id, 'product_pingwin_id' => $a->pingwin_id,
            'supplier_pingwin_id' => 'SUPM', 'line_pingwin_id' => $line, 'sup_product_code' => $code, 'is_active' => true]);
    }

    private function invoice(array $lines, ?Company $c = null): OcrInvoice
    {
        $inv = OcrInvoice::create(['company_id' => ($c ?? $this->yuko)->id, 'image_path' => 'x.pdf', 'status' => 'por_validar',
            'supplier_nif' => self::MAKRO_NIF, 'supplier_name' => 'MAKRO CASH & CARRY PORTUGAL, S.A.', 'number' => 'FAC 02002202601/024366', 'issue_date' => '2026-05-12']);
        foreach ($lines as $i => $l) {
            OcrInvoiceLine::create(array_merge(['ocr_invoice_id' => $inv->id, 'company_id' => $inv->company_id, 'position' => $i, 'quantity' => 1,
                'unit_price' => '1.000000', 'line_total_cents' => 100, 'vat_rate' => 6], $l));
        }

        return $inv;
    }

    private function f4Line(string $docId, ?string $supplierCode, int $articleId, string $taxgroup = '1003003', string $date = '2026-05-04', string $nif = self::MAKRO_NIF, string $status = '8002', string $name = 'Makro'): void
    {
        PingwinSupplierDocument::firstOrCreate(['company_id' => $this->yuko->id, 'docheader_id' => $docId], [
            'docconfig_id' => '1209', 'document' => "VFT 0VFT/{$docId}", 'entity_pingwin_id' => 'SUPM', 'entity_name' => $name,
            'tax_number' => $nif, 'doc_date' => $date, 'total_cents' => 100, 'docstatus_id' => $status]);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => $docId,
            'line_number' => PingwinSupplierDocumentLine::where('docheader_id', $docId)->count() + 1,
            'supplier_code' => $supplierCode, 'article_id' => $articleId, 'price' => '1.000000', 'unit_code' => 'UN', 'total_cents' => 100, 'taxgroup_id' => $taxgroup]);
    }

    private function svc(): OcrLineArticleService
    {
        return app(OcrLineArticleService::class);
    }

    private function line(OcrInvoice $inv, int $pos = 0): OcrInvoiceLine
    {
        return $inv->lines()->where('position', $pos)->first();
    }

    private function url(OcrInvoice $inv, ?OcrInvoiceLine $l = null, string $suffix = ''): string
    {
        return "/api/v1/companies/{$inv->company_id}/ocr/invoices/{$inv->id}" . ($l ? "/lines/{$l->id}" : '') . $suffix;
    }

    // ───────────────────────────────────────────────────────────── validação dos códigos

    public function test_check_digits_for_ean8_upca_ean13_and_gtin14(): void
    {
        $this->assertSame(['kind' => 'EAN-8', 'valid' => true], Gtin::inspect('96385074'));
        $this->assertSame(['kind' => 'EAN-8', 'valid' => false], Gtin::inspect('96385075'));
        $this->assertSame(['kind' => 'UPC-A', 'valid' => true], Gtin::inspect('036000291452'));
        $this->assertSame(['kind' => 'UPC-A', 'valid' => false], Gtin::inspect('036000291453'));
        $this->assertSame(['kind' => 'EAN-13', 'valid' => true], Gtin::inspect(self::EAN));
        $this->assertSame(['kind' => 'EAN-13', 'valid' => true], Gtin::inspect('5601660 999588'), 'os espaços não contam');
        $this->assertSame(['kind' => 'EAN-13', 'valid' => false], Gtin::inspect('5601660999589'));
        $this->assertSame(['kind' => 'GTIN-14', 'valid' => true], Gtin::inspect(self::GTIN));
        $this->assertSame(['kind' => 'GTIN-14', 'valid' => false], Gtin::inspect('65601660982507'));
        // Códigos internos do fornecedor: não se validam.
        foreach (['29251', 'A-12', '279487', '12345678901', '', null] as $internal) {
            $this->assertNull(Gtin::inspect($internal), (string) $internal);
            $this->assertFalse(Gtin::probablyMisread($internal));
        }
        $this->assertTrue(Gtin::probablyMisread('5601660999589'));
        // Caixa ↔ unidade: o GTIN-14 tem no meio os 12 primeiros dígitos do EAN-13.
        $this->assertSame([['code' => '5601660982504', 'relation' => 'unidade']], Gtin::related(self::GTIN));
        $this->assertContains(['code' => '15601660999585', 'relation' => 'caixa'], Gtin::related(self::EAN));
        $this->assertSame([], Gtin::related('5601660999589'), 'código inválido: sem relações');
    }

    // ───────────────────────────────────────────────────────────── vários códigos → um artigo

    public function test_makro_case_two_real_codes_link_to_the_same_article_without_error(): void
    {
        Bus::fake();
        $this->pingwinCode($this->ovos, self::EAN);                // o PingWin só guarda UM código: o EAN
        $this->f4Line('D1', self::EAN, $this->ovos->id);
        app(SupplierArticleMapService::class)->bootstrap($this->yuko->id);
        $inv = $this->invoice([
            ['supplier_code' => self::GTIN, 'item' => 'MC OVOS M IND 15DUZIAS', 'quantity' => 7],
            ['supplier_code' => self::EAN, 'item' => 'MC OVOS M IND 15DUZIAS', 'quantity' => 13],
        ]);
        $this->svc()->linkInvoice($inv);
        $this->assertSame(['ligada', 'mapa'], [$this->line($inv, 1)->link_state, $this->line($inv, 1)->link_method]);

        // Associar a linha do GTIN-14 ao mesmo artigo: liga sem perguntas e sem erro; não substitui no PingWin.
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article'), ['article_id' => $this->ovos->id])->assertOk();
        $res->assertJsonPath('message', 'Associado. No PingWin fica o código principal ' . self::EAN . '; ' . self::GTIN . ' fica como alternativo na XPLENDOR.');
        $res->assertJsonPath('data.line_links.0.supplier_code_status', 'alternativo')
            ->assertJsonPath('data.line_links.0.supplier_code_error', null)
            ->assertJsonPath('data.line_links.0.principal_code', self::EAN)
            ->assertJsonPath('data.line_links.0.supplier_code_check', ['kind' => 'GTIN-14', 'valid' => true])
            ->assertJsonPath('data.line_links.1.supplier_code_check', ['kind' => 'EAN-13', 'valid' => true]);
        $codes = collect($res->json('data.line_links.0.supplier_codes'));
        $this->assertSame([self::EAN, self::GTIN], $codes->pluck('code')->all());
        $this->assertSame([true, false], $codes->pluck('principal')->all());
        Bus::assertNotDispatched(WriteArticleSupplierCodeJob::class);

        // O mapa aceita os dois códigos para o mesmo artigo (chave NIF + código).
        $this->assertSame(2, SupplierArticleMap::where('company_id', $this->yuko->id)->where('supplier_nif', self::MAKRO_NIF)->where('article_id', $this->ovos->id)->count());
        // Numa próxima fatura, as duas linhas ligam-se sozinhas pelo mapa.
        $next = $this->invoice([['supplier_code' => self::GTIN, 'item' => 'X'], ['supplier_code' => self::EAN, 'item' => 'Y']]);
        $this->svc()->linkInvoice($next);
        $this->assertSame([$this->ovos->id, $this->ovos->id], $next->lines()->orderBy('position')->pluck('article_id')->all());
        $this->assertSame(['mapa', 'mapa'], $next->lines()->orderBy('position')->pluck('link_method')->all());
    }

    public function test_make_principal_replaces_in_pingwin_with_reread_and_keeps_the_old_as_alternative(): void
    {
        $this->pingwinCode($this->ovos, self::EAN, 'L9');
        $this->f4Line('D1', self::EAN, $this->ovos->id);
        app(SupplierArticleMapService::class)->bootstrap($this->yuko->id);
        $inv = $this->invoice([['supplier_code' => self::GTIN, 'item' => 'OVOS']]);
        Bus::fake();
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article'), ['article_id' => $this->ovos->id])->assertOk();

        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/supplier-code/principal'))->assertStatus(202)
            ->assertJsonPath('data.line_links.0.supplier_code_status', 'pendente');
        Bus::assertDispatched(WriteArticleSupplierCodeJob::class, fn ($j) => $j->replace === true && $j->lineId === $this->line($inv)->id);
        // Já em curso → recusado.
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/supplier-code/principal'))->assertStatus(422);

        // O job (PingWin simulado): lê o artigo vivo, edita a linha do fornecedor, a releitura confirma.
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            public function readProduct(int $companyId, string $productId): array
            {
                $this->test->pwCalls[] = ['read', $productId];

                return ['supplier_prices' => [['line_pingwin_id' => 'L9', 'supplier' => ['pingwin_id' => 'SUPM'], 'sup_product_code' => '5601660999588']], 'supplier_tables' => []];
            }
            public function updateProduct(int $companyId, string $productId, array $changes, ?string $saleprice = null, ?string $purchaseprice = null, ?array $supplierPricesChanges = null): array
            {
                $this->test->pwCalls[] = ['update', $productId, $supplierPricesChanges];
                $u = $supplierPricesChanges['update'][0];
                PingwinSupplierPrice::where('company_id', $companyId)->where('line_pingwin_id', $u['line_pingwin_id'])->update(['sup_product_code' => $u['sup_product_code']]);

                return ['ok' => true];
            }
        });
        (new WriteArticleSupplierCodeJob($this->yuko->id, $this->line($inv)->id, true))->handle(app(PingwinService::class), $this->svc());

        $this->assertSame('ok', $this->line($inv)->supplier_code_status);
        $this->assertSame([['line_pingwin_id' => 'L9', 'sup_product_code' => self::GTIN]], $this->pwCalls[1][2]['update']);
        $codes = collect(app(SupplierCodesService::class)->forArticles($this->yuko->id, self::MAKRO_NIF, [$this->ovos->id])[$this->ovos->id]);
        $this->assertSame(self::GTIN, $codes->firstWhere('principal', true)['code']);
        $this->assertFalse($codes->firstWhere('code', self::EAN)['principal'], 'o antigo fica como alternativo');
    }

    public function test_suggested_principal_is_the_most_used_code(): void
    {
        $this->pingwinCode($this->ovos, self::GTIN);              // no PingWin está o GTIN, usado 1 vez
        $this->f4Line('D1', self::GTIN, $this->ovos->id);
        foreach (['D2', 'D3', 'D4'] as $d) {
            $this->f4Line($d, self::EAN, $this->ovos->id);       // o EAN aparece em 3 faturas
        }
        app(SupplierArticleMapService::class)->bootstrap($this->yuko->id);

        $codes = collect(app(SupplierCodesService::class)->forArticles($this->yuko->id, self::MAKRO_NIF, [$this->ovos->id])[$this->ovos->id])->keyBy('code');
        $this->assertSame([true, 1, false], [$codes[self::GTIN]['principal'], $codes[self::GTIN]['uses'], $codes[self::GTIN]['suggested_principal']]);
        $this->assertSame([false, 3, true], [$codes[self::EAN]['principal'], $codes[self::EAN]['uses'], $codes[self::EAN]['suggested_principal']]);
        // Outro fornecedor (outro NIF) não mistura códigos.
        $this->assertSame([], app(SupplierCodesService::class)->forArticles($this->yuko->id, '999999990', [$this->ovos->id]));
    }

    public function test_misread_code_never_learns_alone_and_never_goes_to_pingwin(): void
    {
        Bus::fake();
        $bad = '5601660999589'; // formato EAN-13, controlo errado
        // Documento PingWin ligado (F3) com o artigo: a regra b liga, mas NÃO aprende o código mal lido.
        PingwinSupplierDocument::create(['company_id' => $this->yuko->id, 'docheader_id' => 'DX', 'docconfig_id' => '1209', 'document' => 'VFT X',
            'entity_pingwin_id' => 'SUPM', 'tax_number' => self::MAKRO_NIF, 'doc_date' => '2026-05-12', 'total_cents' => 100, 'docstatus_id' => '8002']);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => 'DX', 'line_number' => 1, 'article_id' => $this->ovos->id,
            'qnt' => 1, 'price' => '1.000000', 'total_cents' => 100]);
        $inv = $this->invoice([['supplier_code' => $bad, 'item' => 'OVOS']]);
        OcrInvoicePingwinLink::create(['company_id' => $this->yuko->id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => 'DX', 'method' => 'numero', 'status' => 'confirmada']);
        $this->svc()->linkInvoice($inv);
        $this->assertSame(['ligada', 'pingwin'], [$this->line($inv)->link_state, $this->line($inv)->link_method]);
        $this->assertSame(0, SupplierArticleMap::count(), 'não entra no mapa sozinho');

        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")->assertOk();
        $this->assertSame(['kind' => 'EAN-13', 'valid' => false], $res->json('data.invoice.lines.0.supplier_code_check'));

        // À mão entra no mapa, mas não vai para o PingWin; "Tornar principal" recusa.
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article'), ['article_id' => $this->ovos->id])->assertOk()
            ->assertJsonPath('data.line_links.0.supplier_code_status', null);
        $this->assertDatabaseHas('supplier_article_map', ['supplier_code' => $bad, 'article_id' => $this->ovos->id, 'source' => 'manual']);
        Bus::assertNotDispatched(WriteArticleSupplierCodeJob::class);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/supplier-code/principal'))->assertStatus(422)
            ->assertJsonPath('message', 'O código parece mal lido (dígito de controlo errado): não vai para o PingWin.');
    }

    public function test_gtin14_and_ean13_of_the_same_product_suggest_but_never_link(): void
    {
        $caixa = $this->article('100900', 'OVOS CAIXA');
        // Conhecido: o EAN-13 da unidade 5601660982504 → ovos. A linha traz o GTIN-14 da caixa.
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => self::MAKRO_NIF, 'supplier_code_norm' => '5601660982504',
            'supplier_code' => '5601660982504', 'article_id' => $this->ovos->id, 'source' => 'manual']);
        $inv = $this->invoice([['supplier_code' => self::GTIN, 'item' => 'PRODUTO SEM NOME PARECIDO']]);
        $this->svc()->linkInvoice($inv);
        $l = $this->line($inv);
        $this->assertSame('sugerida', $l->link_state);
        $this->assertNull($l->article_id);
        $this->assertSame(['article_id' => $this->ovos->id, 'score' => 0.95, 'reason' => 'gtin_caixa', 'code' => '5601660982504'], $l->link_suggestions[0]);
        // "Aceitar sugestões" não as aceita sozinha (pede a confirmação da unidade e da quantidade).
        $r = $this->svc()->acceptSuggestions($inv, 0.6, $this->user->id);
        $this->assertSame(0, $r['accepted']);
        $this->assertNull($this->line($inv)->article_id);

        // O contrário: conhecido o GTIN-14 da caixa → a linha com o EAN-13 da unidade.
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => self::MAKRO_NIF, 'supplier_code_norm' => '15601660999585',
            'supplier_code' => '15601660999585', 'article_id' => $caixa->id, 'source' => 'manual']);
        $inv2 = $this->invoice([['supplier_code' => self::EAN, 'item' => 'OUTRA COISA']]);
        $this->svc()->linkInvoice($inv2);
        $this->assertSame([$caixa->id, 'gtin_unidade'], [$this->line($inv2)->link_suggestions[0]['article_id'], $this->line($inv2)->link_suggestions[0]['reason']]);
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv2, null, '/lines/relink'))->assertOk();
        $this->assertSame('gtin_unidade', $res->json('data.line_links.0.suggestions.0.reason'));
    }

    // ───────────────────────────────────────────────────────────── IVA

    public function test_vat_warning_and_correct_article_vat_with_reread(): void
    {
        Bus::fake();
        $oleo = $this->article('100500', 'OLEO 5LT', ['taxgroup' => 'Intermédia']);           // artigo 13%
        $inv = $this->invoice([['supplier_code' => '29251', 'item' => 'OLEO', 'vat_rate' => 23],  // fatura 23%
            ['supplier_code' => '29252', 'item' => 'OVOS', 'vat_rate' => 6]]);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article'), ['article_id' => $oleo->id])->assertOk()
            ->assertJsonPath('data.line_links.0.vat_check', ['invoice_rate' => 23, 'article_rate' => 13, 'invoice_taxgroup' => '1003001']);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv, 1), '/article'), ['article_id' => $this->ovos->id])->assertOk()
            ->assertJsonPath('data.line_links.1.vat_check', null); // 6% = 6%

        // Igual → recusado; diferente → escrita de artigo com o taxgroup da fatura.
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv, 1), '/article-vat'))->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article-vat'))->assertStatus(202)
            ->assertJsonPath('data.line_links.0.vat_write.status', 'a_editar');
        $write = PingwinCatalogWrite::where('catalog_item_id', $oleo->id)->sole();
        $this->assertSame(['editar', ['taxgroup_id' => '1003001'], 'a_editar'], [$write->action, $write->payload, $write->status]);
        Bus::assertDispatched(UpdatePingwinCatalogJob::class, fn ($j) => $j->writeId === $write->id);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url($inv, $this->line($inv), '/article-vat'))->assertStatus(422); // em curso

        // Releitura com outro grupo → erro, o espelho não muda.
        $this->runVatJob($write, '1003002');
        $this->assertSame('erro', $write->fresh()->status);
        $this->assertSame('Intermédia', $oleo->fresh()->taxgroup);

        // Releitura certa → ok; o espelho passa a 23% (id e nome) e o aviso desaparece.
        $write2 = PingwinCatalogWrite::create(['company_id' => $this->yuko->id, 'action' => 'editar', 'catalog_item_id' => $oleo->id, 'code' => '100500',
            'description' => 'OLEO 5LT', 'payload' => ['taxgroup_id' => '1003001'], 'status' => 'a_editar']);
        $this->line($inv)->update(['vat_write_id' => $write2->id]);
        $this->runVatJob($write2, '1003001');
        $this->assertSame('ok', $write2->fresh()->status);
        $this->assertSame(['1003001', 'Normal'], [$oleo->fresh()->taxgroup_id, $oleo->fresh()->taxgroup]);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")->assertOk()
            ->assertJsonPath('data.invoice.lines.0.vat_check', null)->assertJsonPath('data.invoice.lines.0.vat_write.status', 'ok');
    }

    private function runVatJob(PingwinCatalogWrite $write, string $rereadTaxgroup): void
    {
        $this->app->instance(PingwinService::class, new class($rereadTaxgroup) extends PingwinService {
            public function __construct(private string $tg) {}
            public function updateProduct(int $companyId, string $productId, array $changes, ?string $saleprice = null, ?string $purchaseprice = null, ?array $supplierPricesChanges = null): array
            {
                return ['ok' => true, 'confirm' => ['taxgroup_id' => $this->tg, 'status' => null]];
            }
        });
        $alerts = $this->createMock(AlertService::class);
        (new UpdatePingwinCatalogJob($this->yuko->id, $write->id))->handle(app(PingwinService::class), $alerts);
    }

    public function test_vat_report_from_f4_lines(): void
    {
        $limao = $this->article('100608', 'PEDRAS LIMAO', ['taxgroup' => 'Isenta']);   // 0%
        $oleo = $this->article('100500', 'OLEO', ['taxgroup_id' => '1003002']);        // 13% (pelo id)
        $this->f4Line('D1', null, $limao->id, '1003001', '2026-08-01', '500000000', '8002', 'SUPER BOCK');
        $this->f4Line('D2', null, $limao->id, '1003001', '2026-08-27', '500000000', '8002', 'SUPER BOCK');
        $this->f4Line('D3', null, $limao->id, '1003004', '2026-07-01');                 // igual: não conta como divergente
        $this->f4Line('D4', null, $limao->id, '1003002', '2026-09-30', '500000000', '8003', 'ANULADO'); // anulado: fora
        $this->f4Line('D5', null, $oleo->id, '1003001', '2026-06-18', self::MAKRO_NIF, '8002', 'Makro');
        $this->f4Line('D6', null, $this->ovos->id, '1003003');                          // ovos 6% = 6%: fora

        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/catalog/vat-divergences")->assertOk();
        $this->assertSame(['articles_checked' => 3, 'articles_divergent' => 2, 'lines_divergent' => 3, 'articles_no_rate' => 0], $res->json('data.summary'));
        $first = $res->json('data.articles.0');
        $this->assertSame(['100608', 0, [['rate' => 23, 'lines' => 2]], 2, 3, [['name' => 'SUPER BOCK', 'lines' => 2]], '2026-08-27'],
            [$first['code'], $first['article_rate'], $first['invoiced_rates'], $first['divergent_lines'], $first['total_lines'], $first['suppliers'], $first['last_date']]);
        $this->assertSame(['100500', 13, 23], [$res->json('data.articles.1.code'), $res->json('data.articles.1.article_rate'), $res->json('data.articles.1.invoiced_rates.0.rate')]);
    }

    // ───────────────────────────────────────────────────────────── ficha do artigo, pesquisa, tenancy

    public function test_article_supplier_codes_and_search_show_principal_and_alternatives(): void
    {
        $this->pingwinCode($this->ovos, self::EAN);
        $this->f4Line('D1', self::EAN, $this->ovos->id);
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => self::MAKRO_NIF, 'supplier_code_norm' => self::GTIN,
            'supplier_code' => self::GTIN, 'article_id' => $this->ovos->id, 'source' => 'manual', 'times_seen' => 1]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/articles/{$this->ovos->id}/supplier-codes")->assertOk();
        $this->assertSame([self::MAKRO_NIF, 'Makro'], [$res->json('data.suppliers.0.nif'), $res->json('data.suppliers.0.supplier')]);
        $this->assertSame([[self::EAN, true], [self::GTIN, false]], collect($res->json('data.suppliers.0.codes'))->map(fn ($c) => [$c['code'], $c['principal']])->all());

        $s = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/articles/search?q=ovos&nif=" . self::MAKRO_NIF)->assertOk();
        $this->assertSame([[self::EAN, true], [self::GTIN, false]], collect($s->json('data.articles.0.supplier_code_info'))->map(fn ($c) => [$c['code'], $c['principal']])->all());
    }

    public function test_tenancy(): void
    {
        $inv = $this->invoice([['supplier_code' => self::GTIN, 'item' => 'OVOS']]);
        $l = $this->line($inv);
        foreach ([$this->url($inv, $l, '/supplier-code/principal'), $this->url($inv, $l, '/article-vat')] as $u) {
            $this->actingAs($this->otherUser, 'sanctum')->postJson($u)->assertStatus(403);
        }
        // Pela própria empresa (rota da outra) não encontra a fatura.
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/lines/{$l->id}/article-vat")->assertStatus(404);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/catalog/vat-divergences")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/articles/{$this->ovos->id}/supplier-codes")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->other->id}/integrations/pingwin/articles/{$this->ovos->id}/supplier-codes")->assertStatus(404);
        $this->assertSame(0, PingwinCatalogWrite::count());
    }
}
