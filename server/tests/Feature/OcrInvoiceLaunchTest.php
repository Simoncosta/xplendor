<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\LaunchPingwinDocumentJob;
use App\Models\ArticleGrouping;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentWrite;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinUnit;
use App\Models\ProductUnitProfile;
use App\Models\OcrInvoiceDiscount;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\OcrInvoiceLaunchService;
use App\Services\OcrPingwinLinkService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — FB-1: "Lançar no PingWin" (rascunho 8001 → fechar 8002 / anular 8003). PingWin
 * simulado (qualquer chamada real ao Python falha). Cobre: a matriz de guardas (cada uma bloqueia),
 * tipos fora do âmbito, unidade escolhida, idempotência/lock, os estados da escrita, a confirmação
 * por releitura (ok / erro_confirmacao), a ligação F3 criada, fechar e anular, e tenancy.
 */
class OcrInvoiceLaunchTest extends TestCase
{
    use RefreshDatabase;

    private Company $yuko;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinSupplier $supplier;
    private PingwinCatalogItem $a;
    private PingwinCatalogItem $b;
    public array $calls = [];
    public array $liveActive = [];
    public array $liveDocs = [];
    public ?array $launchResult = null;
    public ?array $statusReread = null;

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
        $this->supplier = PingwinSupplier::create(['company_id' => $this->yuko->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPX', 'name' => 'TESTE FB1',
            'tax_number' => '123456783', 'is_active' => true]);
        foreach ([['11001', 'Unidade', 'UN', null], ['11002', 'Quilograma', 'KG', null], ['CX6', 'Caixa 6 Unidades', 'CX 6', 'PB']] as [$id, $d, $sh, $prod]) {
            PingwinUnit::create(['company_id' => $this->yuko->id, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sh, 'product_pingwin_id' => $prod, 'is_active' => true]);
        }
        $this->a = PingwinCatalogItem::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'PA', 'code' => '901', 'description' => 'ARTIGO A',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11002', 'default_purchase_unit_id' => '11002', 'purchaseunit' => 'Quilograma']);
        $this->b = PingwinCatalogItem::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'PB', 'code' => '902', 'description' => 'ARTIGO B',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001', 'purchaseunit' => 'Unidade']);
        $this->liveActive = [['id' => 'SUPX', 'tax_number' => '123456783']];

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
            public function findSuppliersByNif(int $companyId, string $nif): array
            {
                $this->test->calls[] = ['find', $nif];

                return ['active' => $this->test->liveActive, 'voided' => []];
            }
            public function fetchSupplierDocuments(int $companyId, string $from, string $to): array
            {
                $this->test->calls[] = ['docs', $from, $to];

                return ['documents' => $this->test->liveDocs, 'blocks' => 1, 'splits' => 0];
            }
            public function launchSupplierInvoice(int $companyId, array $doc): array
            {
                $this->test->calls[] = ['launch', $doc];

                return $this->test->launchResult;
            }
            public function documentStatus(int $companyId, string $docheaderId, string $status, string $docconfigId = '1209'): array
            {
                $this->test->calls[] = ['status', $docheaderId, $status, $docconfigId];

                return ['result' => ['ok' => true, 'docstatus_id' => $status], 'reread' => $this->test->statusReread ?? ['ok' => true, 'header' => ['docstatus_id' => $status], 'details' => []]];
            }
        });
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** Fatura pronta a lançar: QR coerente, linhas ligadas; L2 em "CX" (escolha de unidade). Total 10,38. */
    private function invoice(array $o = [], array $l2 = []): OcrInvoice
    {
        $inv = OcrInvoice::create(array_merge([
            'company_id' => $this->yuko->id, 'image_path' => 'x.pdf', 'status' => 'por_validar', 'supplier_nif' => '123456783',
            'supplier_name' => 'TESTE FB1', 'buyer_nif' => '514148497', 'number' => 'FT TESTE/1', 'issue_date' => '2026-10-05', 'doc_type' => 'FT',
            'qr_ok' => true, 'check_status' => 'confere', 'link_status' => 'nao_lancada',
            'qr_data' => ['fields' => ['O' => '10.38'], 'by_rate' => [['rate' => 6, 'base_cents' => 283, 'vat_cents' => 17], ['rate' => 23, 'base_cents' => 600, 'vat_cents' => 138]]],
        ], $o));
        OcrInvoiceSummary::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'total_cents' => 1038]);
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 0, 'item' => 'a', 'quantity' => 2, 'unit' => 'Kg',
            'unit_price' => '1.415000', 'line_total_cents' => 283, 'vat_rate' => 6, 'article_id' => $this->a->id, 'link_state' => 'ligada', 'link_method' => 'mapa']);
        OcrInvoiceLine::create(array_merge(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 1, 'item' => 'b', 'quantity' => 3, 'unit' => 'CX',
            'unit_price' => '2.000000', 'line_total_cents' => 600, 'vat_rate' => 23, 'article_id' => $this->b->id, 'link_state' => 'ligada', 'link_method' => 'mapa',
            'launch_unit_id' => 'CX6'], $l2));

        return $inv;
    }

    /** As unidades dos artigos confirmadas no Guião de unidades (o fecho exige-as). */
    private function confirmUnits(array $pingwinIds = ['PA', 'PB']): void
    {
        foreach ($pingwinIds as $id) {
            ProductUnitProfile::create(['company_id' => $this->yuko->id, 'product_pingwin_id' => $id, 'state' => 'confirmado',
                'confirmed_by' => $this->user->id, 'confirmed_at' => now()]);
        }
    }

    private function svc(): OcrInvoiceLaunchService
    {
        return app(OcrInvoiceLaunchService::class);
    }

    private function failedGuards(OcrInvoice $inv): array
    {
        return collect($this->svc()->preview($inv->fresh())['guards'])->where('ok', false)->pluck('key')->values()->all();
    }

    private function okLaunch(string $total = '10.38', array $reread = []): void
    {
        $this->launchResult = [
            'result' => ['ok' => true, 'saved' => true, 'docheader_id' => 'DOC1', 'docconfig_id' => '1209', 'document' => 'VFT BOVFT/1077', 'adjustment' => '0.00',
                'header_before_save' => ['id' => 'DOC1', 'doc_prefix' => 'VFT BOVFT', 'doc_number' => 1077, 'store_code' => 'BO'],
                'lines' => [['total' => 2.83, 'tax_description' => 'Reduzida'], ['total' => 6.0, 'tax_description' => 'Normal', 'tax_override' => ['from' => '1003003', 'to' => '1003001']]]],
            'reread' => array_replace_recursive(['ok' => true, 'header' => ['id' => 'DOC1', 'doc_prefix' => 'VFT BOVFT', 'doc_number' => 1077, 'docstatus_id' => '8001',
                'entity_id' => 'SUPX', 'total' => (float) $total, 'total_products' => 8.83, 'total_tax' => 1.55, 'docreference_number' => 'FT TESTE/1',
                'doc_date' => '20261010T00:00:00', 'store_id' => 'S1', 'detail_discount_value' => 0],
                'details' => [['line_number' => 1, 'product_id' => 'PA', 'total' => 2.83, 'tax_value' => 0.17, 'qnt' => 2, 'price' => 1.415],
                    ['line_number' => 2, 'product_id' => 'PB', 'total' => 6.0, 'tax_value' => 1.38, 'qnt' => 3, 'price' => 2.0]]], $reread),
        ];
    }

    private function launchNow(OcrInvoice $inv): PingwinDocumentWrite
    {
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $w = $this->svc()->requestLaunch($inv, $this->user->id, false);

        return $this->svc()->execute($w->id);
    }

    // ───────────────────────────────────────────────────────────── guardas

    public function test_ready_invoice_passes_all_guards_with_chosen_unit(): void
    {
        $p = $this->svc()->preview($this->invoice());
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values()));
        $this->assertSame(['11002', 'CX6'], array_column($p['lines']->all(), 'unit_id'));
        $this->assertSame(['fatura', 'escolhida'], array_column($p['lines']->all(), 'unit_source'));
        $this->assertSame(0, $p['estimate']['adjustment_cents']);
    }

    /**
     * Fatura com IEC numa coluna à parte (os números de uma fatura real de bebidas, sem nomes): 4 bebidas
     * a 23 % com o IEC somado e 4 caixas de vasilhame a 0 %; total 153,66 €.
     */
    private function iecInvoice(): OcrInvoice
    {
        $inv = $this->invoice(['qr_data' => ['fields' => ['O' => '153.66'], 'by_rate' => [['rate' => 0, 'base_cents' => 3500, 'vat_cents' => 0], ['rate' => 23, 'base_cents' => 9647, 'vat_cents' => 2219]]]]);
        $inv->lines()->delete();
        $rows = [['Bebida A 24', 1, '20.880000', 35.01, 1412, 55, 23], ['Caixa A', 1, '5.000000', 0, 500, null, 0], ['Bebida B 24', 1, '20.160000', 35.02, 1360, 50, 23],
            ['Caixa B', 1, '5.000000', 0, 500, null, 0], ['Bebida C 24', 3, '20.880000', 34.99, 4237, 165, 23], ['Caixa C', 3, '5.000000', 0, 1500, null, 0],
            ['Bebida D 24', 2, '20.160000', 35.0, 2638, 17, 23], ['Caixa D', 2, '5.000000', 0, 1000, null, 0]];
        foreach ($rows as $i => [$item, $q, $price, $disc, $total, $iec, $rate]) {
            OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => $i, 'item' => $item, 'quantity' => $q, 'unit' => 'UN',
                'unit_price' => $price, 'discount_pct' => $disc, 'line_total_cents' => $total, 'gross_total_cents' => $total, 'iec_cents' => $iec, 'vat_rate' => $rate,
                'article_id' => $this->b->id, 'link_state' => 'ligada', 'link_method' => 'mapa']);
        }

        return $inv->fresh();
    }

    /** Uma fatura anterior do fornecedor, já no PingWin, com o IEC numa linha à parte (artigo próprio). */
    private function previousInvoiceWithIec(): PingwinCatalogItem
    {
        $iec = PingwinCatalogItem::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'PIEC', 'code' => '999', 'description' => 'IEC',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => null, 'purchaseunit' => 'Unidade']);
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->yuko->id, 'docheader_id' => 'OLD1', 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor',
            'document' => 'VFT BOVFT/1', 'entity_pingwin_id' => 'SUPX', 'doc_date' => '2026-10-01', 'total_cents' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, 'PB', 'BEBIDA', '11001', 0.87, 1357], [2, 'PIEC', 'IEC', '11001', 4.77, 477]] as [$n, $prod, $desc, $unit, $price, $total]) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->yuko->id, 'docheader_id' => 'OLD1', 'line_number' => $n, 'product_pingwin_id' => $prod,
                'description' => $desc, 'qnt' => 1, 'unit_id' => $unit, 'price' => $price, 'total_cents' => $total, 'tax_description' => 'Normal', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $iec;
    }

    public function test_iec_goes_in_its_own_line_like_the_previous_invoices_of_the_supplier_and_the_total_matches(): void
    {
        $this->previousInvoiceWithIec();
        $inv = $this->iecInvoice();
        $p = $this->svc()->preview($inv);
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values()));
        $planned = $p['planned']['lines'];
        $this->assertCount(9, $planned);
        $last = end($planned);
        $this->assertSame(['iec', 'IEC', 1.0, 2.87, 0.0, 23, 287, 66], [$last['kind'], $last['article']['description'], $last['qnt'], $last['price'], $last['discount_pct'], $last['vat_rate'], $last['net_cents'], $last['tax_cents']]);
        // As bebidas vão sem o IEC (quantidade × preço × (1 − desconto)).
        $this->assertSame([1357, 1310, 4072, 2621], array_values(array_map(fn ($l) => $l['net_cents'], array_filter($planned, fn ($l) => $l['kind'] === 'linha' && $l['vat_rate'] === 23))));
        $this->assertSame([15366, 15366, 0], [$p['planned']['total_cents'], $p['planned']['target_cents'], $p['estimate']['adjustment_cents']]);
        $this->assertSame('VFT BOVFT/1', $p['planned']['iec_from']);

        // O que segue para o PingWin: as 8 linhas e a do IEC (artigo e unidade da fatura anterior).
        $details = [];
        foreach ($planned as $i => $l) {
            $details[] = ['line_number' => $i + 1, 'product_id' => $l['kind'] === 'iec' ? 'PIEC' : 'PB', 'total' => $l['net_cents'] / 100, 'tax_value' => $l['tax_cents'] / 100];
        }
        $this->okLaunch('153.66', ['details' => $details]);
        $this->assertSame('ok', $this->launchNow($inv)->status);
        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertCount(9, $doc['lines']);
        $this->assertSame(['PIEC', '11001', '1.000000', '2.870000', '0', 23], [$doc['lines'][8]['product_id'], $doc['lines'][8]['unit_id'], $doc['lines'][8]['qnt'],
            $doc['lines'][8]['price'], $doc['lines'][8]['discount1'], $doc['lines'][8]['vat_rate']]);
        $this->assertSame(['20.880000', '35.01', 23], [$doc['lines'][0]['price'], $doc['lines'][0]['discount1'], $doc['lines'][0]['vat_rate']]);
        $this->assertSame('153.66', $doc['target_total']);
    }

    public function test_iec_without_a_previous_iec_line_of_the_supplier_does_not_launch(): void
    {
        $inv = $this->iecInvoice();
        $g = collect($this->svc()->preview($inv)['guards'])->firstWhere('key', 'iec');
        $this->assertFalse($g['ok']);
        $this->assertSame('As linhas têm IEC numa coluna à parte, mas nenhuma fatura deste fornecedor no PingWin tem a linha do IEC para seguir. Lance esta fatura à mão.', $g['message']);
        // Sem IEC nas linhas, a guarda nem aparece.
        $this->assertNull(collect($this->svc()->preview($this->invoice())['guards'])->firstWhere('key', 'iec'));
    }

    public function test_guard_matrix_each_one_blocks(): void
    {
        $cases = [
            'tipo'        => [['doc_type' => 'ND'], []],
            'qr'          => [['buyer_nif' => '500000001'], []],
            'conferencia' => [['check_status' => 'nao_confere'], []],
            'nao_lancada' => [['link_status' => 'lancada'], []],
            'artigos'     => [[], ['link_state' => 'sugerida', 'article_id' => null]],
            'unidades'    => [[], ['launch_unit_id' => null]],            // "CX" não é uma unidade do artigo B (só UN e CX 6)
            'iva'         => [[], ['vat_rate' => null]],
            'valores'     => [[], ['quantity' => 0]],
            'acerto'      => [['qr_data' => ['fields' => ['O' => '10.48']]], []],
        ];
        foreach ($cases as $key => [$inv, $l2]) {
            $this->assertContains($key, $this->failedGuards($this->invoice($inv, $l2)), "a guarda {$key} devia bloquear");
        }
        $this->supplier->update(['is_active' => false]);
        $this->assertContains('fornecedor', $this->failedGuards($this->invoice()));
    }

    public function test_the_type_guard_shows_the_real_type_with_the_right_message(): void
    {
        $cases = [
            'FT' => [true, 'Fatura (FT)', null],
            'NC' => [true, 'Nota de crédito (NC)', null],
            'FR' => [true, 'Fatura-recibo (FR)', null],
            'FS' => [true, 'Fatura simplificada (FS)', null],
            'ND' => [false, 'Nota de débito (ND)', 'As notas de débito ainda se lançam à mão no PingWin. Disponível em breve.'],
            'VD' => [false, 'Venda a dinheiro (VD)', 'Por agora, lançar à mão no PingWin.'],
            ''   => [false, 'Sem tipo', 'O QR não se leu e a leitura não identificou o tipo.'],
        ];
        foreach ($cases as $t => [$ok, $label, $message]) {
            $g = collect($this->svc()->preview($this->invoice(['doc_type' => $t === '' ? null : $t]))['guards'])->firstWhere('key', 'tipo');
            $this->assertSame([$ok, $label, $message], [$g['ok'], $g['label'], $g['message']], "tipo {$t}");
        }
    }

    public function test_a_debit_note_is_never_launched_and_nothing_is_written_to_pingwin(): void
    {
        Bus::fake([LaunchPingwinDocumentJob::class]);
        foreach (['ND'] as $t) {
            $inv = $this->invoice(['doc_type' => $t]);
            $p = $this->svc()->preview($inv);
            $this->assertFalse($p['can_launch']);
            $this->assertFalse($p['close']['can_close']);
            $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch", ['accept_check_diff' => true])
                ->assertStatus(422)->assertJsonPath('errors.code', 'guardas')->assertJsonPath('errors.failed.0.key', 'tipo');
        }
        $this->assertSame(0, PingwinDocumentWrite::count());
        Bus::assertNotDispatched(LaunchPingwinDocumentJob::class);
    }

    // ───────────────────────────────────────────────────────────── notas de crédito (1205)

    /** Uma nota de crédito pronta a lançar: o mesmo que a fatura de teste, com o tipo NC. */
    private function creditNote(array $o = []): OcrInvoice
    {
        return $this->invoice(array_merge(['doc_type' => 'NC', 'number' => 'NC CX/0196'], $o));
    }

    public function test_a_credit_note_launches_as_1205_in_draft_with_the_note_date_and_reference(): void
    {
        $inv = $this->creditNote();
        $p = $this->svc()->preview($inv);
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values()));
        $this->assertSame('Nota de crédito (NC)', collect($p['guards'])->firstWhere('key', 'tipo')['label']);
        $this->assertSame('Total das linhas igual ao total da nota', collect($p['guards'])->firstWhere('key', 'acerto')['label']);

        $this->okLaunch('10.38', ['header' => ['docconfig_id' => '1205', 'doc_prefix' => 'VNC BOVNC', 'doc_number' => 89, 'doc_date' => '20261005T00:00:00',
            'docreference_number' => 'NC CX/0196']]);
        $this->launchResult['result'] = array_replace($this->launchResult['result'], ['docconfig_id' => '1205', 'document' => 'VNC BOVNC/89',
            'header_before_save' => ['id' => 'DOC1', 'doc_prefix' => 'VNC BOVNC', 'doc_number' => 89, 'store_code' => 'BO']]);
        $this->launchResult['reread']['header']['doc_prefix'] = 'VNC BOVNC';
        $this->launchResult['reread']['header']['doc_number'] = 89;
        $w = $this->launchNow($inv);
        $this->assertSame('ok', $w->status, (string) $w->error);

        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertSame(['1205', '8001', '', 'NC CX/0196', '20261005T00:00:00', '20261005T00:00:00', '0.00', '10.38'],
            [$doc['docconfig_id'], $doc['docstatus_id'], $doc['docreference_id'], $doc['docreference_number'], $doc['docreference_date'], $doc['doc_date'],
                $doc['max_adjustment'], $doc['target_total']]);
        // Valores positivos, como na nota (o sinal vem do tipo).
        $this->assertSame(['1.415000', '2.000000'], array_column($doc['lines'], 'price'));
        $this->assertSame(['2.000000', '3.000000'], array_column($doc['lines'], 'qnt'));
        // O espelho fica com a nota de crédito em rascunho (Aberto): não mexe no stock.
        $mirror = PingwinSupplierDocument::where('docheader_id', 'DOC1')->first();
        $this->assertSame(['1205', 'Nota de crédito de fornecedor', '8001', 'VNC BOVNC/89'], [$mirror->docconfig_id, $mirror->doctype, $mirror->docstatus_id, $mirror->document]);

        // O fecho é o botão de sempre (com as unidades confirmadas no Guião), com a releitura da 1205.
        $this->confirmUnits();
        $close = $this->svc()->requestStatus($inv->fresh(), PingwinDocumentWrite::CLOSE, $this->user->id);
        $this->assertSame('ok', $this->svc()->execute($close->id)->status);
        $this->assertSame(['status', 'DOC1', '8002', '1205'], collect($this->calls)->firstWhere(0, 'status'));
    }

    public function test_a_credit_note_with_the_wrong_date_after_save_is_not_confirmed(): void
    {
        $this->okLaunch('10.38', ['header' => ['docconfig_id' => '1205', 'doc_date' => '20261021T00:00:00', 'docreference_number' => 'NC CX/0196']]);
        $w = $this->launchNow($this->creditNote());
        $this->assertSame('erro_confirmacao', $w->status);
        $this->assertStringContainsString('a data do documento não é a da nota (2026-10-21)', (string) $w->error);
    }

    public function test_a_credit_note_needs_the_exact_total_without_adjustment(): void
    {
        // Uma fatura aceita até 0,05 € de acerto; uma nota de crédito não.
        $o = ['qr_data' => ['fields' => ['O' => '10.40'], 'by_rate' => [['rate' => 6, 'base_cents' => 283, 'vat_cents' => 17], ['rate' => 23, 'base_cents' => 600, 'vat_cents' => 138]]]];
        $this->assertNotContains('acerto', $this->failedGuards($this->invoice($o)));
        $this->assertContains('acerto', $this->failedGuards($this->creditNote($o)));
    }

    public function test_a_credit_note_already_launched_by_hand_is_found_linked_and_not_created(): void
    {
        // À mão: sem referência e com a data do dia; a de 45 € anulada não conta, nem uma fatura com o mesmo total.
        $this->liveDocs = [
            ['id' => 'NC88', 'docconfig_id' => '1205', 'document' => 'VNC BOVNC/88', 'entity_id' => 'SUPX', 'total' => 10.38, 'doc_date' => '20261007T00:00:00', 'docstatus_id' => '8002', 'docreference_number' => ''],
            ['id' => 'NC89', 'docconfig_id' => '1205', 'document' => 'VNC BOVNC/89', 'entity_id' => 'SUPX', 'total' => 10.38, 'doc_date' => '20261005T00:00:00', 'docstatus_id' => '8003', 'docreference_number' => ''],
            ['id' => 'FT1', 'docconfig_id' => '1209', 'document' => 'VFT BOVFT/1', 'entity_id' => 'SUPX', 'total' => 10.38, 'doc_date' => '20261005T00:00:00', 'docstatus_id' => '8002', 'docreference_number' => ''],
        ];
        $inv = $this->creditNote();
        $w = $this->launchNow($inv);
        $this->assertSame('erro', $w->status);
        $this->assertSame('Já está lançada no PingWin (VNC BOVNC/88). Ficou ligada à nota; nada foi criado.', $w->error);
        $this->assertNull(collect($this->calls)->firstWhere(0, 'launch'), 'nada foi criado no PingWin');
        $inv->refresh();
        $this->assertSame([OcrPingwinLinkService::LAUNCHED, ['NC88']], [$inv->link_status, $inv->pingwinLinks()->pluck('docheader_id')->all()]);
        $g = collect($this->svc()->preview($inv)['guards'])->firstWhere('key', 'nao_lancada');
        $this->assertSame([false, 'Já está lançada no PingWin (VNC BOVNC/88).'], [$g['ok'], $g['message']]);
    }

    public function test_a_credit_note_with_the_same_reference_is_found_whatever_the_total(): void
    {
        $this->liveDocs = [['id' => 'NC90', 'docconfig_id' => '1205', 'document' => 'VNC BOVNC/90', 'entity_id' => 'SUPX', 'total' => 99.0,
            'doc_date' => '20261020T00:00:00', 'docstatus_id' => '8001', 'docreference_number' => 'NC CX/0196']];
        $w = $this->launchNow($this->creditNote());
        $this->assertSame('Já está lançada no PingWin (VNC BOVNC/90). Ficou ligada à nota; nada foi criado.', $w->error);
    }

    // ───────────────────────────────────────────────────────────── faturas-recibo (FR C)

    public function test_a_fr_launches_as_fr_c_in_draft_with_the_invoice_date_and_reference(): void
    {
        $inv = $this->invoice(['doc_type' => 'FR', 'number' => 'FR FR.JM26/23178', 'issue_date' => '2026-10-20']);
        $this->assertTrue($this->svc()->preview($inv)['can_launch']);
        $this->okLaunch('10.38', ['header' => ['docconfig_id' => OcrPingwinLinkService::FRC, 'doc_prefix' => ' BO', 'doc_number' => 138,
            'doc_date' => '20261020T00:00:00', 'docreference_number' => 'FR FR.JM26/23178']]);
        $this->launchResult['result'] = array_replace($this->launchResult['result'], ['docconfig_id' => OcrPingwinLinkService::FRC, 'document' => ' BO/138',
            'header_before_save' => ['id' => 'DOC1', 'doc_prefix' => ' BO', 'doc_number' => 138, 'store_code' => 'BO']]);
        $w = $this->launchNow($inv);
        $this->assertSame('ok', $w->status, (string) $w->error);

        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertSame([OcrPingwinLinkService::FRC, '8001', OcrInvoiceLaunchService::DOCREF_ID, 'FR FR.JM26/23178', '20261020T00:00:00', '20261020T00:00:00', '0.05'],
            [$doc['docconfig_id'], $doc['docstatus_id'], $doc['docreference_id'], $doc['docreference_number'], $doc['docreference_date'], $doc['doc_date'], $doc['max_adjustment']]);
        $this->assertSame(['1.415000', '2.000000'], array_column($doc['lines'], 'price'));
        $mirror = PingwinSupplierDocument::where('docheader_id', 'DOC1')->first();
        $this->assertSame([OcrPingwinLinkService::FRC, 'Fatura-recibo compra', '8001'], [$mirror->docconfig_id, $mirror->doctype, $mirror->docstatus_id]);

        $this->confirmUnits();
        $close = $this->svc()->requestStatus($inv->fresh(), PingwinDocumentWrite::CLOSE, $this->user->id);
        $this->assertSame('ok', $this->svc()->execute($close->id)->status);
        $this->assertSame(['status', 'DOC1', '8002', OcrPingwinLinkService::FRC], collect($this->calls)->firstWhere(0, 'status'));
    }

    public function test_a_simplified_invoice_launches_as_fr_c(): void
    {
        // O número do talão tem 27 carateres: o Documento de referência (25) fica sem o prefixo do tipo, inteiro.
        $inv = $this->invoice(['doc_type' => 'FS', 'number' => 'FS 0489064251229I824/052982', 'issue_date' => '2026-10-15']);
        $p = $this->svc()->preview($inv);
        $this->assertSame('Fatura simplificada (FS)', collect($p['guards'])->firstWhere('key', 'tipo')['label']);
        $this->assertSame(['0489064251229I824/052982', false], [$p['docreference']['number'], $p['docreference']['truncated']]);
        $this->okLaunch('10.38', ['header' => ['docconfig_id' => OcrPingwinLinkService::FRC, 'doc_date' => '20261015T00:00:00', 'docreference_number' => '0489064251229I824/052982']]);
        $w = $this->launchNow($inv);
        $this->assertSame('ok', $w->status, (string) $w->error);
        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertSame([OcrPingwinLinkService::FRC, '8001', OcrInvoiceLaunchService::DOCREF_ID, '0489064251229I824/052982', '20261015T00:00:00'],
            [$doc['docconfig_id'], $doc['docstatus_id'], $doc['docreference_id'], $doc['docreference_number'], $doc['doc_date']]);
        $this->assertSame('Fatura-recibo compra', PingwinSupplierDocument::where('docheader_id', 'DOC1')->value('doctype'));
        // Só se tira o prefixo quando não cabe; um número curto fica como está.
        $this->assertSame(['FT A/77', 'FT 2026/00000000000000000', 'FAC 02002202601/024366'], [OcrInvoiceLaunchService::docReference('FT A/77'),
            OcrInvoiceLaunchService::docReference('FT 2026/00000000000000000'), OcrInvoiceLaunchService::docReference('FAC 02002202601/024366')]);
    }

    public function test_a_fr_already_launched_by_hand_is_found_and_linked(): void
    {
        // À mão: a FR C ficou com a data do dia (23/10, a fatura é de 20/10) e sem referência; a FR C anulada não conta.
        $this->liveDocs = [
            ['id' => 'FR138', 'docconfig_id' => OcrPingwinLinkService::FRC, 'document' => 'BO/138', 'entity_id' => 'SUPX', 'total' => 10.38, 'doc_date' => '20261023T00:00:00', 'docstatus_id' => '8002', 'docreference_number' => ''],
            ['id' => 'FR139', 'docconfig_id' => OcrPingwinLinkService::FRC, 'document' => 'BO/139', 'entity_id' => 'SUPX', 'total' => 10.38, 'doc_date' => '20261020T00:00:00', 'docstatus_id' => '8003', 'docreference_number' => ''],
        ];
        $inv = $this->invoice(['doc_type' => 'FR', 'number' => 'FR FR.JM26/23178', 'issue_date' => '2026-10-20']);
        $w = $this->launchNow($inv);
        $this->assertSame('Já está lançada no PingWin (BO/138). Ficou ligada à fatura-recibo; nada foi criado.', $w->error);
        $this->assertNull(collect($this->calls)->firstWhere(0, 'launch'));
        $this->assertSame(['FR138'], $inv->fresh()->pingwinLinks()->pluck('docheader_id')->all());
        $g = collect($this->svc()->preview($inv->fresh())['guards'])->firstWhere('key', 'nao_lancada');
        $this->assertSame('Já está lançada no PingWin (BO/138).', $g['message']);
    }

    public function test_a_fr_launched_as_an_invoice_1209_with_the_same_reference_is_found(): void
    {
        $this->liveDocs = [['id' => 'FT55', 'docconfig_id' => '1209', 'document' => 'VFT BOVFT/55', 'entity_id' => 'SUPX', 'total' => 99.0,
            'doc_date' => '20261001T00:00:00', 'docstatus_id' => '8002', 'docreference_number' => 'FR FR.JM26/23178']];
        $w = $this->launchNow($this->invoice(['doc_type' => 'FR', 'number' => 'FR FR.JM26/23178', 'issue_date' => '2026-10-20']));
        $this->assertSame('Já está lançada no PingWin (VFT BOVFT/55). Ficou ligada à fatura-recibo; nada foi criado.', $w->error);
    }

    // ───────────────────────────────────────────────────────────── sem QR legível

    /** Fatura sem QR: o quadro do IVA lido igual às linhas (6 %: 2,83 + 0,17; 23 %: 6,00 + 1,38). */
    private function withoutQr(array $o = []): OcrInvoice
    {
        return $this->invoice(array_merge(['qr_ok' => false, 'qr_data' => null, 'check_status' => 'sem_qr', 'vat_table' => [
            ['code' => 'A', 'rate' => 6, 'base_cents' => 283, 'vat_cents' => 17], ['code' => 'B', 'rate' => 23, 'base_cents' => 600, 'vat_cents' => 138]]], $o));
    }

    public function test_without_qr_it_launches_only_with_the_buyer_the_read_vat_and_the_paper_check(): void
    {
        $inv = $this->withoutQr();
        $p = $this->svc()->preview($inv);
        $this->assertFalse($p['can_launch']);
        $g = collect($p['guards'])->keyBy('key');
        $this->assertSame('Sem QR: adquirente, IVA lido e totais conferidos com o papel', $g['qr']['label']);
        $this->assertSame('Sem QR legível, falta: marcar "Conferi os totais com o papel".', $g['qr']['message']);
        $this->assertTrue($g['conferencia']['ok']);
        $this->assertSame('Linhas a conferir com o quadro do IVA lido', $g['conferencia']['label']);
        $this->assertSame(0, $p['estimate']['adjustment_cents'], 'o total vem do sumário lido');

        // Marcar "Conferi os totais com o papel" guarda quem e quando; com as três condições, lança-se.
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch/paper-check", ['checked' => true])
            ->assertOk()->assertJsonPath('data.can_launch', true)->assertJsonPath('data.paper_check.by_name', $this->user->name);
        $inv->refresh();
        $this->assertSame($this->user->id, (int) $inv->paper_checked_by);
        $this->assertNotNull($inv->paper_checked_at);
        $this->okLaunch();
        $this->assertSame('ok', $this->launchNow($inv)->status);

        // Desmarcar volta a bloquear.
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch/paper-check", ['checked' => false])->assertOk();
        $this->assertNull($inv->fresh()->paper_checked_at);
    }

    public function test_without_qr_the_message_says_what_is_missing(): void
    {
        $inv = $this->withoutQr(['buyer_nif' => '500000001', 'vat_table' => [['code' => 'A', 'rate' => 6, 'base_cents' => 300, 'vat_cents' => 18],
            ['code' => 'B', 'rate' => 23, 'base_cents' => 600, 'vat_cents' => 138]], 'paper_checked_by' => $this->user->id, 'paper_checked_at' => now()]);
        $g = collect($this->svc()->preview($inv)['guards'])->keyBy('key');
        $this->assertSame('Sem QR legível, falta: o NIF do adquirente lido ser o da empresa (lido: 500000001); o IVA por taxa bater com o quadro do IVA lido.', $g['qr']['message']);
        $this->assertFalse($g['conferencia']['ok']);
        // Com QR, a conferência com o papel não se usa.
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$this->invoice()->id}/pingwin-launch/paper-check", ['checked' => true])
            ->assertStatus(422);
    }

    // ───────────────────────────────────────────── decimais à unidade e artigos de agrupamento

    /**
     * O caso real (números da fatura, sem nomes): 3 linhas em kg ligadas a um artigo à unidade e 1 linha
     * de 174,10 €, tudo a 23 %; base 332,65 €, total 409,16 €.
     */
    private function wholeUnitInvoice(): array
    {
        PingwinUnit::where('company_id', $this->yuko->id)->where('pingwin_id', '11001')->update(['raw' => ['frac_unit' => 0]]);
        PingwinUnit::where('company_id', $this->yuko->id)->where('pingwin_id', '11002')->update(['raw' => ['frac_unit' => 1]]);
        $staff = PingwinCatalogItem::create(['company_id' => $this->yuko->id, 'pingwin_id' => 'PSTAFF', 'code' => '908', 'description' => 'COMIDA STAFF 23%',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001', 'purchaseunit' => 'Unidade']);
        $inv = $this->invoice(['qr_data' => ['fields' => ['O' => '409.16'], 'by_rate' => [['rate' => 23, 'base_cents' => 33265, 'vat_cents' => 7651]]]]);
        $inv->lines()->delete();
        foreach ([[8.09, '9.190000', 7437], [8.09, '2.490000', 2015], [8.665, '7.390000', 6403]] as $i => [$q, $price, $total]) {
            OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => $i, 'item' => "Carne {$i}", 'quantity' => $q, 'unit' => 'KG',
                'unit_price' => $price, 'line_total_cents' => $total, 'gross_total_cents' => $total, 'vat_rate' => 23, 'article_id' => $staff->id,
                'link_state' => 'ligada', 'link_method' => 'mapa', 'launch_unit_id' => '11001']);
        }
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 3, 'item' => 'Outro', 'quantity' => 1, 'unit' => 'UN',
            'unit_price' => '174.100000', 'line_total_cents' => 17410, 'gross_total_cents' => 17410, 'vat_rate' => 23, 'article_id' => $this->b->id,
            'link_state' => 'ligada', 'link_method' => 'mapa']);

        return [$inv->fresh(), $staff];
    }

    public function test_decimals_on_an_article_by_the_unit_block_the_launch_and_the_estimate_shows_the_rounding(): void
    {
        [$inv] = $this->wholeUnitInvoice();
        $p = $this->svc()->preview($inv);
        $g = collect($p['guards'])->keyBy('key');
        $this->assertFalse($g['unidades']['ok']);
        $this->assertSame('O artigo COMIDA STAFF 23% é à unidade e a linha tem 8,09 kg: o PingWin arredonda para 9. Confirme a unidade no Guião de unidades ou marque o artigo como artigo de agrupamento.',
            explode(' O artigo', $g['unidades']['message'])[0]);
        $this->assertStringContainsString('a linha tem 8,665 kg: o PingWin arredonda para 9.', $g['unidades']['message']);
        // O total estimado já simula o arredondamento: 9 × 9,19 + 9 × 2,49 + 9 × 7,39 = 171,63 € em vez de 158,55 €.
        $this->assertSame([8271, 2241, 6651, 17410], array_column(array_slice($p['planned']['lines'], 0, 4), 'net_cents'));
        $this->assertSame([42524, 40916, -1608], [$p['estimate']['computed_cents'], $p['estimate']['target_cents'], $p['estimate']['adjustment_cents']]);
        $this->assertFalse($g['acerto']['ok']);
        $this->assertFalse($p['can_launch']);
    }

    public function test_a_grouping_article_joins_its_lines_into_one_and_the_total_matches(): void
    {
        [$inv, $staff] = $this->wholeUnitInvoice();
        // Cadastros › Artigos: "Artigo de agrupamento" (guarda quem e quando).
        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/articles/{$staff->id}/grouping", ['grouping' => true])
            ->assertOk()->assertJsonPath('data.grouping', true)->assertJsonPath('data.changed_by_name', $this->user->name);
        $this->assertSame($this->user->id, (int) ArticleGrouping::first()->changed_by);

        $p = $this->svc()->preview($inv->fresh());
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values()));
        $group = collect($p['planned']['lines'])->firstWhere('kind', 'grupo');
        $this->assertSame([3, 1.0, 158.55, 15855, 23, 'COMIDA STAFF 23%'], [$group['lines'], $group['qnt'], $group['price'], $group['net_cents'], $group['vat_rate'], $group['article']['description']]);
        $this->assertSame([40916, 40916, 0], [$p['estimate']['computed_cents'], $p['estimate']['target_cents'], $p['estimate']['adjustment_cents']]);
        $this->assertSame('confere', $inv->fresh()->check_status, 'a conferência com o QR continua a usar as linhas lidas');
        // As linhas juntas não precisam das unidades confirmadas para fechar (só o outro artigo).
        $this->assertStringNotContainsString('COMIDA STAFF', json_encode($p['close']['blockers'], JSON_UNESCAPED_UNICODE));

        $this->okLaunch('409.16', ['header' => ['total_products' => 332.65, 'total_tax' => 76.51],
            'details' => [['line_number' => 1, 'product_id' => 'PSTAFF', 'total' => 158.55, 'tax_value' => 36.47, 'qnt' => 1, 'price' => 158.55],
                ['line_number' => 2, 'product_id' => 'PB', 'total' => 174.10, 'tax_value' => 40.04, 'qnt' => 1, 'price' => 174.10]]]);
        $this->assertSame('ok', $this->launchNow($inv->fresh())->status);
        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertCount(2, $doc['lines']);
        $this->assertSame(['PSTAFF', '11001', '1.000000', '158.550000', '0', 23], [$doc['lines'][0]['product_id'], $doc['lines'][0]['unit_id'], $doc['lines'][0]['qnt'],
            $doc['lines'][0]['price'], $doc['lines'][0]['discount1'], $doc['lines'][0]['vat_rate']]);
        $this->assertSame('409.16', $doc['target_total']);
    }

    public function test_grouping_lines_with_different_vat_rates_block(): void
    {
        [$inv, $staff] = $this->wholeUnitInvoice();
        ArticleGrouping::create(['company_id' => $this->yuko->id, 'product_pingwin_id' => 'PSTAFF', 'is_grouping' => true]);
        $inv->lines()->where('position', 2)->update(['vat_rate' => 13]);
        $g = collect($this->svc()->preview($inv->fresh())['guards'])->firstWhere('key', 'agrupamento');
        $this->assertSame([false, 'As linhas do artigo COMIDA STAFF 23% têm taxas de IVA diferentes; crie um artigo por taxa.'], [$g['ok'], $g['message']]);
    }

    public function test_a_unit_that_accepts_decimals_and_whole_quantities_do_not_change(): void
    {
        PingwinUnit::where('company_id', $this->yuko->id)->where('pingwin_id', '11001')->update(['raw' => ['frac_unit' => 0]]);
        PingwinUnit::where('company_id', $this->yuko->id)->where('pingwin_id', '11002')->update(['raw' => ['frac_unit' => 1]]);
        // A linha 1 em KG (o artigo A é ao quilo) com 8,09; a linha 2 com 3 unidades.
        $inv = $this->invoice([], []);
        $inv->lines()->where('position', 0)->update(['quantity' => 8.09]);
        $p = $this->svc()->preview($inv->fresh());
        $this->assertTrue(collect($p['guards'])->firstWhere('key', 'unidades')['ok']);
        $rows = collect($p['planned']['lines'])->where('kind', 'linha')->values();
        $this->assertSame([8.09, 3.0], [$rows[0]['qnt'], $rows[1]['qnt']]);
        $this->assertSame([283, 600], [$rows[0]['net_cents'], $rows[1]['net_cents']]);
        $this->assertSame([null, null], [$rows[0]['rounded_from'], $rows[1]['rounded_from']]);
        $this->assertSame(0, $p['estimate']['adjustment_cents']);
    }

    public function test_check_difference_can_be_accepted_and_is_recorded(): void
    {
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $inv = $this->invoice(['check_status' => 'nao_confere']);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch")
            ->assertStatus(422)->assertJsonPath('errors.code', 'guardas')->assertJsonPath('errors.failed.0.key', 'conferencia');
        // Como o frontend envia (multipart: booleanos em texto): "false" não aceita, "true" aceita.
        $this->actingAs($this->user, 'sanctum')->post("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch", ['accept_check_diff' => 'false'], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->post("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch", ['accept_check_diff' => 'true'], ['Accept' => 'application/json'])
            ->assertStatus(202);
        $inv->refresh();
        $this->assertSame($this->user->id, (int) $inv->check_accepted_by);
        $this->assertTrue(PingwinDocumentWrite::first()->payload['check_accepted']);
    }

    public function test_unit_choice_endpoint(): void
    {
        $inv = $this->invoice([], ['launch_unit_id' => null]);
        $line = $inv->lines()->where('position', 1)->first();
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/lines/{$line->id}/launch-unit";
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['unit_id' => '11002'])->assertStatus(422);   // KG não é unidade do B
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['unit_id' => 'CX6', 'quantity' => 0.5])->assertOk()
            ->assertJsonPath('data.can_launch', true)->assertJsonPath('data.lines.1.unit_id', 'CX6');
        $this->assertSame('0.500000', (string) $line->fresh()->quantity);
    }

    // ───────────────────────────────────────────────────────────── idempotência e estados

    public function test_launch_request_is_idempotent_and_locked(): void
    {
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $inv = $this->invoice();
        $url = "/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch";
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(202)->assertJsonPath('data.write.status', 'pendente');
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(422)->assertJsonFragment(['key' => 'escrita']);
        $this->assertSame(1, PingwinDocumentWrite::count());
        Bus::assertDispatchedTimes(LaunchPingwinDocumentJob::class, 1);

        $w = PingwinDocumentWrite::first();
        $doc = $w->payload['doc'];
        $this->assertSame(['8001', 'SUPX', '10.38', '0.05', '1649601156562', 'FT TESTE/1', '20261005T00:00:00'],
            [$doc['docstatus_id'], $doc['entity_id'], $doc['target_total'], $doc['max_adjustment'], $doc['docreference_id'], $doc['docreference_number'], $doc['docreference_date']]);
        $this->assertSame(['PA', '11002', '2.000000', '1.415000', '0', 6], [$doc['lines'][0]['product_id'], $doc['lines'][0]['unit_id'], $doc['lines'][0]['qnt'], $doc['lines'][0]['price'], $doc['lines'][0]['discount1'], $doc['lines'][0]['vat_rate']]);
        $this->assertSame(['PB', 'CX6', '3.000000'], [$doc['lines'][1]['product_id'], $doc['lines'][1]['unit_id'], $doc['lines'][1]['qnt']]);
    }

    public function test_launch_ok_confirms_by_reread_creates_f3_link_and_mirror(): void
    {
        $this->okLaunch();
        $inv = $this->invoice();
        $w = $this->launchNow($inv);

        $this->assertSame('ok', $w->status, (string) $w->error);
        $this->assertSame(['DOC1', 'VFT BOVFT/1077'], [$w->docheader_id, $w->document]);
        $this->assertSame(['find', 'docs', 'launch'], array_column($this->calls, 0));
        $link = OcrInvoicePingwinLink::where('ocr_invoice_id', $inv->id)->first();
        $this->assertSame(['DOC1', 'xplendor'], [$link->docheader_id, $link->method]);
        $this->assertNotNull($link->confirmed_at);
        $this->assertSame('lancada', $inv->fresh()->link_status);
        $d = PingwinSupplierDocument::where('docheader_id', 'DOC1')->first();
        $this->assertSame(['8001', 'Aberto', 1038, 'FT TESTE/1', 'ok'], [$d->docstatus_id, $d->docstatus_description, $d->total_cents, $d->docreference_number, $d->lines_status]);
        $this->assertSame(2, DB::table('pingwin_supplier_document_lines')->where('docheader_id', 'DOC1')->count());

        // Lista: "Rascunho"; um segundo lançamento fica bloqueado.
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices")
            ->assertJsonPath('data.invoices.data.0.pingwin_doc_status', '8001');
        $this->assertContains('escrita', $this->failedGuards($inv));
        $this->assertContains('nao_lancada', $this->failedGuards($inv));
    }

    public function test_live_supplier_not_active_aborts_before_launch(): void
    {
        $this->okLaunch();
        $this->liveActive = [];
        $w = $this->launchNow($this->invoice());
        $this->assertSame('erro', $w->status);
        $this->assertStringContainsString('pesquisa viva', $w->error);
        $this->assertNotContains('launch', array_column($this->calls, 0));
    }

    public function test_live_list_already_has_it_aborts_and_links(): void
    {
        $this->okLaunch();
        $this->liveDocs = [['id' => 'OLD9', 'docconfig_id' => '1209', 'document' => 'VFT 0VFT/9', 'entity_id' => 'SUPX', 'total' => 10.38,
            'doc_date' => '20261006T00:00:00', 'docstatus_id' => '8002', 'docreference_number' => '']];
        $inv = $this->invoice();
        $w = $this->launchNow($inv);
        $this->assertSame('erro', $w->status);
        $this->assertStringContainsString('Já lançada no PingWin: VFT 0VFT/9', $w->error);
        $this->assertNotContains('launch', array_column($this->calls, 0));
        $this->assertSame('OLD9', OcrInvoicePingwinLink::where('ocr_invoice_id', $inv->id)->value('docheader_id'));
    }

    public function test_adjustment_above_max_is_error_without_document(): void
    {
        $this->launchResult = ['result' => ['ok' => false, 'saved' => false, 'reason' => 'acerto', 'adjustment' => '0.43',
            'error' => 'O total no PingWin (9.95) difere do da fatura (10.38) em 0.43 € — acima do acerto máximo (0.05 €). Nada foi gravado.',
            'lines' => [['total' => 2.40, 'tax_description' => 'Reduzida'], ['total' => 6.0, 'tax_description' => 'Normal']]], 'reread' => null];
        $inv = $this->invoice();
        $w = $this->launchNow($inv);
        $this->assertSame('erro', $w->status);
        $this->assertStringContainsString('IVA 6%: PingWin 2.40 € vs QR 2.83 €', $w->error);
        $this->assertSame(0, OcrInvoicePingwinLink::count());
        // "Tentar de novo" é possível (nada foi gravado).
        $this->assertNotContains('escrita', $this->failedGuards($inv));
    }

    public function test_unknown_save_or_reread_mismatch_is_confirmation_error_and_blocks(): void
    {
        $this->launchResult = ['result' => ['ok' => false, 'saved' => 'unknown', 'error' => 'SAVE sem resposta'], 'reread' => null];
        $inv = $this->invoice();
        $this->assertSame('erro_confirmacao', $this->launchNow($inv)->status);
        $this->assertContains('escrita', $this->failedGuards($inv)); // nunca repetir sozinho

        PingwinDocumentWrite::query()->delete();
        $this->okLaunch('10.99');                                    // gravou, mas o total relido não bate
        $w = $this->launchNow($this->invoice());
        $this->assertSame('erro_confirmacao', $w->status);
        $this->assertStringContainsString('o total', $w->error);
        $this->assertSame('DOC1', $w->docheader_id);
    }

    // ───────────────────────────────────────────────────────────── fechar e anular

    public function test_close_then_void_and_launch_again(): void
    {
        $this->confirmUnits();
        $this->okLaunch();
        $inv = $this->invoice();
        $this->launchNow($inv);

        $close = $this->svc()->requestStatus($inv, 'close', $this->user->id);
        $this->assertSame('ok', $this->svc()->execute($close->id)->status);
        $this->assertSame(['status', 'DOC1', '8002'], array_slice(end($this->calls), 0, 3));
        $this->assertSame('8002', PingwinSupplierDocument::where('docheader_id', 'DOC1')->value('docstatus_id'));
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices")
            ->assertJsonPath('data.invoices.data.0.pingwin_doc_status', '8002');
        $this->expectExceptionMessage('Só se fecha um documento em rascunho.');
        try {
            $this->svc()->requestStatus($inv, 'close', $this->user->id);
        } finally {
            $void = $this->svc()->requestStatus($inv, 'void', $this->user->id);
            $this->assertSame('ok', $this->svc()->execute($void->id)->status);
            $inv->refresh();
            $this->assertSame('8003', PingwinSupplierDocument::where('docheader_id', 'DOC1')->value('docstatus_id'));
            $this->assertSame('nao_lancada', $inv->link_status);
            $this->assertStringContainsString('anulado', (string) $inv->link_note);
            $this->assertNotContains('escrita', $this->failedGuards($inv)); // depois de anular, pode lançar-se outra vez
        }
    }

    public function test_status_reread_mismatch_is_confirmation_error(): void
    {
        $this->confirmUnits();
        $this->okLaunch();
        $inv = $this->invoice();
        $this->launchNow($inv);
        $this->statusReread = ['ok' => true, 'header' => ['docstatus_id' => '8001'], 'details' => []];
        $w = $this->svc()->requestStatus($inv, 'close', $this->user->id);
        $this->assertSame('erro_confirmacao', $this->svc()->execute($w->id)->status);
    }

    // ───────────────────────────────────────────────────────────── gravar como fechada

    public function test_close_needs_every_line_unit_confirmed_and_names_the_missing_one(): void
    {
        $this->okLaunch();
        $inv = $this->invoice();
        $this->confirmUnits(['PA']);
        $p = $this->svc()->preview($inv);
        $this->assertTrue($p['can_launch']);
        $this->assertSame('launch', $p['close']['mode']);
        $this->assertFalse($p['close']['can_close']);
        $this->assertSame(['unidades_confirmadas'], array_column($p['close']['blockers'], 'key'));
        $this->assertStringContainsString('ARTIGO B', $p['close']['blockers'][0]['message']);
        $this->assertStringNotContainsString('ARTIGO A', $p['close']['blockers'][0]['message']);

        // "Gravar como fechada" sem as unidades: 422 com o que falta; nada é escrito.
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch", ['close' => true])
            ->assertStatus(422)->assertJsonPath('errors.failed.0.key', 'unidades_confirmadas');
        $this->assertSame(0, PingwinDocumentWrite::count());

        // Um rascunho já lançado também não fecha sem as unidades.
        $this->launchNow($inv);
        $this->assertSame('draft', $this->svc()->preview($inv->fresh())['close']['mode']);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch/close")
            ->assertStatus(422)->assertJsonPath('errors.failed.0.key', 'unidades_confirmadas');
    }

    public function test_launch_as_closed_launches_the_draft_then_closes_it_and_records_who_and_when(): void
    {
        $this->confirmUnits();
        $this->okLaunch();
        $inv = $this->invoice();
        $this->assertTrue($this->svc()->preview($inv)['close']['can_close']);
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $res = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch", ['close' => true])
            ->assertStatus(202);
        $this->assertStringContainsString('fechada', $res->json('message'));
        $launch = PingwinDocumentWrite::where('action', 'launch')->firstOrFail();
        $this->assertTrue($launch->payload['close_after']);

        $this->assertSame('ok', $this->svc()->execute($launch->id)->status);
        $close = PingwinDocumentWrite::where('action', 'close')->firstOrFail();
        $this->assertSame(['ok', 'DOC1', $this->user->id], [$close->status, $close->docheader_id, $close->user_id]);
        $this->assertSame(['status', 'DOC1', '8002'], array_slice(end($this->calls), 0, 3));
        $inv->refresh();
        $this->assertSame($this->user->id, $inv->closed_by);
        $this->assertNotNull($inv->closed_at);
        $this->assertSame('8002', PingwinSupplierDocument::where('docheader_id', 'DOC1')->value('docstatus_id'));
        $p = $this->svc()->preview($inv);
        $this->assertFalse($p['close']['can_close']);
        $this->assertSame('fechada', $p['close']['blockers'][0]['key']);
        $this->assertSame($this->user->name, $p['close']['closed_by_name']);
    }

    public function test_a_draft_closes_from_the_screen_and_the_close_is_recorded(): void
    {
        $this->confirmUnits();
        $this->okLaunch();
        $inv = $this->invoice();
        $this->launchNow($inv);
        $this->assertTrue($this->svc()->preview($inv->fresh())['close']['can_close']);
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch/close")->assertStatus(202);
        $w = PingwinDocumentWrite::where('action', 'close')->firstOrFail();
        $this->assertSame('ok', $this->svc()->execute($w->id)->status);
        $this->assertSame($this->user->id, $inv->fresh()->closed_by);
        // Anular depois de fechar limpa o registo do fecho (o documento deixa de estar fechado).
        $void = $this->svc()->requestStatus($inv->fresh(), 'void', $this->user->id);
        $this->svc()->execute($void->id);
        $this->assertNull($inv->fresh()->closed_by);
    }

    // ───────────────────────────────────────────────────────────── descontos de promoção

    public function test_promo_discount_goes_as_the_line_adjustment_and_the_net_confirms(): void
    {
        // Linha B: bruto 6,00, desconto 0,48 (Leve Mais Pague Menos 45238) → líquido 5,52.
        $inv = $this->invoice(['qr_data' => ['fields' => ['O' => '9.79'], 'by_rate' => [['rate' => 6, 'base_cents' => 283, 'vat_cents' => 17], ['rate' => 23, 'base_cents' => 552, 'vat_cents' => 127]]]],
            ['line_total_cents' => 552, 'gross_total_cents' => 600, 'discount_cents' => 48, 'discount_code' => '45238', 'discount_label' => 'Leve Mais Pague Menos', 'promo_code' => '45238']);
        $inv->summary()->update(['total_cents' => 979]);
        OcrInvoiceDiscount::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'code' => '45238', 'label' => 'Leve Mais Pague Menos',
            'amount_cents' => 48, 'status' => 'atribuido']);
        $this->okLaunch('9.79', ['details' => [1 => ['total' => 5.52, 'adjustment' => -0.48]]]);
        $p = $this->svc()->preview($inv);
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values()));
        $this->assertSame(0, $p['estimate']['adjustment_cents']);

        $w = $this->launchNow($inv);
        $this->assertSame('ok', $w->status, (string) $w->error);
        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertNull($doc['lines'][0]['adjustment']);
        $this->assertSame(['2.000000', '3.000000', '-0.48'], [$doc['lines'][1]['price'], $doc['lines'][1]['qnt'], $doc['lines'][1]['adjustment']]);
        $this->assertSame('0', $doc['lines'][1]['discount1'], 'nunca uma percentagem calculada');
    }

    public function test_unassigned_discount_blocks_the_launch(): void
    {
        $inv = $this->invoice();
        OcrInvoiceDiscount::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'code' => '99999', 'label' => 'Leve Mais Pague Menos',
            'amount_cents' => 52, 'status' => 'por_atribuir']);
        $p = $this->svc()->preview($inv);
        $this->assertSame(['descontos'], collect($p['guards'])->where('ok', false)->pluck('key')->values()->all());
        $msg = collect($p['guards'])->firstWhere('key', 'descontos')['message'];
        $this->assertStringContainsString('Desconto por atribuir: Leve Mais Pague Menos 99999 (0,52 €)', $msg);
    }

    public function test_tenancy(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/pingwin-launch")->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch")
            // Antes do primeiro lançamento não há loja nem série por omissão (nada de um cliente no código).
            ->assertOk()->assertJsonPath('data.can_launch', true)->assertJsonPath('data.defaults.serie', null)->assertJsonPath('data.defaults.store', null);
        $this->assertSame(0, PingwinDocumentWrite::count());
    }
}
