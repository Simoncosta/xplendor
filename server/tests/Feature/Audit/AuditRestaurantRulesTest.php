<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditFindingData;
use App\Audit\AuditRegistry;
use App\Audit\AuditRunService;
use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\DocumentoDataSerieRule;
use App\Audit\Rules\Restaurant\DuplicadosRule;
use App\Audit\Rules\Restaurant\FamiliaSuspeitaRule;
use App\Audit\Rules\Restaurant\FaturaSemLinhasRule;
use App\Audit\Rules\Restaurant\Fichas\ComponenteInativoRule;
use App\Audit\Rules\Restaurant\Fichas\ComponenteSemCustoRule;
use App\Audit\Rules\Restaurant\Fichas\LojaSemFichaRule;
use App\Audit\Rules\Restaurant\Fichas\ParecidoCompradoRule;
use App\Audit\Rules\Restaurant\Fichas\PorConfirmarRule;
use App\Audit\Rules\Restaurant\Fichas\VersaoVaziaRule;
use App\Audit\Rules\Restaurant\Fichas\VersoesMesmaDataRule;
use App\Audit\Rules\Restaurant\IvaVendaRule;
use App\Audit\Rules\Restaurant\PrecoCompraRule;
use App\Audit\Rules\Restaurant\Units\UnidadeGlobalIncoerenteRule;
use App\Services\Restaurant\Units\UnitCatalog;
use App\Audit\Rules\Restaurant\UnidadeSemConversaoRule;
use App\Audit\Rules\Restaurant\UnidadeTrocadaRule;
use App\Audit\Rules\Restaurant\VendidoSemFichaRule;
use App\Models\AuditFinding;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Models\PingwinUnit;
use App\Models\ProductUnitConversion;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 2 — as regras da restauração, cada uma com um caso positivo e um negativo, com os números
 * do spike REST-0: o IVA do Pedras Limão (0% desde 19/06), a linha de 96 × 13,34 € (1280,64 €),
 * as maçãs Golden, a BOVFT/522, a Coca Cola 0 e o Molho. Só leem o espelho.
 */
class AuditRestaurantRulesTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;
    private int $c;
    private int $loc;
    private int $lineSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 16:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->c = $this->company->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->loc = PingwinLocation::create(['company_id' => $this->c, 'winrest_store_id' => 'S1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10'])->id;
        foreach ([['11001', 'Unidade', null, 1], ['11002', 'Quilograma', null, 1], ['11003', 'Litro', null, 1], ['GRD24', 'Grade 24', '11001', 24],
            ['CX24', 'Caixa 24 Unidades', '11001', 1], ['CX6', 'Caixa 6 Unidades', '11001', 1]] as [$id, $d, $p, $v]) {
            PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'unit_value' => $v, 'is_active' => true]);
        }
    }

    /** @return list<AuditFindingData> */
    private function findings(string $ruleClass): array
    {
        return iterator_to_array(app($ruleClass)->run($this->c, new AuditScope($this->c, 'restauracao', 'central')), false);
    }

    /** Vendas de um dia, com o IVA. */
    private function sold(string $pid, string $date, float $qty, int $net, int $tax, string $name = 'Pedras Limao'): void
    {
        DB::table('pingwin_item_sales_daily')->insert([
            'company_id' => $this->c, 'location_id' => $this->loc, 'business_date' => $date, 'product_pingwin_id' => $pid,
            'product_code' => "P{$pid}", 'product_name' => $name, 'family_path' => 'Família \\ Bebidas \\ Aguas',
            'quantity' => $qty, 'net_cents' => $net, 'tax_cents' => $tax, 'gross_cents' => $net + $tax, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Uma linha de compra (fatura fechada) com unidade, preço e documento. */
    private function bought(string $pid, string $date, float $qnt, string $unitId, string $unitDesc, float $unitValue, float $price, int $totalCents, string $doc = '', string $supplier = 'SUPER BOCK GROUP'): void
    {
        $n = ++$this->lineSeq;
        $doc = $doc ?: "VFT BOVFT/{$n}";
        DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $this->c, 'docheader_id' => "H{$n}", 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor', 'document' => $doc,
            'doc_date' => $date, 'docstatus_id' => '8002', 'entity_name' => $supplier, 'entity_pingwin_id' => 'SB', 'tax_number' => '500000000',
            'total_cents' => $totalCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pingwin_supplier_document_lines')->insert([
            'company_id' => $this->c, 'docheader_id' => "H{$n}", 'line_number' => 1, 'product_pingwin_id' => $pid, 'description' => "Linha {$pid}",
            'qnt' => $qnt, 'unit_desc' => $unitDesc, 'price' => $price, 'total_cents' => $totalCents,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qnt * $unitValue, 'unit_id' => $unitId, 'unit_value' => $unitValue]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function doc(string $docheader, string $document, string $date, string $status = '8002', string $config = '1209', ?string $linesStatus = null): void
    {
        DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $this->c, 'docheader_id' => $docheader, 'docconfig_id' => $config, 'doctype' => 'Fatura de fornecedor', 'document' => $document,
            'doc_date' => $date, 'docstatus_id' => $status, 'docstatus_description' => ['8002' => 'Fechado', '8003' => 'Anulado', '8004' => 'Edição'][$status] ?? 'Aberto',
            'entity_name' => 'Victor Ferreira & Torres', 'entity_pingwin_id' => 'VF', 'total_cents' => 140737, 'lines_status' => $linesStatus,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 1. IVA ───────────────────────────────────────────────────────────────

    public function test_vat_at_zero_since_19_june_like_pedras_limao(): void
    {
        $this->catalog($this->c, 'PL', ['description' => 'Pedras Limao']);
        for ($d = 0; $d < 18; $d++) {
            $this->sold('PL', date('Y-m-d', strtotime("2026-06-01 +{$d} days")), 2, 500, 115);   // 23%
        }
        for ($d = 0; $d < 100; $d++) {
            $this->sold('PL', date('Y-m-d', strtotime("2026-06-19 +{$d} days")), 4, 1000, 0);    // 0% desde 19/06
        }
        $this->catalog($this->c, 'OK', ['description' => 'Agua']);
        for ($d = 0; $d < 30; $d++) {
            $this->sold('OK', date('Y-m-d', strtotime("2026-09-01 +{$d} days")), 3, 600, 138, 'Agua');   // sempre 23%: nada
        }

        $f = $this->findings(IvaVendaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('PL', $f[0]->entityId);
        $this->assertSame('Pedras Limao: vendido com IVA a 0%', $f[0]->title);
        $this->assertSame('Desde 19/06/2026, foi vendido com IVA a 0%. Até 26/09/2026 vendeu 400 unidades, num total de 1 000,00 € sem IVA. Antes era vendido a 23%.', $f[0]->seen);
        $this->assertStringContainsString('Confirme a taxa certa com o contabilista', $f[0]->suggestion);
        $this->assertStringContainsString('ignore com esse motivo', $f[0]->suggestion);
        $this->assertStringStartsWith('187,00 € de IVA, se a taxa certa for 23%', $f[0]->stake);
        $this->assertSame(100 * 187, $f[0]->impactCents);   // 23/123 de 10,00 € por dia, 100 dias
        $this->assertSame('/restauracao/artigos/PL', $f[0]->fixUrl);
    }

    public function test_vat_with_two_rates_in_90_days(): void
    {
        $this->catalog($this->c, 'MIX', ['description' => 'Coroa D\'Ouro']);
        $this->sold('MIX', '2026-09-01', 1, 1000, 130, 'Coroa D\'Ouro');   // 13%
        $this->sold('MIX', '2026-09-02', 1, 1000, 230, 'Coroa D\'Ouro');   // 23%
        $f = $this->findings(IvaVendaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame("Coroa D'Ouro: vendido com 2 taxas de IVA diferentes", $f[0]->title);
        $this->assertSame('Nos últimos 90 dias foi vendido com IVA a 13% em 1 dia e a 23% em 1 dia.', $f[0]->seen);
        $this->assertSame('13,23', $f[0]->signature);
    }

    // ── 2. Preço de compra ───────────────────────────────────────────────────

    private function pedras(): void
    {
        $this->catalog($this->c, 'PT', ['description' => 'PEDRAS TR 0.25X0.24 GR', 'forpurchase' => true, 'forsale' => false, 'base_unit_id' => '11001']);
        $this->bought('PT', '2026-09-03', 2, 'GRD24', 'Grade 24', 24, 11.94, 2388);
        $this->bought('PT', '2026-09-03', 2, 'GRD24', 'Grade 24', 24, 11.94, 2388);
        $this->bought('PT', '2026-09-10', 2, 'GRD24', 'Grade 24', 24, 13.34, 2668);
        $this->bought('PT', '2026-09-10', 2, 'GRD24', 'Grade 24', 24, 13.34, 2668);
        $this->bought('PT', '2026-09-17', 4, 'GRD24', 'Grade 24', 24, 13.34, 5336);
        $this->bought('PT', '2026-09-24', 96, '11001', 'Unidade', 1, 0.58, 5395);
        $this->bought('PT', '2026-10-01', 96, '11001', 'Unidade', 1, 13.34, 128064, 'VFT BOVFT/1044');
        $this->bought('PT', '2026-10-08', 48, '11001', 'Unidade', 1, 0.55, 2640);
        $this->bought('PT', '2026-10-08', 48, '11001', 'Unidade', 1, 0.55, 2640);
    }

    public function test_pedras_96_units_at_13_34_is_a_probable_swapped_unit_with_the_impact_to_confirm(): void
    {
        $this->pedras();
        $this->assertSame([], $this->findings(PrecoCompraRule::class));   // 24× é o tamanho de uma Grade 24: não é "preço fora do normal"
        $f = $this->findings(UnidadeTrocadaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('VFT BOVFT/1044: PEDRAS TR 0.25X0.24 GR', $f[0]->entityLabel);
        $this->assertSame('PEDRAS TR 0.25X0.24 GR: unidade da compra provavelmente trocada', $f[0]->title);
        $this->assertSame('Na BOVFT/1044 de 01/10/2026, 96 «Unidade» a 13,34 € cada (1 280,64 €). Nas últimas 9 compras pagou, em média, 0,56 € por unidade: desta vez foi 24 vezes mais. 24 é o número de unidades de uma «Grade 24».', $f[0]->seen);
        $this->assertSame('A confirmar: só se sabe depois de ver a fatura em papel. Se o preço certo for o habitual, a linha está 1 227,28 € acima do que devia.', $f[0]->stake);
        $this->assertNull($f[0]->impactCents);                        // não entra na soma do Pareto
        $this->assertSame(122728, $f[0]->impactPendingCents);         // |1280,64 € − 96 × 0,5558 €|
        $this->assertSame(['type' => 'guiao', 'product_id' => 'PT', 'unit_key' => '11001', 'description' => 'PEDRAS TR 0.25X0.24 GR'], $f[0]->fixAction);
        $this->assertStringContainsString('indique em «Corrigir» quantas unidades tem a embalagem', $f[0]->suggestion);
        $this->assertContains(['fornecedor', 'SB'], $f[0]->refs);
    }

    public function test_swapped_unit_counts_the_difference_when_the_ocr_invoice_does_not_match(): void
    {
        $this->pedras();
        $doc = (string) DB::table('pingwin_supplier_documents')->where('company_id', $this->c)->where('document', 'VFT BOVFT/1044')->value('docheader_id');
        $ocr = DB::table('ocr_invoices')->insertGetId(['company_id' => $this->c, 'image_path' => 'ocr/bovft-1044.pdf', 'status' => 'validada', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ocr_invoice_summary')->insert(['ocr_invoice_id' => $ocr, 'company_id' => $this->c, 'total_cents' => 6564, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ocr_invoice_pingwin_links')->insert(['company_id' => $this->c, 'ocr_invoice_id' => $ocr, 'docheader_id' => $doc, 'method' => 'auto', 'created_at' => now(), 'updated_at' => now()]);

        $f = $this->findings(UnidadeTrocadaRule::class);
        $this->assertSame(128064 - 6564, $f[0]->impactCents);       // o PingWin tem 1280,64 €, a fatura 65,64 €: a diferença conta
        $this->assertNull($f[0]->impactPendingCents);
        $this->assertSame('1 215,00 €: a diferença entre o total desta fatura no PingWin (1 280,64 €) e o total lido pelo OCR na fatura em papel (65,64 €).', $f[0]->stake);
    }

    public function test_price_outlier_that_is_not_a_pack_size(): void
    {
        $this->catalog($this->c, 'AZ', ['description' => 'AZEITE', 'base_unit_id' => '11003']);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22'] as $d) {
            $this->bought('AZ', $d, 5, '11003', 'Litro', 1, 6.0, 3000);
        }
        $this->bought('AZ', '2026-10-01', 5, '11003', 'Litro', 1, 48.0, 24000);   // 8×: não é o tamanho de uma embalagem
        $this->assertSame([], $this->findings(UnidadeTrocadaRule::class));
        $f = $this->findings(PrecoCompraRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('AZEITE: comprado muito acima do preço habitual', $f[0]->title);
        $this->assertStringContainsString('pagou, em média, 6,00 € por litro: desta vez foi 8 vezes mais.', $f[0]->seen);
        $this->assertSame('210,00 €: a diferença entre o total da linha (240,00 €) e o que estas 5 «Litro» custariam ao preço habitual (30,00 €).', $f[0]->stake);
        $this->assertSame(21000, $f[0]->impactCents);                // 240 € − 5 × 6 €: conta no Pareto
        // O tamanho de embalagem escrito no nome também explica a razão (vai para "Provável unidade trocada").
        $this->assertSame([80.0], array_column(\App\Audit\Rules\Restaurant\PriceOutliers::namePacks('VOLUPIS CAPS NESPR (80UN)'), 'factor'));
        $this->assertSame([], \App\Audit\Rules\Restaurant\PriceOutliers::namePacks('VINHO 2019'));
        $this->assertSame(80.0, \App\Audit\Rules\Restaurant\PriceOutliers::packFor(1 / 84.2, \App\Audit\Rules\Restaurant\PriceOutliers::namePacks('VOLUPIS CAPS NESPR (80UN)'))['factor']);
        $this->assertStringStartsWith('/restauracao/faturas?tab=pingwin&doc=', $f[0]->fixUrl);
    }

    public function test_purchase_price_stable_or_generic_is_not_flagged(): void
    {
        $this->catalog($this->c, 'ST', ['description' => 'TOMATE', 'base_unit_id' => '11001']);
        foreach ([1.20, 1.25, 1.18, 1.30] as $i => $p) {
            $this->bought('ST', "2026-09-0" . ($i + 1), 10, '11001', 'Unidade', 1, $p, (int) round($p * 1000));
        }
        // Genérico (despesas): preços que variam por natureza, sem um preço normal.
        $this->catalog($this->c, 'DP', ['description' => 'DESPESAS OPERACIONAIS', 'base_unit_id' => '11001']);
        foreach ([10, 400, 25, 3000, 80] as $i => $v) {
            $this->bought('DP', "2026-09-1" . $i, 1, '11001', 'Unidade', 1, $v, $v * 100);
        }
        $this->assertSame([], $this->findings(PrecoCompraRule::class));
    }

    // ── 3 e 4. Unidades ──────────────────────────────────────────────────────

    public function test_unit_with_factor_one_and_a_pack_name_is_u9(): void
    {
        $this->catalog($this->c, 'CC', ['description' => 'COCA COLA 0.33', 'base_unit_id' => '11001']);
        $this->bought('CC', '2026-09-01', 2, 'CX24', 'Caixa 24 Unidades', 1, 12, 2400);
        $this->bought('CC', '2026-09-08', 3, 'GRD24', 'Grade 24', 24, 12, 3600);   // a Grade 24 vale 24: nada

        $f = collect($this->findings(UnidadeGlobalIncoerenteRule::class))->keyBy('entityId');
        $this->assertTrue($f->has('CX24'));
        $this->assertFalse($f->has('GRD24'));
        $this->assertSame('restauracao.unidade_fator', app(UnidadeGlobalIncoerenteRule::class)->code()); // o código da regra 3 (o histórico fica)
        $this->assertSame('Caixa 24 Unidades: conta 1 unidade em vez de 24 unidades', $f['CX24']->title);
        $this->assertSame(2300, $f['CX24']->impactCents);   // 24 € × (1 − 1/24)
        $this->assertSame('media', $f['CX24']->severity);
        $this->assertSame(['type' => 'unidade_global', 'unit_id' => 'CX24'], $f['CX24']->fixAction);
        // Regra 1: a «Grade 24» já existe com 24 na mesma base: reutiliza-se (não se cria outra).
        $this->assertStringStartsWith('Já existe a unidade certa, «Grade 24» (1 = 24 unidades).', $f['CX24']->suggestion);
        $this->assertSame('baixa', $f['CX6']->severity);   // sem artigos: latente
        $this->assertSame(20.0, UnitCatalog::nameQuantity('BARRIL 20LT', 'L'));
        $this->assertNull(UnitCatalog::nameQuantity('CX PLASTICA 60X40X32', 'un'));
    }

    public function test_purchase_unit_without_conversion(): void
    {
        $this->catalog($this->c, 'QJ', ['description' => 'QUEIJO FLAMENGO', 'base_unit_id' => '11002', 'forpurchase' => true]);
        $this->bought('QJ', '2026-09-01', 4, '11001', 'Unidade', 1, 9.5, 3800);
        $this->catalog($this->c, 'QK', ['description' => 'QUEIJO SERRA', 'base_unit_id' => '11002', 'forpurchase' => true]);
        $this->bought('QK', '2026-09-01', 2, '11001', 'Unidade', 1, 9.5, 1900);
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'QK', 'unit_key' => '11001', 'unit_label' => 'Unidade',
            'supplier_nif' => '', 'factor_to_base' => 2.5, 'source' => 'confirmado']);

        $f = $this->findings(UnidadeSemConversaoRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('QJ|11001', $f[0]->entityId);
        $this->assertSame(['type' => 'guiao', 'product_id' => 'QJ', 'unit_key' => '11001', 'description' => 'QUEIJO FLAMENGO'], $f[0]->fixAction);
        $this->assertSame('QUEIJO FLAMENGO: comprado em «Unidade», contado em quilograma', $f[0]->title);
        $this->assertStringContainsString('Veja numa fatura quantos quilogramas tem cada «Unidade».', $f[0]->suggestion);
    }

    // ── 5. Duplicados ────────────────────────────────────────────────────────

    public function test_golden_apples_are_probable_duplicates_and_the_main_article_is_local(): void
    {
        foreach (['100142' => 'PE MACA GOLDEN II 65 +V9', '100273' => 'MC MACA GOLDEN I 70/75', '100633' => 'MACA GOLDEN'] as $pid => $d) {
            $this->catalog($this->c, (string) $pid, ['code' => (string) $pid, 'description' => $d, 'forpurchase' => true, 'forsale' => false, 'purchaseunit' => 'Quilograma', 'base_unit_id' => '11002']);
            $this->bought((string) $pid, '2026-09-15', 10, '11002', 'Quilograma', 1, 1.6, 1600, '', 'Frutas');
        }
        $this->catalog($this->c, 'D1', ['description' => 'DESPESAS OPERACIONAIS ISENTO']);
        $this->catalog($this->c, 'D2', ['description' => 'DESPESAS OPERACIONAIS']);
        $this->bought('D1', '2026-09-15', 1, '11001', 'Unidade', 1, 500, 50000);   // despesas "1 de cada vez": não são produtos
        $this->bought('D2', '2026-09-16', 1, '11001', 'Unidade', 1, 300, 30000);

        $f = $this->findings(DuplicadosRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('GOLDEN MACA', $f[0]->entityId);
        $this->assertSame('MACA GOLDEN: 3 artigos que parecem o mesmo produto', $f[0]->title);
        $this->assertStringContainsString('100142 PE MACA GOLDEN II 65 +V9, 100273 MC MACA GOLDEN I 70/75, 100633 MACA GOLDEN.', $f[0]->seen);
        $this->assertSame(['100142', '100273', '100633'], $f[0]->fixAction['members']);
        $this->assertSame(4800, $f[0]->weightCents);

        // "Escolher artigo principal": fica só na XPLENDOR (article_groups).
        $run = app(AuditRunService::class)->start($this->c, 'restauracao', 'artigos');
        $finding = AuditFinding::where('rule', 'restauracao.duplicados')->firstOrFail();
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/companies/{$this->c}/restaurant/article-groups/main", ['finding_id' => $finding->id, 'product_id' => 'TOMATE'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/companies/{$this->c}/restaurant/article-groups/main", ['finding_id' => $finding->id, 'product_id' => '100633'])
            ->assertOk()->assertJsonPath('data.finding.evidence.group.main', '100633');
        $this->assertSame('100633', DB::table('article_groups')->where('company_id', $this->c)->where('key', 'GOLDEN MACA')->value('main_product_pingwin_id'));
        $this->assertSame(3, DB::table('article_group_members')->where('company_id', $this->c)->count());
        $this->assertSame('done', $run->fresh()->status);
    }

    // ── 6. Família ───────────────────────────────────────────────────────────

    public function test_suspicious_family_and_deposit_outside_cautions(): void
    {
        foreach (['T1' => 'TOMATE CHERRY', 'T2' => 'TOMATE PELADO', 'T3' => 'TOMATE RAMA', 'T4' => 'TOMATE SECO'] as $pid => $d) {
            $this->catalog($this->c, $pid, ['description' => $d, 'forpurchase' => true, 'forsale' => false, 'family' => 'Família \\ COMPRAS \\ COMIDAS \\ PRODUÇÃO']);
        }
        $this->catalog($this->c, 'TV', ['description' => 'TOMATE CUBOS GULOSOS 2.5KG', 'forpurchase' => true, 'forsale' => false, 'family' => 'Família \\ COMPRAS \\ BEBIDAS \\ VINHO']);
        $this->catalog($this->c, 'VF', ['description' => 'VASILHAME FANTA 0.35 GARRAFA', 'forpurchase' => true, 'forsale' => false, 'family' => 'Família \\ COMPRAS \\ DIVERSOS \\ DIVERSOS']);
        $this->catalog($this->c, 'VC', ['description' => 'VASILHAME COCA COLA 0.35 GARRAFA', 'forpurchase' => true, 'forsale' => false, 'family' => 'Família \\ OUTROS DEVEDORES/CREDORES \\ Cauções de vasilhames - fornecedores']);
        $this->catalog($this->c, 'BS', ['description' => 'BARRIL S. BOCK 30L', 'forpurchase' => true, 'forsale' => false, 'family' => 'Família \\ COMPRAS \\ BEBIDAS \\ CERVEJAS']);

        $f = collect($this->findings(FamiliaSuspeitaRule::class))->keyBy('entityId');
        $this->assertEqualsCanonicalizing(['TV', 'VF'], $f->keys()->all());
        $this->assertSame('Está em COMPRAS › BEBIDAS › VINHO. 4 dos outros 4 artigos começados por «TOMATE» estão em COMPRAS › COMIDAS.', $f['TV']->seen);
        $this->assertSame('VASILHAME FANTA 0.35 GARRAFA: vasilhame fora das cauções', $f['VF']->title);
        $this->assertTrue(FamiliaSuspeitaRule::isDeposit('GRADE SUPER BOCK C24 (33CL)'));
        $this->assertFalse(FamiliaSuspeitaRule::isDeposit('BARRIL S. BOCK 30L'));   // a cerveja em barril é produto
    }

    // ── 7. Vendido sem ficha ─────────────────────────────────────────────────

    public function test_sold_without_a_recipe(): void
    {
        $this->catalog($this->c, 'PR', ['description' => 'Prego no Pão', 'forsale' => true, 'forpurchase' => false]);
        $this->sale($this->c, $this->loc, 'PR', '2026-10-01', 12, 9600);
        $this->catalog($this->c, 'CF', ['description' => 'Café', 'forsale' => true, 'forpurchase' => false]);
        $this->sale($this->c, $this->loc, 'CF', '2026-10-01', 50, 4000);
        $this->persistBom($this->c, $this->bom('CF', [['00000000', 'BCF', '2026-01-01', ['S1' => true], [$this->bomLine('X1', 0.007)]]]));
        // Modificadores a 0 € (Gelo, Fria...) e tipos que não são Artigo (Complemento, Taxa de serviço...): de fora.
        $this->catalog($this->c, 'GL', ['description' => 'Gelo', 'forsale' => true, 'forpurchase' => false]);
        $this->sale($this->c, $this->loc, 'GL', '2026-10-01', 40, 0);
        $this->catalog($this->c, 'FR', ['description' => 'Fria', 'forsale' => true, 'forpurchase' => false, 'product_type' => 'Complemento']);
        $this->sale($this->c, $this->loc, 'FR', '2026-10-01', 30, 600);
        $this->catalog($this->c, 'TX', ['description' => 'Taxa de serviço', 'forsale' => true, 'forpurchase' => false, 'product_type' => 'Taxa de serviço']);
        $this->sale($this->c, $this->loc, 'TX', '2026-10-01', 3, 1500);

        $f = $this->findings(VendidoSemFichaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('PR', $f[0]->entityId);
        $this->assertSame('Prego no Pão: vendido sem ficha técnica', $f[0]->title);
        $this->assertSame('Nos últimos 90 dias vendeu 12 unidades (96,00 € sem IVA). Não tem ficha técnica em vigor.', $f[0]->seen);
        $this->assertSame(9600, $f[0]->weightCents);
        $this->assertSame('/restauracao/fichas-tecnicas/PR?editar=1', $f[0]->fixUrl);
    }

    // ── 8. Os avisos das fichas como regras ──────────────────────────────────

    public function test_recipe_warnings_coca_cola_0_and_the_molho(): void
    {
        // Coca Cola 0: o componente não se compra, mas compra-se um parecido.
        $this->catalog($this->c, '1649601156985', ['code' => '23', 'description' => 'Coca Cola 0', 'forsale' => true]);
        $this->catalog($this->c, '100366', ['code' => '100366', 'description' => 'COCA-COLA ZERO GARRAFA', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, '100415', ['code' => '100415', 'description' => 'COCA COLA ZERO 0.35', 'forpurchase' => true, 'forsale' => false]);
        $this->bought('100415', '2026-10-01', 24, '11001', 'Unidade', 1, 0.55, 1320);
        DB::table('pingwin_supplier_document_lines')->where('product_pingwin_id', '100415')->update(['description' => 'COCA COLA ZERO 0.35', 'product_code' => '100415']);
        $this->persistBom($this->c, $this->bom('1649601156985', [['00000000', 'BCOCA', '2026-05-06', ['S1' => true],
            [$this->bomLine('100366', 1, ['component_code' => '100366', 'component_description' => 'COCA-COLA ZERO GARRAFA', 'unit_cost' => 0.5, 'cost' => 0.5])]]]));
        // Molho (semi-produto, lote sem rendimento confirmado), usado na Francesinha, com um componente inativo.
        $this->catalog($this->c, 'MOLHO', ['code' => '100162', 'description' => 'MOLHO FRANCESINHA 30LT', 'forsale' => false, 'forpurchase' => false]);
        $this->catalog($this->c, 'SALSA', ['code' => '100083', 'description' => 'SALSA', 'forpurchase' => true, 'forsale' => false]);
        $this->catalog($this->c, 'FRAN', ['code' => '227', 'description' => 'Francesinha Tradicional', 'forsale' => true]);
        $this->persistBom($this->c, $this->bom('MOLHO', [['00000000', 'BMOLHO', '2026-05-26', ['S1' => true], [
            $this->bomLine('SALSA', 0.2, ['status' => 2, 'unit_id' => '11002', 'unit_desc' => 'Quilograma']),
            $this->bomLine('X2', 15.4, ['unit_id' => '11003', 'unit_desc' => 'Litro']),
        ]]], ['units' => ['base' => '11003', 'sale' => '11003', 'purchase' => '11003', 'stock' => '11003']]));
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BFRAN', '2026-05-26', ['S1' => true], [
            $this->bomLine('MOLHO', 0.01, ['isbom' => 1, 'unit_id' => '11003', 'unit_desc' => 'Litro']),
        ]]]));

        $similar = $this->findings(ParecidoCompradoRule::class);
        $this->assertCount(1, $similar);
        $this->assertSame('1649601156985', $similar[0]->entityId);
        $this->assertSame('Coca Cola 0: usa um artigo que já não se compra', $similar[0]->title);
        $this->assertStringContainsString('Compra-se um artigo parecido: 100415 COCA COLA ZERO 0.35.', $similar[0]->seen);
        $this->assertContains(['artigo', '100366'], $similar[0]->refs);
        // O sem custo deixa de fora o que tem um parecido comprado (não conta duas vezes).
        $this->assertNotContains('1649601156985', array_map(fn ($f) => $f->entityId, $this->findings(ComponenteSemCustoRule::class)));

        $inactive = $this->findings(ComponenteInativoRule::class);
        $this->assertSame(['MOLHO'], array_map(fn ($f) => $f->entityId, $inactive));
        $this->assertStringContainsString('SALSA', $inactive[0]->title);

        $pending = collect($this->findings(PorConfirmarRule::class))->keyBy('entityId');
        $this->assertTrue($pending->has('MOLHO'));
        $this->assertStringContainsString('falta confirmar quanto rende o lote', $pending['MOLHO']->title);
        $this->assertStringContainsString('parece ser de um lote', $pending['MOLHO']->seen);
        $this->assertSame('/restauracao/fichas-tecnicas/MOLHO?rendimento=1', $pending['MOLHO']->fixUrl);
        $this->assertFalse($pending->has('FRAN'));   // o rendimento na linha fica no achado do próprio molho
    }

    public function test_empty_version_same_day_and_store_without_recipe(): void
    {
        $this->catalog($this->c, 'SG', ['description' => 'SANGRIA TINTA', 'forsale' => true]);
        $this->persistBom($this->c, $this->bom('SG', [
            ['00000000', 'BSG1', '2026-06-02', ['S1' => true], [$this->bomLine('X1', 1)]],
            ['00000001', 'BSG2', '2026-06-02', ['S1' => true], []],
        ]));
        $this->catalog($this->c, 'LS', ['description' => 'Limonada', 'forsale' => true]);
        $this->persistBom($this->c, $this->bom('LS', [['00000000', 'BLS', '2026-01-01', ['S1' => false, 'S2' => true], [$this->bomLine('X1', 1)]]]));
        $this->sale($this->c, $this->loc, 'LS', '2026-10-01', 5, 1500);

        $this->assertSame(['SG'], array_map(fn ($f) => $f->entityId, $this->findings(VersaoVaziaRule::class)));
        $this->assertSame(['SG'], array_map(fn ($f) => $f->entityId, $this->findings(VersoesMesmaDataRule::class)));
        $store = $this->findings(LojaSemFichaRule::class);
        $this->assertSame(['LS'], array_map(fn ($f) => $f->entityId, $store));
        $this->assertSame('Nos últimos 90 dias, Limonada vendeu em Baixa, onde nenhuma versão da ficha está em vigor.', $store[0]->seen);
    }

    // ── 9. Documentos ────────────────────────────────────────────────────────

    public function test_document_dated_outside_the_series_year_like_bovft_522(): void
    {
        $this->doc('H521', 'VFT BOVFT/521', '2026-07-17');
        $this->doc('H522', 'VFT BOVFT/522', '2025-08-01');
        $this->doc('H523', 'VFT BOVFT/523', '2026-08-13');
        $this->doc('H100', 'VFT 0VFT/100', '2026-05-01');
        $this->doc('H101', 'VFT 0VFT/101', '2026-05-02');
        $this->doc('H102', 'VFT 0VFT/102', '2026-05-03');

        $f = $this->findings(DocumentoDataSerieRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('H522', $f[0]->entityId);
        $this->assertSame('BOVFT/522: data de 2025 no meio de documentos de 2026', $f[0]->title);
        $this->assertSame('A fatura BOVFT/522 de Victor Ferreira & Torres tem a data 01/08/2025. A anterior da mesma série (BOVFT/521) é de 17/07/2026 e a seguinte (BOVFT/523) de 13/08/2026.', $f[0]->seen);
        $this->assertSame(['BOVFT', 522], DocumentoDataSerieRule::parse('VFT BOVFT/522'));
    }

    public function test_invoices_without_lines(): void
    {
        $this->doc('E1', 'VFT 0VFT/139', '2026-10-01', '8004');            // em edição há 14 dias
        $this->doc('E2', 'VFT 0VFT/140', '2026-10-14', '8004');            // em edição há 1 dia: ainda não
        $this->doc('A1', 'VFT 0VFT/159', '2026-04-25', '8003');            // anulada: nunca
        $this->doc('Z1', 'VFT BOVFT/900', '2026-09-01', '8002', '1209', 'ok');   // fechada, lida, sem linhas
        $f = collect($this->findings(FaturaSemLinhasRule::class))->keyBy('entityId');
        $this->assertEqualsCanonicalizing(['E1', 'Z1'], $f->keys()->all());
        $this->assertSame('0VFT/139: fatura por fechar no PingWin há 14 dias', $f['E1']->title);
        $this->assertStringContainsString('está em edição no PingWin há 14 dias', $f['E1']->seen);
        $this->assertSame('BOVFT/900: fatura fechada sem linhas', $f['Z1']->title);
    }

    // ── O registo ────────────────────────────────────────────────────────────

    public function test_the_registry_has_every_restaurant_rule_and_the_screens(): void
    {
        $codes = array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'central'));
        $this->assertCount(48, $codes);   // 18 + as 4 do stock (Fatia 7a) + as 8 das transferências (Fatia 7c) + as 2 da engenharia de menu (Fatia 3) + as 9 das unidades (Auditoria v2) + as 6 do dinheiro (v2-C) + a da conferência com as guias (Fatia 5)
        $u = ['restauracao.unidade_fator', 'restauracao.unidade_combinacao_nova', 'restauracao.unidade_embalagem_fator_1', 'restauracao.unidade_base_grande', 'restauracao.unidade_peso_variavel',
            'restauracao.unidade_fornecedores', 'restauracao.unidade_preco_familia', 'restauracao.unidade_sem_uso', 'restauracao.unidade_bebida_ficha', 'restauracao.unidade_por_identificar'];
        $this->assertSame($u, array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'unidades')));
        $this->assertSame(['restauracao.menu_food_cost_alvo', 'restauracao.menu_ficha_sem_compras', 'restauracao.dinheiro_margem_prato'], array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'menu')));
        $this->assertContains('restauracao.iva_venda', $codes);
        $this->assertSame(['restauracao.unidade_fator', 'restauracao.stock_negativo', 'restauracao.comprado_nunca_consumido', 'restauracao.cobertura_impossivel', 'restauracao.armazem_sem_entradas',
            'restauracao.transfer_pcm_armazens'], array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'stock')));
        $this->assertSame(['restauracao.transfer_origem_errada', 'restauracao.transfer_custo_entrada', 'restauracao.transfer_sem_rececao', 'restauracao.transfer_sem_envio',
            'restauracao.transfer_diferenca', 'restauracao.transfer_fator', 'restauracao.transfer_pcm_armazens', 'restauracao.transfer_encomenda_parada'],
            array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'transferencias')));
        $this->assertSame(['restauracao.armazem_sem_entradas'], array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'armazens')));
        $this->assertSame(array_merge(['restauracao.preco_compra', 'restauracao.unidade_trocada', 'restauracao.unidade_fator', 'restauracao.unidade_sem_conversao', 'restauracao.documento_data_serie', 'restauracao.fatura_sem_linhas',
            'restauracao.transfer_fator'], array_values(array_diff($u, ['restauracao.unidade_sem_uso', 'restauracao.unidade_fator'])),
            ['restauracao.dinheiro_preco_subiu', 'restauracao.dinheiro_mais_barato', 'restauracao.dinheiro_fatura_duplicada', 'restauracao.dinheiro_guia_sem_fatura', 'restauracao.dinheiro_fatura_guias_em_falta']),
            array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'faturas')));
        $this->assertSame(['restauracao.familia_suspeita'], array_map(fn ($r) => $r->code(), AuditRegistry::forScope('restauracao', 'familias')));
        $this->assertFalse(app(IvaVendaRule::class)->appliesTo(Company::create(['nipc' => '503504564', 'fiscal_name' => 'Stand', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active'])->id));
    }
}
