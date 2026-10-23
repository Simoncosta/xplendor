<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\User;
use App\Services\Ai\AiFunctionSettings;
use App\Services\CompanyModuleService;
use App\Services\InvoiceOcrService;
use App\Services\Ocr\InvoiceDiscountAllocator;
use App\Services\OcrInvoiceLaunchService;
use App\Services\OcrInvoiceRereadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faturas: os descontos de promoção ligados ao artigo, o quadro "IVA por taxa", "Revalidar" e
 * "Reler do documento" (manter ou substituir as alterações manuais).
 *
 * Caso de referência: a fatura da Makro FAC 02202202601/009539 de 07/10/2026 (digitalizada, 3
 * páginas). O PDF não está no repositório: a leitura é SIMULADA (o JSON que a IA devolve para esta
 * fatura), com os mesmos descontos, o mesmo quadro do IVA e o mesmo QR; as linhas sem desconto
 * são de enchimento, com as somas por taxa da fatura. Leve Mais Pague Menos = 20,93 €; bases
 * 1,80 / 516,24 / 1 028,36 / 26,19; IVA 183,84; total 1 756,43.
 */
class InvoiceDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private const MAKRO_QR = 'A:502030712*B:514148497*C:PT*D:FT*E:N*F:20261007*G:FAC 02202202601/009539*H:JJ37M8RS-9539*I1:PT'
        . '*I2:1.80*I3:1028.36*I4:61.70*I5:26.19*I6:3.40*I7:516.24*I8:118.74*N:183.84*O:1756.43*Q:abcd*R:1234';

    private Company $yuko;
    private User $user;
    private User $root;
    private ?array $analysis = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['ai.providers.anthropic.key' => 'sk-ant-test', 'ai.retry_backoff_ms' => [1, 1], 'services.openai.ocr.check_tolerance_cents' => 2,
            'services.openai.ocr.text_min_chars' => 200]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->yuko = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Ferreira & Cambas, Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->yuko->id, 'restaurant');
        $xplendor = Company::create(['nipc' => '500100200', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
        $this->user = User::factory()->create(['company_id' => $this->yuko->id, 'role' => 'admin']);
        AiFunctionSettings::set('ocr_image', 'claude-opus-5-5', 'medium', $this->root);
        AiFunctionSettings::set('ocr_text', 'claude-opus-5-5', 'medium', $this->root);
        $test = $this;
        $this->app->instance(InvoiceOcrService::class, new class($test) extends InvoiceOcrService {
            public function __construct(private $test) {}

            protected function analyzeFile(string $bytes, string $mime, string $images): array
            {
                return $this->test->analysis();
            }
        });
        // Digitalizada (3 páginas, sem texto): QR lido localmente, linhas pela IA a partir das imagens.
        $this->analysis = ['ok' => true, 'kind' => 'pdf', 'pages' => 3, 'qr' => ['raw' => self::MAKRO_QR, 'page' => 1], 'text' => '', 'text_chars' => 0, 'images' => []];
    }

    public function analysis(): array
    {
        return $this->analysis ?? [];
    }

    // ───────────────────────────────────────────────────────────── a leitura simulada

    /** A resposta da IA para a fatura da Makro (linhas, quadro do IVA e bloco de descontos). */
    private function makro(array $override = []): array
    {
        $l = fn ($code, $item, $qty, $unit, $price, $total, $vat, $dd = null) => array_filter([
            'codigo' => $code, 'item' => $item, 'quantidade' => $qty, 'unidade' => $unit, 'precoUnitario' => $price, 'totalLinha' => $total,
            'codigoIva' => $vat, 'codigoPromocao' => $dd, 'descontoPct' => 0,
        ], fn ($v) => $v !== null);

        return array_replace([
            'fornecedorNome' => 'MAKRO CASH & CARRY PORTUGAL SA',
            'guias' => [],
            'linhas' => [
                $l('231007', 'ÓLEO 5LT STA FILOMENA', 3, 'UN', 9.99, 29.97, '5', '45238'),
                $l('118220', 'MC REBUCADOS MINI CAFE SC 1KG', 2, 'UN', 9.44, 18.88, '2', '41533'),
                $l('405112', 'QJ CHEVRE 180G PALHAIS', 4, 'UN', 2.66, 10.64, '4', '47454'),
                $l('300871', 'MC MIX FRUTOS VERM. 2.5KG', 5, 'UN', 14.15, 70.75, '4', '46336'),
                $l('512004', 'PÃO HOT DOG MAXI 320G BIMBO', 4, 'UN', 1.67, 6.68, '4', '43520'),
                $l('120001', 'AGUA C/GAS 25CL', 24, 'UN', 0.3125, 7.50, '2'),
                ['item' => '+ 0,13 TAXA IEC INCL. PREÇO U.V.', 'totalLinha' => 0.13, 'codigoIva' => '2'],
                $l('164846', '+ 164846 DEPOSITO 1 X 0.10 EUR', 18, 'UN', 0.10, 1.80, '0'),
                $l('120450', 'REFRIGERANTE COLA 33CL', 6, 'UN', 2.33, 13.98, '2'),
                ['item' => '+ 0,61 IEC BEB AÇÚCAR INCL. PUV', 'totalLinha' => 0.61, 'codigoIva' => '2'],
                $l('140990', 'WHISKY 1L', 20, 'UN', 23.92, 478.40, '2'),
                $l('210334', 'SALPIC CACHACO', 0.909, 'KG', 10.99, 9.99, '4'),
                $l('200010', 'ARROZ AGULHA 5KG', 10, 'UN', 5.49, 54.90, '4'),
                $l('260500', 'BACALHAU CRESCIDO', 25, 'KG', 35.6012, 890.03, '4'),
            ],
            'quadroIva' => [
                ['codigo' => '0', 'taxa' => 0, 'base' => 1.80, 'iva' => 0],
                ['codigo' => '2', 'taxa' => 23, 'base' => 516.24, 'iva' => 118.74],
                ['codigo' => '4', 'taxa' => 6, 'base' => 1028.36, 'iva' => 61.70],
                ['codigo' => '5', 'taxa' => 13, 'base' => 26.19, 'iva' => 3.40],
            ],
            'descontos' => [
                ['codigo' => '45238', 'descricao' => 'Leve Mais Pague Menos', 'valor' => 3.78, 'codigoIva' => '5'],
                ['codigo' => '41533', 'descricao' => 'Leve Mais Pague Menos', 'valor' => 2.52, 'codigoIva' => '2'],
                ['codigo' => '47454', 'descricao' => 'Leve Mais Pague Menos', 'valor' => 0.52, 'codigoIva' => '4'],
                ['codigo' => '46336', 'descricao' => 'Leve Mais Pague Menos', 'valor' => 13.15, 'codigoIva' => '4'],
                ['codigo' => '43520', 'descricao' => 'Leve Mais Pague Menos', 'valor' => 0.96, 'codigoIva' => '4'],
            ],
        ], $override);
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($json)]], 'usage' => ['input_tokens' => 9000, 'output_tokens' => 1500]])]);
    }

    private function readMakro(array $override = []): OcrInvoice
    {
        $this->fakeAi($this->makro($override));
        $inv = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => "ocr-invoices/{$this->yuko->id}/makro-009539.pdf",
            'image_mime' => 'application/pdf', 'status' => 'processing']);
        Storage::disk('local')->put($inv->image_path, '%PDF-1.4 digitalizada');
        app(InvoiceOcrService::class)->process($inv->id);

        return $inv->fresh(['lines', 'discounts', 'summary']);
    }

    private function line(OcrInvoice $inv, string $item): OcrInvoiceLine
    {
        return $inv->lines->first(fn ($l) => str_contains((string) $l->item, $item)) ?? $this->fail("linha {$item} não encontrada");
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->actingAs($this->user, 'sanctum')->json($method, "/api/v1/companies/{$this->yuko->id}/ocr/invoices{$path}", $data);
    }

    /** O corpo do PUT/revalidar com as linhas tal como o ecrã as tem. */
    private function body(OcrInvoice $inv, ?callable $edit = null): array
    {
        $lines = $this->api('GET', "/{$inv->id}")->json('data.invoice.lines');
        if ($edit) {
            $lines = $edit($lines);
        }

        return ['supplier_name' => $inv->supplier_name, 'number' => $inv->number, 'issue_date' => $inv->issue_date?->toDateString(),
            'lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['id', 'supplier_code', 'item', 'quantity', 'unit', 'unit_price', 'discount_pct',
                'line_total', 'vat_rate', 'promo_code'])), $lines),
            'summary' => ['total' => 1756.43]];
    }

    // ───────────────────────────────────────────────────────────── a fatura de referência

    public function test_the_makro_reference_invoice_validates_without_adjustment_and_each_discount_stays_on_its_article(): void
    {
        $inv = $this->readMakro();

        $this->assertSame(['por_validar', InvoiceOcrService::CHECK_OK, 'linhas-v6'], [$inv->status, $inv->check_status, $inv->prompt_version]);
        // As duas linhas "+ … INCL." são informativas: ficam de fora (e registadas).
        $this->assertCount(12, $inv->lines);
        $this->assertSame(['+ 0,13 TAXA IEC INCL. PREÇO U.V.', '+ 0,61 IEC BEB AÇÚCAR INCL. PUV'], $inv->ignored_lines);
        $this->assertFalse($inv->lines->contains(fn ($l) => str_contains((string) $l->item, 'IEC')));
        // O depósito do vasilhame é uma linha (IVA 0); a linha ao peso fica em kg.
        $dep = $this->line($inv, 'DEPOSITO');
        $this->assertSame([18.0, 180, 0, '0'], [(float) $dep->quantity, $dep->line_total_cents, $dep->vat_rate, $dep->vat_code]);
        $salpic = $this->line($inv, 'SALPIC');
        $this->assertSame([0.909, 'KG', 999, 6], [(float) $salpic->quantity, $salpic->unit, $salpic->line_total_cents, $salpic->vat_rate]);

        // Os 5 descontos, cada um no artigo com o mesmo código, sem avisos.
        $this->assertSame(5, $inv->discounts->where('status', 'atribuido')->count());
        $this->assertSame(2093, $inv->discounts->sum('amount_cents'));
        $this->assertSame([null], $inv->discounts->pluck('warning')->unique()->values()->all());
        foreach ([['ÓLEO', 2997, 378, 2619, 13], ['REBUCADOS', 1888, 252, 1636, 23], ['CHEVRE', 1064, 52, 1012, 6], ['FRUTOS VERM', 7075, 1315, 5760, 6], ['HOT DOG', 668, 96, 572, 6]] as [$item, $gross, $disc, $net, $rate]) {
            $l = $this->line($inv, $item);
            $this->assertSame([$gross, $disc, $net, $rate, 'Leve Mais Pague Menos'], [$l->gross_total_cents, $l->discount_cents, $l->line_total_cents, $l->vat_rate, $l->discount_label], $item);
            $this->assertSame($l->promo_code, $l->discount_code);
            $this->assertNull($l->discount_pct ?: null, 'nunca uma percentagem calculada');
        }
        $oleo = $this->line($inv, 'ÓLEO');
        $alloc = $inv->discounts->firstWhere('code', '45238')->allocations;
        $this->assertSame([['line_id' => $oleo->id, 'cents' => 378]], $alloc);

        // O quadro do IVA lido, com os códigos da fatura (nunca fixos no código).
        $this->assertSame([['code' => '0', 'rate' => 0], ['code' => '2', 'rate' => 23], ['code' => '4', 'rate' => 6], ['code' => '5', 'rate' => 13]],
            array_map(fn ($r) => ['code' => $r['code'], 'rate' => $r['rate']], $inv->vat_table));

        // IVA por taxa: lido (QR) = calculado (linhas líquidas), em base e em IVA.
        $vat = app(OcrInvoiceRereadService::class)->vatByRate($inv);
        $this->assertTrue($vat['ok'], json_encode($vat));
        $this->assertSame('qr', $vat['source']);
        $this->assertSame([[0, ['0'], 1.80, 0.0], [6, ['4'], 1028.36, 61.70], [13, ['5'], 26.19, 3.40], [23, ['2'], 516.24, 118.74]],
            array_map(fn ($r) => [$r['rate'], $r['codes'], (float) $r['calc_base'], (float) $r['calc_vat']], $vat['rows']));
        $this->assertSame([0.0], array_values(array_unique(array_map(fn ($r) => (float) $r['diff_base'] + (float) $r['diff_vat'], $vat['rows']))));

        // Validação: total 1 756,43 sem acerto; descontos e "Quantidades e preços" a verde.
        $est = app(OcrInvoiceLaunchService::class)->estimate($inv, $inv->lines);
        $this->assertSame([157259, 18384, 175643, 0], [$est['net_cents'], $est['tax_cents'], $est['computed_cents'], $est['adjustment_cents']]);
        $guards = collect(app(OcrInvoiceLaunchService::class)->preview($inv)['guards'])->keyBy('key');
        foreach (['conferencia', 'iva', 'valores', 'descontos', 'acerto'] as $k) {
            $this->assertTrue($guards[$k]['ok'], "{$k}: " . $guards[$k]['message']);
        }

        // O ecrã: bruto, desconto com o código e líquido; o custo do artigo é o líquido.
        $res = $this->api('GET', "/{$inv->id}")->assertOk();
        $row = collect($res->json('data.invoice.lines'))->firstWhere('item', 'ÓLEO 5LT STA FILOMENA');
        $this->assertSame([29.97, 3.78, '45238', 'Leve Mais Pague Menos', 26.19, '5'], [$row['line_total'], $row['discount'], $row['discount_code'], $row['discount_label'], $row['net_total'], $row['vat_code']]);
        $this->assertSame(1572.59, $res->json('data.invoice.lines_total'));
        $this->assertTrue($res->json('data.invoice.vat_by_rate.ok'));
        $this->assertCount(5, $res->json('data.invoice.discounts'));
    }

    // ───────────────────────────────────────────────────────────── repartir, sem par, IVA diferente

    public function test_one_code_on_several_articles_is_split_by_value_with_the_remaining_cents_on_the_largest(): void
    {
        $r = InvoiceDiscountAllocator::allocate([
            ['promo_code' => '45238', 'vat_code' => '4', 'gross_total_cents' => 1000, 'item' => 'A'],
            ['promo_code' => '045238', 'vat_code' => '4', 'gross_total_cents' => 2000, 'item' => 'B'],  // zeros à esquerda: o mesmo código
            ['promo_code' => '45238', 'vat_code' => '4', 'gross_total_cents' => 1000, 'item' => 'C'],
            ['promo_code' => null, 'vat_code' => '4', 'gross_total_cents' => 500, 'item' => 'D'],
        ], [['code' => '45238', 'label' => 'Leve Mais Pague Menos', 'amount_cents' => 101, 'vat_code' => '4']]);

        // 101 × 1/4 = 25, 101 × 2/4 = 50, 25 → resto 1 cêntimo no maior (B).
        $this->assertSame([25, 51, 25, 0], array_column($r['lines'], 'discount_cents'));
        $this->assertSame([975, 1949, 975, 500], array_column($r['lines'], 'line_total_cents'));
        $this->assertSame('atribuido', $r['discounts'][0]['status']);
        $this->assertSame([['line' => 0, 'cents' => 25], ['line' => 1, 'cents' => 51], ['line' => 2, 'cents' => 25]], $r['discounts'][0]['allocations']);
        $this->assertSame([null, 'Leve Mais Pague Menos'], [$r['lines'][3]['discount_label'], $r['lines'][1]['discount_label']]);

        // Pela leitura: dois artigos com o código 46336 → 13,15 € repartidos pelo valor.
        $inv = $this->readMakro(['linhas' => array_merge($this->makro()['linhas'], [
            ['codigo' => '300872', 'item' => 'MC MIX FRUTOS VERM. 2.5KG (2)', 'quantidade' => 1, 'unidade' => 'UN', 'precoUnitario' => 14.15, 'totalLinha' => 14.15, 'codigoIva' => '4', 'codigoPromocao' => '46336'],
        ])]);
        $a = $this->line($inv, 'VERM. 2.5KG');
        $b = $this->line($inv, 'VERM. 2.5KG (2)');
        // 1315 × 7075/8490 = 1095,83 → 1095; 1315 × 1415/8490 = 219,17 → 219; resto 1 no maior.
        $this->assertSame([1096, 219], [$a->discount_cents, $b->discount_cents]);
        $this->assertSame(1315, $a->discount_cents + $b->discount_cents);
    }

    public function test_a_discount_without_an_article_is_unassigned_and_blocks_until_the_code_is_fixed_and_revalidated(): void
    {
        $lines = $this->makro()['linhas'];
        unset($lines[4]['codigoPromocao']); // o PÃO HOT DOG sem o código 43520 (a IA não o leu)
        $inv = $this->readMakro(['linhas' => array_values($lines)]);

        $d = $inv->discounts->firstWhere('code', '43520');
        $this->assertSame(['por_atribuir', []], [$d->status, $d->allocations]);
        $this->assertSame(InvoiceOcrService::CHECK_MISMATCH, $inv->check_status, 'sem o desconto, a base dos 6 % passa em 0,96 €');
        $guards = collect(app(OcrInvoiceLaunchService::class)->preview($inv)['guards'])->keyBy('key');
        $this->assertFalse($guards['descontos']['ok']);
        $this->assertStringContainsString('Desconto por atribuir: Leve Mais Pague Menos 43520 (0,96 €)', $guards['descontos']['message']);
        $this->assertTrue($guards['valores']['ok'], '"Quantidades e preços" não conta o desconto (é do artigo, não é uma linha)');
        // O IVA por taxa aponta a diferença dos 6 % e a linha que a explica (o valor do desconto que falta).
        $row = collect(app(OcrInvoiceRereadService::class)->vatByRate($inv)['rows'])->firstWhere('rate', 6);
        $this->assertSame([false, 0.96, ['43520']], [$row['ok'], $row['diff_base'], $row['explained_by_discounts']]);

        // Corrigir o código na linha e "Revalidar": fica no artigo, a conferência passa, sem mudar o estado.
        $pao = $this->line($inv, 'HOT DOG');
        $this->api('POST', "/{$inv->id}/revalidate", $this->body($inv, fn ($ls) => array_map(fn ($l) => $l['id'] === $pao->id ? ['promo_code' => '43520'] + $l : $l, $ls)))
            ->assertOk()->assertJsonPath('data.invoice.status', 'por_validar')
            ->assertJsonPath('data.invoice.vat_by_rate.ok', true);
        $inv->refresh();
        $this->assertSame(['atribuido', InvoiceOcrService::CHECK_OK, 96, 572], [$inv->discounts->firstWhere('code', '43520')->status, $inv->check_status,
            $pao->fresh()->discount_cents, $pao->fresh()->line_total_cents]);
    }

    public function test_a_discount_whose_vat_is_not_the_article_vat_warns(): void
    {
        $makro = $this->makro();
        $makro['descontos'][0]['codigoIva'] = '4'; // o desconto do ÓLEO (IVA código 5) lido com o código 4
        $inv = $this->readMakro(['descontos' => $makro['descontos']]);

        $d = $inv->discounts->firstWhere('code', '45238');
        $this->assertSame('atribuido', $d->status, 'avisa, mas fica no artigo');
        $this->assertSame('O IVA do desconto 45238 (código 4) não é o do artigo ÓLEO 5LT STA FILOMENA (código 5).', $d->warning);
        $this->assertSame([$d->warning], app(OcrInvoiceLaunchService::class)->preview($inv)['discount_warnings']->all());
        // Sem códigos de IVA, compara a taxa.
        $r = InvoiceDiscountAllocator::allocate([['promo_code' => '1', 'vat_rate' => 23, 'gross_total_cents' => 100, 'item' => 'X']],
            [['code' => '1', 'amount_cents' => 10, 'vat_rate' => 6]]);
        $this->assertSame('O IVA do desconto 1 (6 %) não é o do artigo X (23 %).', $r['discounts'][0]['warning']);
    }

    // ───────────────────────────────────────────────────────────── revalidar

    public function test_revalidate_reruns_the_checks_on_the_current_lines(): void
    {
        $inv = $this->readMakro();
        $oleo = $this->line($inv, 'ÓLEO');
        // Uma correção à mão que estraga a taxa de 13 % (valor 39,97): Revalidar mostra-o; o líquido segue o bruto.
        $res = $this->api('POST', "/{$inv->id}/revalidate", $this->body($inv, fn ($ls) => array_map(fn ($l) => $l['id'] === $oleo->id ? ['line_total' => 39.97] + $l : $l, $ls)))->assertOk();
        $this->assertSame([3997, 378, 3619], [$oleo->fresh()->gross_total_cents, $oleo->fresh()->discount_cents, $oleo->fresh()->line_total_cents]);
        $this->assertSame(InvoiceOcrService::CHECK_MISMATCH, $inv->fresh()->check_status);
        $row = collect($res->json('data.invoice.vat_by_rate.rows'))->firstWhere('rate', 13);
        $this->assertSame([false, 10.0], [$row['ok'], (float) $row['diff_base']]);
        $this->assertSame([$oleo->id], $row['explained_by'], 'a linha mudada à mão em 10,00 € explica a diferença');
        $this->assertSame('por_validar', $inv->fresh()->status);

        // Sem corpo: revalida o que está gravado (a correção desfeita volta a conferir).
        OcrInvoiceLine::whereKey($oleo->id)->update(['gross_total_cents' => 2997]);
        $res = $this->api('POST', "/{$inv->id}/revalidate")->assertOk();
        $this->assertTrue($res->json('data.invoice.vat_by_rate.ok'), json_encode($res->json('data.invoice.vat_by_rate')));
        $this->assertSame([InvoiceOcrService::CHECK_OK, 2619], [$inv->fresh()->check_status, $oleo->fresh()->line_total_cents]);
    }

    // ───────────────────────────────────────────────────────────── reler

    public function test_reread_without_manual_changes_says_so_and_replaces(): void
    {
        $inv = $this->readMakro();
        $this->api('GET', "/{$inv->id}/manual-changes")->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.changes', []);
        Bus::fake([ProcessInvoiceOcrJob::class]);
        $this->api('POST', "/{$inv->id}/reprocess", ['keep_manual' => true])->assertStatus(202)->assertJsonPath('data.kept_changes', 0);
        $this->assertNull($inv->fresh()->reread_keep);
        Bus::assertDispatched(ProcessInvoiceOcrJob::class);
    }

    public function test_reread_keeping_the_manual_changes_reapplies_them_to_the_new_reading(): void
    {
        $inv = $this->readMakro();
        $salpic = $this->line($inv, 'SALPIC');
        $arroz = $this->line($inv, 'ARROZ');
        $this->api('PUT', "/{$inv->id}", $this->body($inv, function ($ls) use ($salpic, $arroz) {
            $ls = array_map(fn ($l) => $l['id'] === $salpic->id ? ['quantity' => 0.91, 'item' => 'SALPICÃO CACHAÇO'] + $l : $l, $ls);
            $ls = array_values(array_filter($ls, fn ($l) => $l['id'] !== $arroz->id));
            $ls[] = ['item' => 'PORTES', 'quantity' => 1, 'unit_price' => 5, 'line_total' => 5, 'vat_rate' => 23];

            return $ls;
        }))->assertOk();
        $this->assertSame('validada', $inv->fresh()->status);

        $mc = $this->api('GET', "/{$inv->id}/manual-changes")->assertOk()->json('data');
        $this->assertSame(['alterada', 'apagada', 'acrescentada'], array_column($mc['changes'], 'type'));
        $this->assertSame(['item', 'quantity'], array_column($mc['changes'][0]['fields'], 'field'));
        $this->assertSame([0.909, 0.91], [$mc['changes'][0]['fields'][1]['read'], $mc['changes'][0]['fields'][1]['current']]);

        Bus::fake([ProcessInvoiceOcrJob::class]);
        $this->api('POST', "/{$inv->id}/reprocess", ['keep_manual' => true])->assertStatus(202)->assertJsonPath('data.kept_changes', 3);
        $this->fakeAi($this->makro());
        app(InvoiceOcrService::class)->process($inv->id);

        $inv = $inv->fresh(['lines', 'discounts']);
        $this->assertSame('por_validar', $inv->status);
        $this->assertNull($inv->reread_keep, 'todas as alterações encontraram a sua linha');
        $this->assertSame([0.91, 'SALPICÃO CACHAÇO'], [(float) $this->line($inv, 'SALPIC')->quantity, $this->line($inv, 'SALPIC')->item]);
        $this->assertFalse($inv->lines->contains(fn ($l) => str_contains((string) $l->item, 'ARROZ')));
        $this->assertSame(500, $this->line($inv, 'PORTES')->line_total_cents);
        $this->assertSame(5, $inv->discounts->where('status', 'atribuido')->count(), 'e os descontos voltam a ficar nos artigos');
    }

    public function test_reread_replacing_the_manual_changes_returns_to_the_reading(): void
    {
        $inv = $this->readMakro();
        $salpic = $this->line($inv, 'SALPIC');
        $this->api('PUT', "/{$inv->id}", $this->body($inv, fn ($ls) => array_map(fn ($l) => $l['id'] === $salpic->id ? ['quantity' => 0.91] + $l : $l, $ls)))->assertOk();
        Bus::fake([ProcessInvoiceOcrJob::class]);
        $this->api('POST', "/{$inv->id}/reprocess", ['keep_manual' => false])->assertStatus(202)->assertJsonPath('data.kept_changes', 0);
        $this->fakeAi($this->makro());
        app(InvoiceOcrService::class)->process($inv->id);

        $inv = $inv->fresh('lines');
        $this->assertSame(0.909, (float) $this->line($inv, 'SALPIC')->quantity);
        $this->api('GET', "/{$inv->id}/manual-changes")->assertJsonPath('data.changes', []);
    }

    // ───────────────────────────────────────────────────────────── ficar no ecrã

    public function test_validate_and_save_answers_with_the_invoice_and_the_next_one_to_launch(): void
    {
        $older = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => 'a.pdf', 'status' => 'por_validar', 'link_status' => 'nao_lancada']);
        $launched = OcrInvoice::create(['company_id' => $this->yuko->id, 'image_path' => 'b.pdf', 'status' => 'validada', 'link_status' => 'lancada']);
        $inv = $this->readMakro();
        $this->assertGreaterThan($launched->id, $inv->id);

        $res = $this->api('PUT', "/{$inv->id}", $this->body($inv))->assertOk();
        $this->assertSame('validada', $res->json('data.invoice.status'));
        $this->assertSame($older->id, $res->json('data.invoice.next_to_launch_id'), 'a seguinte por lançar (a já lançada fica de fora)');
        $this->assertSame(5, collect($res->json('data.invoice.lines'))->where('discount', '>', 0)->count(), 'os descontos ficam depois de guardar');
        $this->assertTrue($res->json('data.invoice.vat_by_rate.ok'));
    }
}
