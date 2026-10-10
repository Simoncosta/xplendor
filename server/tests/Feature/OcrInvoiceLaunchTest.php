<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\LaunchPingwinDocumentJob;
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
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\OcrInvoiceLaunchService;
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
                $this->test->calls[] = ['status', $docheaderId, $status];

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

    public function test_guard_matrix_each_one_blocks(): void
    {
        $cases = [
            'tipo'        => [['doc_type' => 'NC'], []],
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

    public function test_out_of_scope_types_are_disabled_with_message(): void
    {
        foreach (['FR', 'FS', 'NC', 'ND'] as $t) {
            $g = collect($this->svc()->preview($this->invoice(['doc_type' => $t]))['guards'])->firstWhere('key', 'tipo');
            $this->assertFalse($g['ok']);
            $this->assertSame('Por agora, lançar à mão no PingWin.', $g['message']);
        }
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
        $this->okLaunch();
        $inv = $this->invoice();
        $this->launchNow($inv);
        $this->statusReread = ['ok' => true, 'header' => ['docstatus_id' => '8001'], 'details' => []];
        $w = $this->svc()->requestStatus($inv, 'close', $this->user->id);
        $this->assertSame('erro_confirmacao', $this->svc()->execute($w->id)->status);
    }

    public function test_tenancy(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/pingwin-launch")->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-launch")
            ->assertOk()->assertJsonPath('data.can_launch', true)->assertJsonPath('data.defaults.serie', 'VFT BOVFT');
        $this->assertSame(0, PingwinDocumentWrite::count());
    }
}
