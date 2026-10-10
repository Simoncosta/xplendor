<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CreatePingwinCatalogJob;
use App\Jobs\WriteArticleSupplierCodeJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCatalogWrite;
use App\Models\PingwinFamily;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use App\Services\OcrArticleSearchService;
use App\Services\OcrLineArticleService;
use App\Services\PingwinService;
use App\Services\SupplierArticleMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F2b: artigos nas linhas da fatura OCR. Bootstrap do mapa (normalização,
 * conflitos), ligação a → e, "nunca pelo nosso código", "pronta para lançar", associar/criar a
 * gravar no mapa, pergunta de substituição do código do fornecedor, escrita do código no artigo
 * (PingWin simulado), precisão de 6 casas, pesquisa de artigos e tenancy.
 */
class OcrLineArticleTest extends TestCase
{
    use RefreshDatabase;

    private Company $yuko;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinSupplier $carnes;
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
        $this->carnes = PingwinSupplier::create(['company_id' => $this->yuko->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPC', 'name' => 'Carnes Sá da Bandeira', 'tax_number' => '502 811 331', 'is_active' => true]);

        // ⚠️ PingWin simulado: qualquer chamada ao Python falha o teste (as escritas vêm por jobs simulados).
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
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
            'description' => $desc, 'is_active' => true, 'product_status' => 'Ativo', 'purchaseunit' => 'KG'], $extra));
    }

    private function invoice(array $lines, array $extra = [], ?Company $c = null): OcrInvoice
    {
        $inv = OcrInvoice::create(array_merge(['company_id' => ($c ?? $this->yuko)->id, 'image_path' => 'x.pdf', 'status' => 'por_validar',
            'supplier_nif' => '502811331', 'supplier_name' => 'Carnes Sá da Bandeira', 'number' => 'FD M501/1681', 'issue_date' => '2026-05-04'], $extra));
        foreach ($lines as $i => $l) {
            OcrInvoiceLine::create(array_merge(['ocr_invoice_id' => $inv->id, 'company_id' => $inv->company_id, 'position' => $i, 'quantity' => 1,
                'unit_price' => '1.000000', 'line_total_cents' => 100], $l));
        }

        return $inv;
    }

    /** Documento PingWin real (F4) do fornecedor Carnes, com uma linha. */
    private function f4Line(string $docId, string $supplierCode, int $articleId, string $date = '2026-05-04', string $nif = '502811331', string $price = '13.500000'): void
    {
        PingwinSupplierDocument::firstOrCreate(['company_id' => $this->yuko->id, 'docheader_id' => $docId], [
            'docconfig_id' => '1209', 'document' => "VFT 0VFT/{$docId}", 'entity_pingwin_id' => 'SUPC', 'entity_name' => 'CARNES',
            'tax_number' => $nif, 'doc_date' => $date, 'total_cents' => 100, 'docstatus_id' => '8002']);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => $docId,
            'line_number' => PingwinSupplierDocumentLine::where('docheader_id', $docId)->count() + 1,
            'supplier_code' => $supplierCode, 'article_id' => $articleId, 'price' => $price, 'unit_code' => 'KG', 'total_cents' => 100]);
    }

    private function svc(): OcrLineArticleService
    {
        return app(OcrLineArticleService::class);
    }

    private function line(OcrInvoice $inv, int $pos = 0): OcrInvoiceLine
    {
        return $inv->lines()->where('position', $pos)->first();
    }

    // ───────────────────────────────────────────────────────────── normalização e bootstrap

    public function test_normalization(): void
    {
        $this->assertSame('5', SupplierArticleMapService::normCode('  00005 '));
        $this->assertSame('2900128047001', SupplierArticleMapService::normCode('29 001280 47001'));
        $this->assertSame('A-12', SupplierArticleMapService::normCode('a-12'));
        $this->assertSame('0', SupplierArticleMapService::normCode('000'));
        $this->assertSame('502811331', SupplierArticleMapService::nif('PT 502 811 331'));
        $this->assertSame('pimento verde', OcrLineArticleService::normDesc('  PIMENTO   Verde!! '));
        $this->assertSame('cafe acucar', OcrLineArticleService::normDesc('Café / Açúcar'));
    }

    public function test_bootstrap_from_f4_and_supplier_prices_with_conflicts(): void
    {
        $bife = $this->article('100014', 'BIFE NOVILHO');
        $old = $this->article('100165', 'PRESUNTO (descontinuado)', ['product_status' => 'Descontinuado']);
        $new = $this->article('101159', 'PRESUNTO S/ OSSO');
        $frango = $this->article('100478', 'FRANGO');
        $a = $this->article('100800', 'A');
        $b = $this->article('100801', 'B');

        $this->f4Line('D1', '00005', $bife->id);
        $this->f4Line('D2', '5', $bife->id, '2026-06-01', '502811331', '14.100000');   // mesmo código normalizado
        foreach (range(1, 5) as $i) {
            $this->f4Line("P{$i}", '29251', $old->id, '2026-04-0' . $i);              // frequente mas descontinuado
        }
        $this->f4Line('P9', '29251', $new->id, '2026-09-04');
        $this->f4Line('X1', '777', $a->id, '2026-05-01');
        $this->f4Line('X2', '777', $a->id, '2026-05-02');
        $this->f4Line('X3', '777', $b->id, '2026-05-03');                               // conflito: fica o mais frequente
        PingwinSupplierPrice::create(['company_id' => $this->yuko->id, 'catalog_item_id' => $frango->id, 'product_pingwin_id' => $frango->pingwin_id,
            'supplier_pingwin_id' => 'SUPC', 'line_pingwin_id' => 'L1', 'sup_product_code' => '00058', 'price_cents' => 289, 'is_active' => true]);
        // Uma entrada manual nunca é refeita pelo bootstrap.
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => '502811331', 'supplier_code_norm' => '777',
            'supplier_code' => '777', 'article_id' => $b->id, 'source' => 'manual']);

        $s = app(SupplierArticleMapService::class)->bootstrap($this->yuko->id);

        $this->assertSame(4, $s['pairs']);
        $this->assertSame(1, $s['suppliers']);
        $this->assertSame(2, $s['conflicts']);   // 29251 e 777
        $this->assertSame(1, $s['kept_manual']);
        $m = fn ($code) => SupplierArticleMap::where('supplier_code_norm', $code)->first();
        $this->assertSame($bife->id, $m('5')->article_id);
        $this->assertSame(2, $m('5')->times_seen);
        $this->assertSame('14.100000', (string) $m('5')->last_price);                 // o mais recente
        $this->assertSame($new->id, $m('29251')->article_id);                         // utilizável ganha ao descontinuado
        $this->assertSame(1, $m('29251')->conflicts);
        $this->assertSame($frango->id, $m('58')->article_id);
        $this->assertSame('pingwin_supplierprices', $m('58')->source);
        $this->assertSame($b->id, $m('777')->article_id);                             // manual intocado
        $this->assertSame('manual', $m('777')->source);
    }

    // ───────────────────────────────────────────────────────────── ligação a → e

    public function test_link_order_map_pingwin_description_suggestion_unlinked(): void
    {
        $bife = $this->article('100014', 'BIFE NOVILHO');
        $frango = $this->article('100478', 'FRANGO');
        $peru = $this->article('100063', 'BIFE DE PERU');
        $verde = $this->article('100900', 'MC PIMENTO VERDE');
        SupplierArticleMap::create(['company_id' => $this->yuko->id, 'supplier_nif' => '502811331', 'supplier_code_norm' => '5',
            'supplier_code' => '00005', 'article_id' => $bife->id, 'source' => 'f4_bootstrap']);
        $inv = $this->invoice([
            ['supplier_code' => '00005', 'item' => 'qualquer coisa', 'line_total_cents' => 94500],                          // a. mapa
            ['supplier_code' => '00058', 'item' => 'AVE', 'quantity' => 36.1, 'unit_price' => '2.890000', 'line_total_cents' => 10433], // b. PingWin
            ['item' => 'Bife de Peru', 'line_total_cents' => 5584],                                                         // c. descrição igual
            ['item' => 'MC PIMENTO VERMELHO', 'line_total_cents' => 300],                                                   // d. só sugestão
            ['item' => 'xyz', 'line_total_cents' => 1],                                                                     // e. nada
        ]);
        // b: a fatura está ligada a 1 documento PingWin cuja linha (mesmo total/qtd/preço) é o FRANGO.
        PingwinSupplierDocument::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'docconfig_id' => '1209', 'tax_number' => '502811331', 'total_cents' => 1, 'docstatus_id' => '8002']);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'line_number' => 1, 'article_id' => $frango->id,
            'qnt' => '36.100000', 'price' => '2.890000', 'total_cents' => 10433]);
        OcrInvoicePingwinLink::create(['company_id' => $this->yuko->id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => '57', 'method' => 'total_data']);

        $count = $this->svc()->linkInvoice($inv);

        $l = fn ($p) => $this->line($inv, $p);
        $this->assertSame([$bife->id, 'ligada', 'mapa'], [$l(0)->article_id, $l(0)->link_state, $l(0)->link_method]);
        $this->assertSame([$frango->id, 'ligada', 'pingwin'], [$l(1)->article_id, $l(1)->link_state, $l(1)->link_method]);
        $this->assertSame([$peru->id, 'ligada', 'descricao'], [$l(2)->article_id, $l(2)->link_state, $l(2)->link_method]);
        $this->assertSame([null, 'sugerida'], [$l(3)->article_id, $l(3)->link_state]);  // VERMELHO ≠ VERDE: nunca automático
        $this->assertSame($verde->id, $l(3)->link_suggestions[0]['article_id']);
        $this->assertSame([null, 'por_ligar'], [$l(4)->article_id, $l(4)->link_state]);
        // b. com código do fornecedor → o par entrou no mapa (ocr_link)
        $this->assertDatabaseHas('supplier_article_map', ['supplier_nif' => '502811331', 'supplier_code_norm' => '58', 'article_id' => $frango->id, 'source' => 'ocr_link']);
        $this->assertSame(['mapa' => 1, 'pingwin' => 1, 'descricao' => 1, 'sugerida' => 1, 'por_ligar' => 1, 'manual' => 0], $count);
        $this->assertFalse(OcrLineArticleService::summary($inv->lines()->get())['ready']);
    }

    public function test_never_links_by_our_own_article_code(): void
    {
        // Spike F2-0: o código do fornecedor 101031 ("Forma Redonda") é o NOSSO código do barril.
        $this->article('101031', 'BARRIL S. BOCK 30L');
        $inv = $this->invoice([['supplier_code' => '101031', 'item' => 'Forma Redonda - Fatiada']], ['supplier_nif' => '516182609']);
        $this->svc()->linkInvoice($inv);
        $this->assertNull($this->line($inv)->article_id);
        $this->assertNotSame('ligada', $this->line($inv)->link_state);
    }

    public function test_inactive_or_discontinued_articles_never_link(): void
    {
        $this->article('1', 'FRANGO', ['product_status' => 'Descontinuado']);
        $this->article('2', 'FRANGO', ['is_active' => false], null);
        $inv = $this->invoice([['item' => 'Frango']]);
        $this->svc()->linkInvoice($inv);
        $this->assertSame('por_ligar', $this->line($inv)->link_state);
    }

    public function test_ready_to_launch_only_when_all_lines_linked(): void
    {
        $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['item' => 'Frango'], ['item' => 'Frango']]);
        $this->svc()->linkInvoice($inv);
        $this->assertTrue(OcrLineArticleService::summary($inv->lines()->get())['ready']);
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 9, 'item' => 'nada parecido']);
        $this->svc()->linkInvoice($inv);
        $this->assertFalse(OcrLineArticleService::summary($inv->lines()->get())['ready']);
    }

    public function test_manual_links_are_never_redone_and_accept_suggestions(): void
    {
        $frango = $this->article('100478', 'FRANGO');
        $verde = $this->article('100900', 'MC PIMENTO VERDE');
        $inv = $this->invoice([['item' => 'xyz'], ['item' => 'MC PIMENTO VERMELHO']]);
        Bus::fake();
        $this->svc()->associate($inv, $this->line($inv), $frango->id, $this->user->id);
        $this->svc()->linkInvoice($inv);
        $this->assertSame([$frango->id, 'manual'], [$this->line($inv)->article_id, $this->line($inv)->link_method]);

        $res = $this->svc()->acceptSuggestions($inv->fresh(), 0.95, $this->user->id);
        $this->assertSame(0, $res['accepted']);                       // abaixo do mínimo
        $res = $this->svc()->acceptSuggestions($inv->fresh(), 0.6, $this->user->id);
        $this->assertSame(1, $res['accepted']);
        $this->assertSame([$verde->id, 'sugestao'], [$this->line($inv, 1)->article_id, $this->line($inv, 1)->link_method]);
        $this->assertTrue(OcrLineArticleService::summary($inv->lines()->get())['ready']);
    }

    // ───────────────────────────────────────────────────────────── associar / criar / código do fornecedor

    public function test_associate_learns_map_and_queues_supplier_code_write(): void
    {
        Bus::fake();
        $frango = $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE']]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$this->line($inv)->id}/article", ['article_id' => $frango->id])
            ->assertOk()->assertJsonPath('data.line_links.0.article.code', '100478')->assertJsonPath('data.line_links.0.supplier_code_status', 'pendente')
            ->assertJsonPath('data.articles_summary.ready', true);

        $this->assertDatabaseHas('supplier_article_map', ['supplier_nif' => '502811331', 'supplier_code_norm' => '58', 'article_id' => $frango->id, 'source' => 'manual']);
        Bus::assertDispatched(WriteArticleSupplierCodeJob::class, fn ($j) => $j->lineId === $this->line($inv)->id && $j->replace === false);
    }

    public function test_associate_asks_before_replacing_another_supplier_code(): void
    {
        Bus::fake();
        $frango = $this->article('100478', 'FRANGO');
        PingwinSupplierPrice::create(['company_id' => $this->yuko->id, 'catalog_item_id' => $frango->id, 'product_pingwin_id' => $frango->pingwin_id,
            'supplier_pingwin_id' => 'SUPC', 'line_pingwin_id' => 'L1', 'sup_product_code' => 'ANTIGO-9', 'is_active' => true]);
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE']]);
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$this->line($inv)->id}/article";

        $this->actingAs($this->user, 'sanctum')->postJson($url, ['article_id' => $frango->id])
            ->assertStatus(409)->assertJsonPath('errors.code', 'codigo_diferente')->assertJsonPath('errors.existing_code', 'ANTIGO-9');
        $this->assertNull($this->line($inv)->article_id);
        Bus::assertNotDispatched(WriteArticleSupplierCodeJob::class);

        // Como o frontend envia (multipart: booleanos em texto).
        $this->actingAs($this->user, 'sanctum')->post($url, ['article_id' => (string) $frango->id, 'replace_code' => 'false'], ['Accept' => 'application/json'])->assertStatus(409);
        $this->actingAs($this->user, 'sanctum')->post($url, ['article_id' => (string) $frango->id, 'replace_code' => 'true'], ['Accept' => 'application/json'])->assertOk();
        Bus::assertDispatched(WriteArticleSupplierCodeJob::class, fn ($j) => $j->replace === true);
    }

    public function test_same_code_already_on_article_needs_no_write(): void
    {
        Bus::fake();
        $frango = $this->article('100478', 'FRANGO');
        PingwinSupplierPrice::create(['company_id' => $this->yuko->id, 'catalog_item_id' => $frango->id, 'product_pingwin_id' => $frango->pingwin_id,
            'supplier_pingwin_id' => 'SUPC', 'line_pingwin_id' => 'L1', 'sup_product_code' => '58', 'is_active' => true]);
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE']]);
        $this->svc()->associate($inv, $this->line($inv), $frango->id, $this->user->id);
        $this->assertSame('ok', $this->line($inv)->supplier_code_status);
        Bus::assertNotDispatched(WriteArticleSupplierCodeJob::class);
    }

    public function test_unlink_manual_forgets_learned_pair(): void
    {
        Bus::fake();
        $frango = $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['supplier_code' => 'Z1', 'item' => 'xyz']]);
        $this->svc()->associate($inv, $this->line($inv), $frango->id, $this->user->id);
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$this->line($inv)->id}/article")->assertOk();
        $this->assertSame('por_ligar', $this->line($inv)->link_state);
        $this->assertDatabaseMissing('supplier_article_map', ['supplier_code_norm' => 'Z1']);
    }

    public function test_create_article_uses_existing_flow_then_links_when_confirmed(): void
    {
        Bus::fake();
        PingwinFamily::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'F1', 'description' => 'Fruta', 'parent_pingwin_id' => 'ROOT', 'is_active' => true]);
        $inv = $this->invoice([['supplier_code' => 'K-7', 'item' => 'Kiwi gold', 'unit' => 'KG', 'unit_price' => '3.415000', 'vat_rate' => 6]]);
        $line = $this->line($inv);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$line->id}/create-article", [
            'description' => 'KIWI GOLD', 'family_id' => 'F1', 'taxgroup_id' => '1003003', 'unit_id' => '11002', 'purchase_price' => 3.415,
        ])->assertStatus(202);

        $write = PingwinCatalogWrite::where('action', 'criar')->firstOrFail();
        $this->assertSame('a_criar', $write->status);
        $this->assertSame(['F1', '1003003', '11002', 1, 0], [$write->payload['family_id'], $write->payload['taxgroup_id'], $write->payload['base_unit_id'], $write->payload['forpurchase'], $write->payload['forsale']]);
        $this->assertSame(342, $write->purchaseprice_cents);
        Bus::assertDispatched(CreatePingwinCatalogJob::class);
        $this->assertSame($write->id, $line->fresh()->article_write_id);

        // O job confirma a criação (releitura) → o espelho ganha o artigo → a linha liga-se.
        $item = $this->article('101300', 'KIWI GOLD', ['pingwin_id' => 'PNEW']);
        $write->update(['status' => 'ok', 'pingwin_id' => 'PNEW']);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}")
            ->assertOk()->assertJsonPath('data.invoice.lines.0.article.id', $item->id)->assertJsonPath('data.invoice.lines.0.link_method', 'criado');
        $this->assertDatabaseHas('supplier_article_map', ['supplier_code_norm' => 'K-7', 'article_id' => $item->id, 'source' => 'manual']);
        Bus::assertDispatched(WriteArticleSupplierCodeJob::class);
    }

    /** O job de escrita do código do fornecedor (PingWin simulado: leitura + edição + espelho). */
    private function bindPingwinForCodeJob(array $supplierPrices, array $tables, bool $writeOk = true): void
    {
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test, $supplierPrices, $tables, $writeOk) extends PingwinService {
            public function __construct(private $test, private array $sp, private array $tables, private bool $ok) {}
            public function readProduct(int $companyId, string $productId): array
            {
                $this->test->pwCalls[] = ['read', $productId];

                return ['supplier_prices' => $this->sp, 'supplier_tables' => $this->tables];
            }
            public function updateProduct(int $companyId, string $productId, array $changes, ?string $saleprice = null, ?string $purchaseprice = null, ?array $supplierPricesChanges = null): array
            {
                $this->test->pwCalls[] = ['update', $productId, $changes, $supplierPricesChanges];
                if ($this->ok) { // releitura do servidor → espelho
                    $c = $supplierPricesChanges['create'][0] ?? null;
                    $u = $supplierPricesChanges['update'][0] ?? null;
                    PingwinSupplierPrice::updateOrCreate(['company_id' => $companyId, 'line_pingwin_id' => $u['line_pingwin_id'] ?? 'NEWLINE'],
                        ['product_pingwin_id' => $productId, 'supplier_pingwin_id' => 'SUPC', 'sup_product_code' => $c['sup_product_code'] ?? $u['sup_product_code'], 'is_active' => true]);
                }

                return ['ok' => $this->ok];
            }
        });
    }

    public function test_supplier_code_job_creates_line_in_supplier_table_and_confirms_by_reread(): void
    {
        $frango = $this->article('100478', 'FRANGO', ['default_purchase_unit_id' => '11002']);
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE', 'unit_price' => '2.890000']]);
        $line = $this->line($inv);
        $line->update(['article_id' => $frango->id, 'link_state' => 'ligada', 'link_method' => 'manual', 'supplier_code_status' => 'pendente']);
        $this->bindPingwinForCodeJob([], [['supplier_id' => 'SUPC', 'table_id' => 'TAB-C']]);

        (new WriteArticleSupplierCodeJob($this->yuko->id, $line->id))->handle(app(PingwinService::class), app(OcrLineArticleService::class));

        $this->assertSame('ok', $line->fresh()->supplier_code_status);
        $create = $this->pwCalls[1][3]['create'][0];
        $this->assertSame(['TAB-C', $this->carnes->id, '11002', 289, '00058'],
            [$create['supprice_header_id'], $create['supplier_id'], $create['unit_id'], $create['price_cents'], $create['sup_product_code']]);
        $this->assertSame([], $this->pwCalls[1][2]);                  // o artigo em si não muda
        $this->assertSame('ok', PingwinCatalogWrite::where('action', 'editar')->value('status'));
    }

    public function test_supplier_code_job_conflict_and_replace(): void
    {
        $frango = $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE']]);
        $line = $this->line($inv);
        $line->update(['article_id' => $frango->id, 'link_state' => 'ligada', 'link_method' => 'manual', 'supplier_code_status' => 'pendente']);
        $existing = [['line_pingwin_id' => 'L9', 'supplier' => ['pingwin_id' => 'SUPC'], 'sup_product_code' => 'ANTIGO']];
        $this->bindPingwinForCodeJob($existing, []);

        (new WriteArticleSupplierCodeJob($this->yuko->id, $line->id))->handle(app(PingwinService::class), app(OcrLineArticleService::class));
        $this->assertSame('conflito', $line->fresh()->supplier_code_status);
        $this->assertCount(1, $this->pwCalls);                        // só leu; não escreveu

        $line->refresh()->update(['supplier_code_status' => 'pendente']);
        (new WriteArticleSupplierCodeJob($this->yuko->id, $line->id, true))->handle(app(PingwinService::class), app(OcrLineArticleService::class));
        $this->assertSame('ok', $line->fresh()->supplier_code_status);
        $this->assertSame([['line_pingwin_id' => 'L9', 'sup_product_code' => '00058']], $this->pwCalls[2][3]['update']);
    }

    public function test_supplier_code_job_error_when_reread_does_not_confirm(): void
    {
        $frango = $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['supplier_code' => '00058', 'item' => 'AVE']]);
        $line = $this->line($inv);
        $line->update(['article_id' => $frango->id, 'link_state' => 'ligada', 'link_method' => 'manual', 'supplier_code_status' => 'pendente']);
        $this->bindPingwinForCodeJob([], [['supplier_id' => 'SUPC', 'table_id' => 'TAB-C']], false);
        (new WriteArticleSupplierCodeJob($this->yuko->id, $line->id))->handle(app(PingwinService::class), app(OcrLineArticleService::class));
        $this->assertSame('erro', $line->fresh()->supplier_code_status);
    }

    // ───────────────────────────────────────────────────────────── precisão, pesquisa, tenancy

    public function test_precision_six_decimals_in_parse_and_save(): void
    {
        $clean = app(InvoiceOcrService::class)->sanitize(['linhas' => [['item' => 'X', 'quantidade' => 16.175, 'precoUnitario' => 1.415, 'totalLinha' => 22.89]]]);
        $this->assertSame('1.415000', $clean['lines'][0]['unit_price']);
        $this->assertSame(16.175, $clean['lines'][0]['quantity']);

        $frango = $this->article('100478', 'FRANGO');
        $inv = $this->invoice([['item' => 'Frango']]);
        $this->svc()->linkInvoice($inv);
        $line = $this->line($inv);
        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}", [
            'lines' => [['id' => $line->id, 'item' => 'Frango', 'quantity' => 16.175, 'unit_price' => 2.123456, 'line_total' => 34.35]],
            'summary' => ['total' => 34.35],
        ])->assertOk()->assertJsonPath('data.invoice.lines.0.unit_price', 2.123456);
        $line->refresh();
        $this->assertSame('2.123456', (string) $line->unit_price);
        $this->assertSame('16.175000', (string) $line->quantity);
        $this->assertSame($frango->id, $line->article_id);            // a ligação sobrevive à validação
    }

    public function test_article_search_by_code_description_supplier_code_and_supplier_name(): void
    {
        $bife = $this->article('100014', 'BIFE NOVILHO');
        $this->article('100099', 'Pão de Ló');
        $this->article('100555', 'NOVILHO PICADO');
        $this->f4Line('D1', '00005', $bife->id, '2026-05-04', '502811331', '13.500000');
        $s = app(OcrArticleSearchService::class);
        $codes = fn ($q, $nif = null) => array_column($s->search($this->yuko->id, $q, $nif), 'code');

        $this->assertSame(['100014'], $codes('100014'));
        $this->assertSame(['100099'], $codes('pao de lo'));                       // sem acentos
        $this->assertSame(['100014'], $codes('00005'));                           // código do fornecedor
        $this->assertSame(['100014'], $codes('carnes bife'));                     // nome do fornecedor + palavra
        $this->assertSame(['100014', '100555'], $codes('novilho', '502811331'));  // já comprado a este fornecedor primeiro
        // Nenhum artigo tem todas as palavras → aproximados, os com mais palavras em comum primeiro.
        $approx = $s->search($this->yuko->id, 'BIFE NOVILHO 2KG EMBALADO');
        $this->assertSame('100014', $approx[0]['code']);
        $this->assertTrue($approx[0]['approximate']);
        $this->assertFalse($s->search($this->yuko->id, 'bife novilho')[0]['approximate']);
        $hit = $s->search($this->yuko->id, 'novilho', '502811331')[0];
        $this->assertTrue($hit['bought_from_supplier']);
        $this->assertSame(['price' => 13.5, 'unit' => 'KG', 'date' => '2026-05-04', 'supplier' => 'CARNES'], $hit['last_purchase']);
    }

    public function test_tenancy(): void
    {
        $theirs = $this->article('1', 'FRANGO', [], $this->other);
        $inv = $this->invoice([['item' => 'xyz']]);
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$this->line($inv)->id}/article";
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['article_id' => $theirs->id])->assertStatus(422); // artigo de outra empresa
        $this->actingAs($this->otherUser, 'sanctum')->postJson($url, ['article_id' => $theirs->id])->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/lines/{$this->line($inv)->id}/article", ['article_id' => $theirs->id])
            ->assertStatus(404);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/articles/search?q=frango")->assertStatus(403);
    }

    public function test_bootstrap_command(): void
    {
        $bife = $this->article('100014', 'BIFE NOVILHO');
        $this->f4Line('D1', '00005', $bife->id);
        $this->artisan('ocr:bootstrap-article-map', ['company' => $this->yuko->id])->expectsOutputToContain('1')->assertExitCode(0);
        $this->assertSame(1, SupplierArticleMap::count());
    }
}
