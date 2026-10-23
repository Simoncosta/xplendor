<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\User;
use App\Services\AtInvoiceQr;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use App\Services\OcrInvoiceRereadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — Faturas: notas de crédito, leitura sem QR e vários documentos no mesmo ficheiro.
 * Tudo sintético (NIF válidos inventados; os valores da coluna IEC são os de uma fatura real de
 * bebidas, sem nomes). O scraper e a leitura são fakes (sem rede):
 *  · QR com D:NC (todo não sujeito, em L): lê-se e confere a 0 %;
 *  · vários QR diferentes no mesmo PDF: para antes da leitura, com a lista; a mesma fatura em duas vias conta uma;
 *  · sem QR: o tipo, o adquirente e o ATCUD lidos; o adquirente certo passa, o errado fica "não é desta empresa"
 *    (verificado pelo texto); emitente e adquirente trocados na leitura são destrocados;
 *  · IVA a 0 % e a coluna IEC somada (a base de 23 % inclui o IEC); o "+ … INCL." continua informativo.
 */
class InvoiceOcrCreditNotesTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = '509999999';
    private const SUPPLIER = '508888883';
    private const OTHER = '511111118';

    private const QR_NC = 'A:' . self::SUPPLIER . '*B:' . self::OWN . '*C:PT*D:NC*E:N*F:20261016*G:NC CX/0196*H:0*I1:PT*L:291.59*N:0.00*O:291.59*Q:KN8F*R:2177';
    private const QR_FT = 'A:' . self::SUPPLIER . '*B:' . self::OWN . '*C:PT*D:FT*E:N*F:20261017*G:FT A/77*H:J6ABCDEF-77*I1:PT*I7:100.00*I8:23.00*N:23.00*O:123.00*Q:AbCd*R:0001';

    private Company $company;
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.openai.ocr.text_min_chars' => 200, 'services.openai.ocr.check_tolerance_cents' => 2]);
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => self::OWN, 'fiscal_name' => 'Restaurante Teste, Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    /** O scraper devolve $analysis; a leitura devolve as respostas pela ordem (regista as chamadas). */
    private function bindFake(array $analysis, array $responses): void
    {
        $this->fake = new class($analysis, $responses) extends InvoiceOcrService {
            public array $calls = [];

            public function __construct(private array $analysis, private array $responses) {}

            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                return $this->analysis;
            }

            protected function callAnthropic(array $plan, string $system, ?array $qr): array
            {
                $this->calls[] = ['system' => $system, 'qr' => $qr !== null];
                $r = array_shift($this->responses);

                return ['content' => json_encode($r), 'tokens_in' => 1000, 'tokens_out' => 100, 'input' => 'pdf'];
            }
        };
        $this->app->instance(InvoiceOcrService::class, $this->fake);
    }

    /** O que o scraper devolve: $qrs = os QR encontrados (texto e página). */
    private function analysis(array $qrs, int $textChars = 0): array
    {
        $found = array_map(fn ($q) => ['raw' => $q[0], 'page' => $q[1], 'dpi' => 200], $qrs);

        return ['ok' => true, 'kind' => 'pdf', 'pages' => max(1, count($qrs)), 'qr' => $found[0] ?? null, 'qrs' => $found,
            'text' => '', 'text_chars' => $textChars, 'copies_dropped' => [], 'kept_pages' => [1], 'images' => [], 'truncated' => false];
    }

    private function runOcr(): OcrInvoice
    {
        $inv = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => "ocr-invoices/{$this->company->id}/f.pdf", 'image_mime' => 'application/pdf', 'status' => 'processing']);
        Storage::disk('local')->put($inv->image_path, 'bytes');
        ProcessInvoiceOcrJob::dispatchSync($this->company->id, $inv->id);

        return $inv->fresh(['lines', 'summary']);
    }

    private function lastAlert(): ?Alert
    {
        return Alert::where('company_id', $this->company->id)->orderByDesc('id')->first();
    }

    /** A leitura completa (sem QR). */
    private function fullJson(array $over = []): array
    {
        return $over + [
            'fornecedor' => ['nome' => 'Bebidas Teste, SA', 'nif' => self::SUPPLIER],
            'tipoDocumento' => 'FT', 'nifAdquirente' => self::OWN, 'atcud' => 'ABCD1234-55', 'numeroFatura' => 'FT 2026/55', 'dataEmissao' => '2026-10-15',
            'linhas' => [['item' => 'Água 1,5 L', 'quantidade' => 6, 'unidade' => 'UN', 'precoUnitario' => 1, 'totalLinha' => 6.00, 'taxaIva' => 6]],
            'sumario' => ['ivaPorTaxa' => [['taxa' => 6, 'base' => 6.00, 'iva' => 0.36]], 'total' => 6.36],
            'quadroIva' => [], 'descontos' => [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────── QR com D:NC

    public function test_a_credit_note_qr_is_read_and_the_not_subject_value_counts_at_zero_percent(): void
    {
        $qr = AtInvoiceQr::parse(self::QR_NC);
        $this->assertTrue($qr['valid']);
        $this->assertSame('NC', $qr['doc_type']);
        $this->assertSame([['rate' => 0, 'base_cents' => 29159, 'vat_cents' => 0]], $qr['by_rate']);
        $this->assertSame('Nota de crédito', AtInvoiceQr::docTypeLabel('NC'));

        $this->bindFake($this->analysis([[self::QR_NC, 1]]), [['fornecedorNome' => 'Cervejas Teste, SA', 'linhas' => [
            ['item' => 'Barril 50 L devolvido', 'quantidade' => 3, 'unidade' => 'UN', 'precoUnitario' => 75.00, 'totalLinha' => 225.00, 'taxaIva' => 0],
            ['item' => 'Grade 24 garrafas devolvida', 'quantidade' => 11, 'unidade' => 'UN', 'precoUnitario' => 6.0536, 'totalLinha' => 66.59, 'taxaIva' => 0],
        ], 'guias' => [], 'quadroIva' => [], 'descontos' => []]]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status, (string) $inv->error_message);
        $this->assertSame('NC', $inv->doc_type);
        $this->assertSame(self::OWN, $inv->buyer_nif);
        $this->assertSame('confere', $inv->check_status);
        $this->assertSame(29159, $inv->summary->total_cents);
        $this->assertSame('Fatura lida', $this->lastAlert()->title);
    }

    // ─────────────────────────────────────────────────────────── vários documentos

    public function test_two_documents_in_one_file_stop_before_reading_with_the_list(): void
    {
        $this->bindFake($this->analysis([[self::QR_FT, 1], [self::QR_NC, 2]]), []);
        $inv = $this->runOcr();

        $this->assertSame('erro', $inv->status);
        $this->assertSame([], $this->fake->calls, 'não se lê nada');
        $this->assertSame(
            "Este ficheiro tem 2 documentos. Carregue um documento por ficheiro.\n"
            . '· Fatura FT A/77, emitente ' . self::SUPPLIER . " (página 1)\n"
            . '· Nota de crédito NC CX/0196, emitente ' . self::SUPPLIER . ' (página 2)',
            $inv->error_message
        );
        $this->assertSame(['NC', 'NC CX/0196'], [$inv->qr_data['documents'][1]['doc_type'], $inv->qr_data['documents'][1]['number']]);
        $this->assertSame(0, $inv->lines->count());
        $this->assertSame('Fatura não lida', $this->lastAlert()->title);
        $this->assertStringStartsWith('Este ficheiro tem 2 documentos.', $this->lastAlert()->message);
    }

    public function test_the_same_invoice_in_two_copies_is_one_document(): void
    {
        $this->bindFake($this->analysis([[self::QR_FT, 1], [self::QR_FT, 2]]), [['fornecedorNome' => 'X', 'linhas' => [
            ['item' => 'Artigo', 'quantidade' => 1, 'precoUnitario' => 100, 'totalLinha' => 100.00, 'taxaIva' => 23],
        ], 'guias' => [], 'quadroIva' => [], 'descontos' => []]]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status);
        $this->assertSame('confere', $inv->check_status);
    }

    // ───────────────────────────────────────────────────────────────────── sem QR

    public function test_without_qr_the_reading_brings_type_buyer_and_atcud_and_the_right_buyer_passes(): void
    {
        $this->bindFake($this->analysis([]), [$this->fullJson(['tipoDocumento' => 'NC', 'numeroFatura' => 'NC 2026/9', 'atcud' => 'ABCD1234-9'])]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status);
        $this->assertSame(['NC', self::OWN, 'ABCD1234-9', 'NC 2026/9'], [$inv->doc_type, $inv->buyer_nif, $inv->atcud, $inv->number]);
        $this->assertSame('sem_qr', $inv->check_status);
        $this->assertFalse($this->fake->calls[0]['qr']);
        $this->assertStringContainsString('"nifAdquirente"', $this->fake->calls[0]['system']);
    }

    public function test_without_qr_a_code_that_is_not_this_documents_atcud_stays_empty(): void
    {
        // Uma referência no topo do talão, igual na nota de crédito e na fatura, que não bate com o número.
        $this->bindFake($this->analysis([]), [$this->fullJson(['numeroFatura' => '3040707088', 'atcud' => 'ABCD1234-3040707091'])]);
        $this->assertNull($this->runOcr()->atcud);

        $this->assertSame('J6TBYMGN-1681', InvoiceOcrService::atcudFor('ATCUD: J6TBYMGN-1681', 'FD M501/1681'));
        $this->assertSame('J6FCWGPS-024366', InvoiceOcrService::atcudFor('J6FCWGPS-024366', 'FAC 02002202601/24366'));
        $this->assertNull(InvoiceOcrService::atcudFor('ABCD1234-3040707091', '3382764649'));
        $this->assertNull(InvoiceOcrService::atcudFor('0', 'NC 1/5'));
        $this->assertNull(InvoiceOcrService::atcudFor('', 'NC 1/5'));
        $this->assertStringContainsString('só o código escrito junto da palavra "ATCUD"', app(InvoiceOcrService::class)->prompt());
    }

    public function test_the_line_discount_column_goes_to_the_discount_percentage(): void
    {
        $svc = app(InvoiceOcrService::class);
        foreach ([$svc->prompt(), $svc->linesPrompt()] as $p) {
            $this->assertStringContainsString('da coluna de desconto em percentagem ("%Desc"', $p);
            $this->assertStringContainsString('quantidade × precoUnitario × (1 − descontoPct/100) ≈ totalLinha (sem o IEC)', $p);
        }
        $clean = $svc->sanitize($this->fullJson(['linhas' => [['item' => 'Bebida 24', 'quantidade' => 3, 'precoUnitario' => 20.88, 'descontoPct' => 34.99, 'totalLinha' => 40.72, 'taxaIva' => 23, 'valorIec' => 1.65]]]));
        $l = $clean['lines'][0];
        $this->assertSame(34.99, $l['discount_pct']);
        $this->assertSame(4072, (int) round(3 * 20.88 * (1 - 34.99 / 100) * 100), 'quantidade × preço × (1 − desconto) = valor sem o IEC');
        $this->assertSame([4237, 165], [$l['line_total_cents'], $l['iec_cents']]);
    }

    public function test_without_qr_a_wrong_buyer_is_not_this_company_verified_by_the_text(): void
    {
        $this->bindFake($this->analysis([]), [$this->fullJson(['nifAdquirente' => self::OTHER])]);
        $inv = $this->runOcr();

        $this->assertSame(InvoiceOcrService::STATUS_NOT_OURS, $inv->status);
        $message = 'Esta fatura não é desta empresa: o NIF do adquirente lido na fatura é ' . self::OTHER . ', o da empresa é ' . self::OWN
            . '. Verificado pelo texto; o QR não se conseguiu ler.';
        $this->assertSame($message, $inv->error_message);
        $this->assertSame('Fatura não é desta empresa', $this->lastAlert()->title);
        $this->assertSame($message, $this->lastAlert()->message);
    }

    public function test_without_qr_an_unreadable_or_invalid_buyer_does_not_block(): void
    {
        $this->bindFake($this->analysis([]), [$this->fullJson(['nifAdquirente' => '123456780'])]);
        $this->assertSame('por_validar', $this->runOcr()->status);
    }

    public function test_issuer_and_buyer_swapped_in_the_reading_are_swapped_back(): void
    {
        $this->bindFake($this->analysis([]), [$this->fullJson(['fornecedor' => ['nome' => 'Bebidas Teste, SA', 'nif' => self::OWN], 'nifAdquirente' => self::SUPPLIER])]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status);
        $this->assertSame([self::SUPPLIER, self::OWN], [$inv->supplier_nif, $inv->buyer_nif]);
    }

    public function test_the_not_ours_alert_by_qr_has_both_nifs(): void
    {
        $qr = 'A:' . self::SUPPLIER . '*B:' . self::OTHER . '*C:PT*D:NC*E:N*F:20261016*G:NC CX/1*H:0*I1:PT*L:10.00*N:0.00*O:10.00*Q:KN8F*R:2177';
        $this->bindFake($this->analysis([[$qr, 1]]), []);
        $inv = $this->runOcr();

        $this->assertSame(InvoiceOcrService::STATUS_NOT_OURS, $inv->status);
        $this->assertSame('Esta fatura não é desta empresa: o NIF do adquirente no QR é ' . self::OTHER . ', o da empresa é ' . self::OWN . '. Não foi lida.',
            $this->lastAlert()->message);
    }

    // ──────────────────────────────────────────────────────── IVA 0 % e IEC somado

    public function test_iec_column_is_added_to_the_line_and_the_vat_check_matches_the_invoice(): void
    {
        // Valor líquido 128,60 + IEC 2,87 + IVA 22,19 = 153,66; a 23 %: 93,60 + 2,87 = 96,47; a 0 %: 35,00 (caixas).
        $this->bindFake($this->analysis([]), [$this->fullJson([
            'linhas' => [
                ['item' => 'Refrigerante lata 33 cl', 'quantidade' => 48, 'unidade' => 'UN', 'precoUnitario' => 1.25, 'totalLinha' => 60.00, 'taxaIva' => 23, 'valorIec' => 1.84],
                ['item' => 'Néctar 20 cl', 'quantidade' => 48, 'unidade' => 'UN', 'precoUnitario' => 0.70, 'totalLinha' => 33.60, 'taxaIva' => 23, 'valorIec' => 1.03],
                ['item' => '+ 0,52 TAXA IEC INCL. PREÇO U.V.', 'totalLinha' => 0.52],
                ['item' => 'Caixa de vasilhame', 'quantidade' => 7, 'unidade' => 'UN', 'precoUnitario' => 5.00, 'totalLinha' => 35.00, 'taxaIva' => 0],
            ],
            'quadroIva' => [['codigo' => '0', 'taxa' => 0, 'base' => 35.00, 'iva' => 0], ['codigo' => '1', 'taxa' => 23, 'base' => 96.47, 'iva' => 22.19]],
            'sumario' => ['ivaPorTaxa' => [['taxa' => 0, 'base' => 35.00, 'iva' => 0], ['taxa' => 23, 'base' => 96.47, 'iva' => 22.19]], 'valorIvaTotal' => 22.19, 'total' => 153.66],
        ])]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status);
        $lines = $inv->lines->sortBy('position')->values();
        $this->assertCount(3, $lines, 'o "+ … INCL." continua informativo');
        $this->assertSame([6184, 3463, 3500], $lines->pluck('line_total_cents')->all());
        $this->assertSame([184, 103, null], $lines->pluck('iec_cents')->all());
        $this->assertSame([23, 23, 0], $lines->pluck('vat_rate')->all());
        $this->assertSame(['+ 0,52 TAXA IEC INCL. PREÇO U.V.'], $inv->ignored_lines);

        $byRate = app(OcrInvoiceRereadService::class)->vatByRate($inv);
        $this->assertSame('fatura', $byRate['source']);
        $this->assertTrue($byRate['ok']);
        $rows = collect($byRate['rows'])->keyBy('rate');
        $this->assertEquals([35, 35, 0], [$rows[0]['read_base'], $rows[0]['calc_base'], $rows[0]['calc_vat']]);
        $this->assertEquals([96.47, 96.47, 22.19, 22.19], [$rows[23]['read_base'], $rows[23]['calc_base'], $rows[23]['read_vat'], $rows[23]['calc_vat']]);

        // O ecrã recebe o IEC de cada linha (já incluído no valor).
        $user = User::where('company_id', $this->company->id)->first();
        $show = $this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/ocr/invoices/{$inv->id}")->assertOk();
        $this->assertSame([1.84, 1.03, null], collect($show->json('data.invoice.lines'))->pluck('iec')->all());
    }

    public function test_the_prompts_accept_zero_percent_vat_and_the_iec_column(): void
    {
        $svc = app(InvoiceOcrService::class);
        $this->assertStringContainsString('"taxaIva": 0|6|13|23', $svc->prompt());
        $this->assertStringContainsString('"ivaPorTaxa": [{"taxa": 0|6|13|23', $svc->prompt());
        $this->assertStringContainsString('taxaIva só pode ser 0 (isento', $svc->prompt());
        foreach ([$svc->prompt(), $svc->linesPrompt()] as $p) {
            $this->assertStringContainsString('COLUNA IEC SOMADA', $p);
            $this->assertStringContainsString('"valorIec": number', $p);
        }
        // Sem QR, a leitura aceita uma linha a 0 % (isento).
        $clean = $svc->sanitize($this->fullJson(['linhas' => [['item' => 'Grade devolvida', 'totalLinha' => 6.00, 'taxaIva' => 0]],
            'quadroIva' => [['codigo' => 'A', 'taxa' => 0, 'base' => 6.00, 'iva' => 0]]]));
        $this->assertSame(0, $clean['lines'][0]['vat_rate']);
        $this->assertSame([['code' => 'A', 'rate' => 0, 'base_cents' => 600, 'vat_cents' => 0]], $clean['vat_table']);
    }

    // ─────────────────────────────────────────── talões com IVA incluído e notas à mão

    /** Um talão (FS) com QR: as bases e o IVA por taxa e o total. */
    private function ticketQr(string $taxes, string $vat, string $total): string
    {
        return 'A:' . self::SUPPLIER . '*B:' . self::OWN . "*C:PT*D:FS*E:N*F:20261015*G:FS 0489/052982*H:ABCD1234-52982*I1:PT*{$taxes}*N:{$vat}*O:{$total}*Q:AbCd*R:0001";
    }

    private function ticketLines(array $lines, array $o = []): array
    {
        return $o + ['fornecedorNome' => 'Supermercado Teste', 'precosComIva' => true, 'notasManuscritas' => '', 'linhas' => $lines, 'guias' => [], 'quadroIva' => [], 'descontos' => []];
    }

    public function test_a_ticket_with_vat_included_is_converted_and_matches_the_vat_table(): void
    {
        // "2 X 1,19 = 2,38" e o quadro do IVA: 23 %, base 1,93, IVA 0,45, total 2,38.
        $this->bindFake($this->analysis([[$this->ticketQr('I7:1.93*I8:0.45', '0.45', '2.38'), 1]]), [$this->ticketLines([
            ['item' => 'Grão 200 g', 'quantidade' => 2, 'unidade' => 'UN', 'precoUnitario' => 1.19, 'totalLinha' => 2.38, 'taxaIva' => 23]])]);
        $inv = $this->runOcr();

        $this->assertSame('por_validar', $inv->status);
        $this->assertTrue($inv->prices_include_vat);
        $l = $inv->lines->first();
        $this->assertSame([193, 193, 238, 23], [$l->line_total_cents, $l->gross_total_cents, $l->vat_included_cents, $l->vat_rate]);
        $this->assertSame('0.965000', (string) $l->unit_price, '2 × 0,965 = 1,93');
        $this->assertSame('confere', $inv->check_status);
        $v = collect(app(OcrInvoiceRereadService::class)->vatByRate($inv)['rows'])->firstWhere('rate', 23);
        $this->assertEquals([1.93, 1.93, 0.45, 0.44, true], [$v['read_base'], $v['calc_base'], $v['read_vat'], $v['calc_vat'], $v['ok']]);
        // O PingWin calcula o IVA à linha (0,44): 2,37 €, e o acerto de 0,01 € (até 0,05 €) leva-o aos 2,38 €.
        $e = app(\App\Services\OcrInvoiceLaunchService::class)->estimate($inv, $inv->lines);
        $this->assertSame([237, 238, 1], [$e['computed_cents'], $e['target_cents'], $e['adjustment_cents']]);
    }

    public function test_a_ticket_with_two_rates_matches_the_bases_to_the_cent(): void
    {
        $qr = $this->ticketQr('I3:4.31*I4:0.26*I7:7.03*I8:1.62', '1.88', '13.22');
        $lines = [];
        foreach ([['Pão', 1.19, 6], ['Leite', 0.89, 6], ['Fruta', 2.49, 6], ['Detergente', 3.99, 23], ['Esponja', 1.49, 23], ['Guardanapos', 0.79, 23], ['Grão', 2.38, 23]] as [$item, $total, $rate]) {
            $lines[] = ['item' => $item, 'quantidade' => 1, 'unidade' => 'UN', 'precoUnitario' => $total, 'totalLinha' => $total, 'taxaIva' => $rate];
        }
        $this->bindFake($this->analysis([[$qr, 1]]), [$this->ticketLines($lines)]);
        $inv = $this->runOcr();

        $by = $inv->lines->sortBy('position')->values();
        $this->assertSame([113, 83, 235, 325, 121, 64, 193], $by->pluck('line_total_cents')->all(), 'os cêntimos que sobram vão para as linhas maiores');
        $this->assertSame([431, 703], [(int) $by->where('vat_rate', 6)->sum('line_total_cents'), (int) $by->where('vat_rate', 23)->sum('line_total_cents')]);
        $this->assertSame([119, 89, 249, 399, 149, 79, 238], $by->pluck('vat_included_cents')->all());
        $this->assertSame('confere', $inv->check_status);
    }

    public function test_a_normal_invoice_without_vat_included_does_not_change(): void
    {
        $this->bindFake($this->analysis([[self::QR_FT, 1]]), [['fornecedorNome' => 'X', 'precosComIva' => false, 'notasManuscritas' => '', 'linhas' => [
            ['item' => 'Artigo', 'quantidade' => 4, 'precoUnitario' => 25, 'totalLinha' => 100.00, 'taxaIva' => 23]], 'guias' => [], 'quadroIva' => [], 'descontos' => []]]);
        $inv = $this->runOcr();
        $l = $inv->lines->first();
        $this->assertFalse($inv->prices_include_vat);
        $this->assertSame([10000, null, '25.000000'], [$l->line_total_cents, $l->vat_included_cents, (string) $l->unit_price]);
        $this->assertSame('confere', $inv->check_status);
    }

    public function test_handwritten_notes_are_kept_and_fill_an_empty_accounting_note_only(): void
    {
        $this->bindFake($this->analysis([[self::QR_FT, 1]]), [['fornecedorNome' => 'X', 'precosComIva' => false, 'notasManuscritas' => 'Pago em dinheiro / Reembolso a colaborador',
            'linhas' => [['item' => 'Artigo', 'quantidade' => 1, 'precoUnitario' => 100, 'totalLinha' => 100.00, 'taxaIva' => 23]], 'guias' => [], 'quadroIva' => [], 'descontos' => []]]);
        $inv = $this->runOcr();
        $this->assertSame('Pago em dinheiro / Reembolso a colaborador', $inv->handwritten_notes);

        // Ligada a dois documentos do PingWin: o que não tem Nota recebe as notas; o que já tem fica como está.
        foreach (['DOC-A', 'DOC-B'] as $d) {
            DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->company->id, 'docheader_id' => $d, 'docconfig_id' => '1209', 'document' => "VFT {$d}",
                'doc_date' => '2026-10-17', 'total_cents' => 12300, 'docstatus_id' => '8002', 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Models\FinanceDocumentMark::create(['company_id' => $this->company->id, 'docheader_id' => 'DOC-B', 'note' => 'Nota já escrita']);
        $supplier = $inv->supplier_id;
        app(\App\Services\OcrPingwinLinkService::class)->confirm($inv->fresh(), ['DOC-A', 'DOC-B'], null, null);
        $notes = \App\Models\FinanceDocumentMark::where('company_id', $this->company->id)->pluck('note', 'docheader_id')->all();
        $this->assertSame(['DOC-A' => 'Pago em dinheiro / Reembolso a colaborador', 'DOC-B' => 'Nota já escrita'], [ 'DOC-A' => $notes['DOC-A'], 'DOC-B' => $notes['DOC-B']]);
        // Não tiram conclusões: nenhum pagamento registado e o fornecedor não muda.
        $this->assertSame(0, \App\Models\FinancePayment::count());
        $this->assertSame($supplier, $inv->fresh()->supplier_id);
        $this->assertStringContainsString('"notasManuscritas"', app(InvoiceOcrService::class)->linesPrompt());
        $this->assertStringContainsString('"precosComIva": true|false', app(InvoiceOcrService::class)->prompt());
    }
}
