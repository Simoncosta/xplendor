<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\LinkOcrInvoiceJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use App\Models\OcrInvoiceLine;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\OcrPingwinLinkService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F3: ligação Fatura OCR ↔ documento(s) do PingWin. Só espelhos: o PingwinService é
 * um mock que FALHA em qualquer chamada ao Python que não seja a pesquisa de fornecedor por NIF
 * (só leitura). Cobre: normalização de NIF e nº; regras a, b (1 e vários), c (guias, com
 * diferença), d; duplicada; documento ligado que passa a anulado; tipos; tenancy; API; comando.
 */
class OcrPingwinLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $yuko;
    private Company $other;
    private User $user;
    private User $otherUser;
    public array $pingwinCalls = [];
    public array $liveSuppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Ferreira & Cambas, Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->yuko, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active',
                'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        }
        $this->user = User::factory()->create(['company_id' => $this->yuko->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

        // ⚠️ Mock: só a pesquisa por NIF (leitura) é permitida; qualquer outra chamada falha o teste.
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                $this->test->pingwinCalls[] = $payload['mode'] ?? '?';
                if (($payload['mode'] ?? '') !== 'find_supplier_by_nif') {
                    throw new \LogicException('Chamada ao PingWin proibida neste teste: ' . ($payload['mode'] ?? '?'));
                }

                return ['ok' => true, 'active' => $this->test->liveSuppliers, 'voided' => []];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->assertSame([], array_diff($this->pingwinCalls, ['find_supplier_by_nif']), 'Nenhuma escrita no PingWin');
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function supplier(string $nif, string $pingwinId = 'E1', ?Company $c = null, string $name = 'Carnes Sá da Bandeira'): PingwinSupplier
    {
        return PingwinSupplier::create(['company_id' => ($c ?? $this->yuko)->id, 'source' => 'pingwin', 'pingwin_id' => $pingwinId,
            'name' => $name, 'tax_number' => $nif, 'is_active' => true]);
    }

    private function doc(string $id, int $totalCents, string $date, array $extra = [], ?Company $c = null): PingwinSupplierDocument
    {
        return PingwinSupplierDocument::create(array_merge([
            'company_id' => ($c ?? $this->yuko)->id, 'docheader_id' => $id, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor',
            'document' => "VFT 0VFT/{$id}", 'entity_pingwin_id' => 'E1', 'entity_name' => 'CARNES', 'doc_date' => $date,
            'total_cents' => $totalCents, 'docstatus_id' => '8002', 'docstatus_description' => 'Fechado', 'paid' => true,
            'store_name' => 'Tabern Yuko Baixa',
        ], $extra));
    }

    /** Fatura OCR com o cabeçalho do QR (A, D, F, G, H, O). */
    private function invoice(array $o = [], ?Company $c = null): OcrInvoice
    {
        $c ??= $this->yuko;
        $total = $o['total'] ?? 2082.84;
        $inv = OcrInvoice::create(array_merge([
            'company_id' => $c->id, 'image_path' => 'x.pdf', 'image_mime' => 'application/pdf', 'status' => 'por_validar',
            'supplier_nif' => '502811331', 'supplier_name' => 'Carnes Sá da Bandeira, Lda.', 'number' => 'FD M501/1681',
            'issue_date' => '2026-05-04', 'doc_type' => 'FT', 'atcud' => null, 'qr_ok' => true,
            'qr_data' => ['fields' => ['O' => number_format($total, 2, '.', '')]],
        ], array_diff_key($o, ['total' => 1])));
        OcrInvoiceSummary::create(['ocr_invoice_id' => $inv->id, 'company_id' => $c->id, 'total_cents' => (int) round($total * 100)]);

        return $inv;
    }

    private function svc(): OcrPingwinLinkService
    {
        return app(OcrPingwinLinkService::class);
    }

    private function link(OcrInvoice $inv, bool $live = false): OcrInvoice
    {
        return $this->svc()->link($inv, $live)->fresh();
    }

    private function linkedDocs(OcrInvoice $inv): array
    {
        return $inv->pingwinLinks()->orderBy('docheader_id')->pluck('docheader_id')->all();
    }

    // ───────────────────────────────────────────────────────────── normalização

    public function test_normalization_of_nif_number_and_guides(): void
    {
        $this->assertSame('500246467', OcrPingwinLinkService::digits('500 246 467'));
        $this->assertSame('500246467', OcrPingwinLinkService::digits('PT500246467'));
        $this->assertSame('379', OcrPingwinLinkService::numberKey('FT FAR.2026/379'));
        $this->assertSame('24366', OcrPingwinLinkService::numberKey('FAC 02002202601/024366'));
        $this->assertSame('27731', OcrPingwinLinkService::numberKey('027731'));
        $this->assertSame('0', OcrPingwinLinkService::numberKey('000'));
        $this->assertNull(OcrPingwinLinkService::numberKey('sem número'));

        $g = OcrPingwinLinkService::extractGuides("GT 3105/2026 de 02/05/2026\n101031 Forma\nGT 3159/2026 de 04/05/2026\nGR 77");
        $this->assertSame([
            ['ref' => 'GT 3105/2026', 'date' => '2026-05-02'],
            ['ref' => 'GT 3159/2026', 'date' => '2026-05-04'],
            ['ref' => 'GR 77', 'date' => null],
        ], $g);
    }

    public function test_supplier_nif_with_spaces_in_mirror_is_found(): void
    {
        $this->supplier('502 811 331');
        $this->doc('57', 208284, '2026-05-04');
        $inv = $this->link($this->invoice());
        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->link_status);
        $this->assertNotNull($inv->supplier_id);
    }

    // ───────────────────────────────────────────────────────────── a, b, c, d

    public function test_a_by_number(): void
    {
        $this->supplier('502811331');
        $this->doc('X1', 100, '2026-05-20', ['docreference_number' => '01681']); // total diferente; o nº manda
        $this->doc('X2', 100, '2025-05-20', ['docreference_number' => '1681']);  // mesmo nº, um ano antes: fora da janela
        $inv = $this->link($this->invoice());

        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->link_status);
        $this->assertSame(['X1'], $this->linkedDocs($inv));
        $this->assertSame(OcrInvoicePingwinLink::NUMBER, $inv->pingwinLinks()->value('method'));
        $this->assertSame(100 - 208284, $inv->link_diff_cents);
    }

    public function test_b_total_and_date_single_candidate_needs_confirmation(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $this->doc('58', 208284, '2026-05-09');  // 5 dias: fora
        $this->doc('59', 208285, '2026-05-04');  // 1 cêntimo a mais: fora
        $inv = $this->link($this->invoice());

        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->link_status);
        $this->assertSame(['57'], $this->linkedDocs($inv));
        $l = $inv->pingwinLinks()->first();
        $this->assertSame(OcrInvoicePingwinLink::TOTAL_DATE, $l->method);
        $this->assertNull($l->confirmed_at); // "confirmar"
        $this->assertSame(0, $inv->link_diff_cents);

        // Confirmar
        $this->svc()->confirm($inv, [], null, $this->user->id);
        $this->assertNotNull($inv->pingwinLinks()->first()->confirmed_at);
    }

    public function test_b_uses_reference_date_and_plus_one_day(): void
    {
        $this->supplier('517343355', 'E9');
        // Julho: lançado a 07-07, fatura de 07-06 (+1 dia).
        $this->doc('216', 185001, '2026-07-07', ['entity_pingwin_id' => 'E9']);
        // Lançado muito depois, mas com a data da fatura do fornecedor (F4) igual a F.
        $this->doc('300', 185001, '2026-08-30', ['entity_pingwin_id' => 'E9', 'docreference_date' => '2026-07-06']);
        $inv = $this->link($this->invoice(['supplier_nif' => '517343355', 'number' => '1 2026/3', 'issue_date' => '2026-07-06', 'total' => 1850.01]));

        $this->assertSame(OcrPingwinLinkService::POSSIBLE, $inv->link_status); // 2 candidatos
        $this->assertEqualsCanonicalizing(['216', '300'], $inv->link_candidates['docs']);
        $this->assertSame([], $this->linkedDocs($inv));
    }

    public function test_c_guides_candidates_then_confirm_with_difference(): void
    {
        $this->supplier('516182609', 'EF', null, 'Forno Tradicional');
        $this->doc('62', 6402, '2026-05-02', ['entity_pingwin_id' => 'EF']);
        $this->doc('63', 9391, '2026-05-04', ['entity_pingwin_id' => 'EF']);
        $this->doc('175', 11398, '2026-05-30', ['entity_pingwin_id' => 'EF']);
        $this->doc('240', 6402, '2026-06-01', ['entity_pingwin_id' => 'EF']);   // depois de F: fora
        $this->doc('ANUL', 9999, '2026-05-10', ['entity_pingwin_id' => 'EF', 'docstatus_id' => '8003']); // anulado: fora
        $inv = $this->link($this->invoice([
            'supplier_nif' => '516182609', 'number' => 'FT FAR.2026/379', 'issue_date' => '2026-05-31', 'total' => 1287.82,
            'guide_refs' => [['ref' => 'GT 3105/2026', 'date' => '2026-05-02'], ['ref' => 'GT 3846/2026', 'date' => '2026-05-30']],
        ]));

        $this->assertSame(OcrPingwinLinkService::POSSIBLE, $inv->link_status);
        $this->assertSame('guias', $inv->link_candidates['mode']);
        $this->assertSame(['62', '63', '175'], $inv->link_candidates['docs']);
        $this->assertSame(['from' => '2026-05-01', 'to' => '2026-05-31', 'source' => 'guias'], $inv->link_candidates['period']);

        $this->svc()->confirm($inv, ['62', '63', '175'], 'guias', $this->user->id);
        $inv->refresh();
        $this->assertSame(OcrPingwinLinkService::LAUNCHED_GUIDES, $inv->link_status);
        $this->assertSame(6402 + 9391 + 11398 - 128782, $inv->link_diff_cents);
        $this->assertSame(3, $inv->pingwinLinks()->whereNotNull('confirmed_at')->where('method', 'guias')->count());

        // Uma nova verificação não desfaz a ligação confirmada.
        $this->assertSame(OcrPingwinLinkService::LAUNCHED_GUIDES, $this->link($inv)->link_status);
    }

    public function test_d_nothing_is_not_launched_with_35_day_choices(): void
    {
        $this->supplier('502030712', 'EM', null, 'Makro');
        $this->doc('25', 17621, '2026-05-05', ['entity_pingwin_id' => 'EM']);
        $inv = $this->link($this->invoice(['supplier_nif' => '502030712', 'number' => 'FAC 02002202601/024366', 'issue_date' => '2026-05-12', 'total' => 3381.83]));

        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $inv->link_status);
        $this->assertSame('35_dias', $inv->link_candidates['period']['source']);
        $this->assertSame(['25'], $inv->link_candidates['docs']);
        $this->assertSame([], $this->linkedDocs($inv));
    }

    public function test_d_without_any_document(): void
    {
        $this->supplier('502811331');
        $inv = $this->link($this->invoice());
        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $inv->link_status);
    }

    // ───────────────────────────────────────────────────────────── tipos e anulados

    public function test_compatible_types_only(): void
    {
        $this->supplier('502811331');
        $this->doc('FT1', 5000, '2026-05-04');                                          // 1209
        $this->doc('NC1', 5000, '2026-05-04', ['docconfig_id' => '1205']);              // NC
        $this->doc('FRC1', 7000, '2026-05-04', ['docconfig_id' => OcrPingwinLinkService::FRC]);

        $nc = $this->link($this->invoice(['doc_type' => 'NC', 'number' => 'NC 1', 'total' => 50.00]));
        $this->assertSame(['NC1'], $this->linkedDocs($nc));

        $fr = $this->link($this->invoice(['doc_type' => 'FR', 'number' => 'FR 2', 'total' => 70.00]));
        $this->assertSame(['FRC1'], $this->linkedDocs($fr));

        $ft = $this->link($this->invoice(['doc_type' => 'FT', 'number' => 'FT 3', 'total' => 70.00]));
        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $ft->link_status); // FT não liga a Fatura-recibo
    }

    public function test_linked_document_voided_goes_back_to_not_launched_with_warning(): void
    {
        $this->supplier('502811331');
        $d = $this->doc('57', 208284, '2026-05-04');
        $inv = $this->link($this->invoice());
        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->link_status);

        $d->update(['docstatus_id' => '8003', 'docstatus_description' => 'Anulado']);
        $inv = $this->link($inv);

        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $inv->link_status);
        $this->assertStringContainsString('foi anulado', $inv->link_note);
        $this->assertSame([], $this->linkedDocs($inv));
    }

    public function test_document_already_linked_elsewhere_is_not_reused(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $first = $this->link($this->invoice());
        $second = $this->link($this->invoice(['number' => 'FD M501/9999']));

        $this->assertSame(['57'], $this->linkedDocs($first));
        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $second->link_status);
    }

    // ───────────────────────────────────────────────────────────── duplicada e fornecedor

    public function test_duplicate_by_nif_and_number_or_atcud(): void
    {
        $this->supplier('502811331');
        $original = $this->link($this->invoice(['atcud' => 'J6TBYMGN-1681']));
        $dup = $this->link($this->invoice(['number' => 'FD  M501/1681']));                       // mesmo A + G (espaços)
        $dupAtcud = $this->link($this->invoice(['supplier_nif' => '999999990', 'number' => 'X', 'atcud' => 'J6TBYMGN-1681']));

        $this->assertSame(OcrPingwinLinkService::DUPLICATE, $dup->link_status);
        $this->assertSame($original->id, $dup->duplicate_of_id);
        $this->assertSame(OcrPingwinLinkService::DUPLICATE, $dupAtcud->link_status);
        $this->assertSame($original->id, $dupAtcud->duplicate_of_id);
    }

    public function test_missing_supplier_then_live_search_brings_it_into_the_mirror(): void
    {
        $this->doc('57', 208284, '2026-05-04', ['entity_pingwin_id' => 'NEW1']);
        $inv = $this->link($this->invoice());
        $this->assertSame(OcrPingwinLinkService::MISSING_SUPPLIER, $inv->link_status);
        $this->assertSame([], $this->pingwinCalls); // sem $live: só espelho

        $this->liveSuppliers = [['id' => 'NEW1', 'code' => '300', 'name' => 'CARNES SÁ DA BANDEIRA', 'tax_number' => '502811331']];
        $inv = $this->link($inv, true);

        $this->assertSame(['find_supplier_by_nif'], $this->pingwinCalls); // só leitura
        $this->assertDatabaseHas('suppliers', ['company_id' => $this->yuko->id, 'pingwin_id' => 'NEW1', 'tax_number' => '502811331']);
        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->link_status);
    }

    public function test_own_company_nif_is_never_the_supplier(): void
    {
        $this->supplier('514148497', 'ARMAZEM');
        $inv = $this->link($this->invoice(['supplier_nif' => '514148497']), true);
        $this->assertSame(OcrPingwinLinkService::MISSING_SUPPLIER, $inv->link_status);
        $this->assertSame([], $this->pingwinCalls);
    }

    public function test_not_ours_invoice_is_not_linked(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $inv = $this->link($this->invoice(['status' => 'nao_desta_empresa']));
        $this->assertNull($inv->link_status);
        $this->assertSame([], $this->linkedDocs($inv));
    }

    public function test_unlink_rejects_document_and_does_not_relink_it(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $inv = $this->link($this->invoice());
        $inv = $this->svc()->unlink($inv)->fresh();

        $this->assertSame(OcrPingwinLinkService::NOT_LAUNCHED, $inv->link_status);
        $this->assertSame(['57'], $inv->link_rejected);
        $this->assertSame([], $this->linkedDocs($this->link($inv)));

        // Escolher outra vez esse documento à mão volta a ligá-lo.
        $this->svc()->confirm($inv, ['57'], null, $this->user->id);
        $this->assertSame(OcrPingwinLinkService::LAUNCHED, $inv->fresh()->link_status);
        $this->assertNull($inv->fresh()->link_rejected);
    }

    public function test_compare_lines_ocr_vs_pingwin(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $inv = $this->invoice();
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 0, 'supplier_code' => '00005', 'item' => 'BIFE', 'line_total_cents' => 94500]);
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->yuko->id, 'position' => 1, 'item' => 'FRANGO', 'line_total_cents' => 10433]);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'line_number' => 1, 'description' => 'BIFE NOVILHO', 'supplier_code' => '00005', 'total_cents' => 94500]);
        PingwinSupplierDocumentLine::create(['company_id' => $this->yuko->id, 'docheader_id' => '57', 'line_number' => 2, 'description' => 'PERU', 'total_cents' => 5584]);
        $inv = $this->link($inv);

        $c = $this->svc()->present($inv)['compare'];
        $this->assertSame([2, 2], [$c['ocr_count'], $c['pw_count']]);
        $this->assertEquals(1049.33, $c['ocr_sum']);
        $this->assertSame(['FRANGO'], array_column($c['unmatched_ocr'], 'description'));
        $this->assertSame(['PERU'], array_column($c['unmatched_pw'], 'description'));
    }

    // ───────────────────────────────────────────────────────────── API, tenancy e comando

    public function test_api_show_index_confirm_unlink_and_tenancy(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $this->doc('58', 208284, '2026-05-20');
        $this->doc('99', 208284, '2026-05-04', [], $this->other); // documento de OUTRA empresa
        $inv = $this->link($this->invoice());
        $base = "/api/v1/companies/{$this->yuko->id}/ocr/invoices";

        $this->actingAs($this->user, 'sanctum')->getJson("{$base}/{$inv->id}")
            ->assertOk()
            ->assertJsonPath('data.pingwin.status', 'lancada')
            ->assertJsonPath('data.pingwin.linked.0.document', 'VFT 0VFT/57')
            ->assertJsonPath('data.pingwin.linked.0.method', 'total_data')
            ->assertJsonPath('data.pingwin.linked.0.confirmed', false);

        $this->actingAs($this->user, 'sanctum')->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.invoices.data.0.doc_type', 'FT')
            ->assertJsonPath('data.invoices.data.0.link_status', 'lancada')
            ->assertJsonPath('data.invoices.data.0.paid', true)
            ->assertJsonPath('data.invoices.data.0.store', 'Tabern Yuko Baixa');

        // Documento de outra empresa → 422; outro documento da empresa → ligado à mão.
        $this->actingAs($this->user, 'sanctum')->postJson("{$base}/{$inv->id}/pingwin-link/confirm", ['docheader_ids' => ['99']])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson("{$base}/{$inv->id}/pingwin-link/confirm", ['docheader_ids' => ['58']])
            ->assertOk()->assertJsonPath('data.pingwin.linked.0.document', 'VFT 0VFT/58')->assertJsonPath('data.pingwin.linked.0.method', 'manual');

        $this->actingAs($this->user, 'sanctum')->deleteJson("{$base}/{$inv->id}/pingwin-link")->assertOk();
        $this->assertSame(0, $inv->pingwinLinks()->whereIn('docheader_id', ['58'])->count());

        // Tenancy: a outra empresa não vê nem mexe.
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-link")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->postJson("/api/v1/companies/{$this->other->id}/ocr/invoices/{$inv->id}/pingwin-link")->assertStatus(404);

        // Documentos PingWin: coluna OCR.
        $this->svc()->confirm($inv->fresh(), ['57'], null, $this->user->id);
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->yuko->id}/integrations/pingwin/supplier-documents?from=2026-05-01&to=2026-05-31")
            ->assertOk()
            ->assertJsonFragment(['docheader_id' => '57', 'ocr_invoice_id' => $inv->id]);
    }

    public function test_search_button_without_supplier_queues_live_search_in_worker(): void
    {
        Bus::fake([LinkOcrInvoiceJob::class]);
        $inv = $this->invoice();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/companies/{$this->yuko->id}/ocr/invoices/{$inv->id}/pingwin-link")
            ->assertOk()
            ->assertJsonPath('data.pingwin.status', 'fornecedor_em_falta')
            ->assertJsonPath('data.pingwin.search_pending', true)
            ->assertJsonPath('data.pingwin.supplier.prefill.nif', '502811331');
        Bus::assertDispatched(LinkOcrInvoiceJob::class, fn ($j) => $j->invoiceId === $inv->id);
        $this->assertSame([], $this->pingwinCalls); // o pedido HTTP nunca chama o Python
    }

    public function test_command(): void
    {
        $this->supplier('502811331');
        $this->doc('57', 208284, '2026-05-04');
        $inv = $this->invoice();
        $this->artisan('ocr:link-pingwin', ['company' => $this->yuko->id, '--invoice' => $inv->id])
            ->expectsOutputToContain('lancada')
            ->assertExitCode(0);
        $this->assertSame(['57'], $this->linkedDocs($inv));
    }
}
