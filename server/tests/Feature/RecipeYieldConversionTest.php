<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\PingwinLocation;
use App\Models\PingwinUnit;
use App\Models\ProductBomYield;
use App\Models\ProductUnitConversion;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 1b — rendimento dos semi-produtos (sugestão, confirmação, custo por unidade, pratos de baixa
 * confiança), conversões confirmadas por artigo (sugestão pela descrição e pela razão de preços,
 * prioridade sobre a qnt_base, o caso AVINHEZ), avisos novos e ACL. Tudo só na XPLENDOR.
 */
class RecipeYieldConversionTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private const MOLHO = '1002';
    private const FRANC = '1001';
    private const AVINHEZ = '2001';
    private const NIF = '516832379';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Ana Admin']);
        PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'S1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        $c = $this->company->id;

        foreach ([['11001', 'Unidade', 'UN', null, 1], ['11002', 'Quilograma', 'KG', null, 1], ['11003', 'Litro', 'LT', null, 1], ['BD30', 'BIDON 30L', 'BD 30', '11003', 30]] as [$id, $d, $sn, $parent, $v]) {
            PingwinUnit::create(['company_id' => $c, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sn, 'parent_pingwin_id' => $parent, 'unit_value' => $v]);
        }
        $this->catalog($c, self::MOLHO, ['description' => 'MOLHO FRANCESINHA 30LT', 'base_unit_id' => '11003', 'default_sale_unit_id' => 'BD30',
            'default_purchase_unit_id' => 'BD30', 'forproduction' => true, 'forsale' => false, 'has_bom' => true]);
        $this->catalog($c, self::FRANC, ['description' => 'Francesinha', 'base_unit_id' => '11001', 'has_bom' => true]);
        $this->catalog($c, self::AVINHEZ, ['description' => 'AVINHEZ BRANCO', 'base_unit_id' => '11003', 'forpurchase' => true]);

        // Molho (lote): 6 L de vinho, 5 kg de tomate e 4 kg de ossos (subproduto, custo 0 no PingWin).
        $this->persistBom($c, $this->bom(self::MOLHO, [['00000000', 'BM', '2026-05-26', ['S1' => true], [
            $this->bomLine(self::AVINHEZ, 6, ['unit_id' => '11003', 'unit_desc' => 'Litro', 'component_description' => 'AVINHEZ BRANCO', 'unit_cost' => 0.744603, 'cost' => 4.467618]),
            $this->bomLine('2002', 5, ['unit_id' => '11002', 'unit_desc' => 'Quilograma', 'component_description' => 'TOMATE CUBOS', 'unit_cost' => 1.536, 'cost' => 7.68]),
            $this->bomLine('2003', 4, ['unit_id' => '11002', 'unit_desc' => 'Quilograma', 'component_description' => 'OSSOS']),
        ]]], [
            // Como no PingWin: base Litro, venda e compra por omissão BIDON 30L, artigo de produção.
            'units' => ['base' => '11003', 'sale' => 'BD30', 'purchase' => 'BD30', 'stock' => '11003'],
            'forsale' => 1, 'forproduction' => 1, 'prices' => ['saleprice' => 0, 'saleprice_wo_tax' => 0],
        ]));
        // Francesinha: 0,01 L de molho (semi-produto) + 0,15 kg de bife.
        $this->persistBom($c, $this->bom(self::FRANC, [['00000000', 'BF', '2026-05-26', ['S1' => true], [
            $this->bomLine(self::MOLHO, 0.01, ['isbom' => 1, 'unit_id' => '11003', 'unit_desc' => 'Litro']),
            $this->bomLine('2004', 0.15, ['unit_id' => '11002', 'unit_desc' => 'Quilograma', 'unit_cost' => 13, 'cost' => 1.95]),
        ]]]));

        // O AVINHEZ compra-se em "Unidade" (um recipiente de 20 L) a 14,89 €: a qnt_base do raw é 1.
        $this->purchase($c, self::AVINHEZ, '2026-09-30', 17, 17, 25313, ['unit_id' => '11001', 'unit_desc' => 'Unidade']);
        DB::table('pingwin_supplier_documents')->update(['tax_number' => self::NIF, 'entity_name' => 'COPOS SATISFEITOS']);
        DB::table('pingwin_supplier_document_lines')->update(['description' => "D' ALEGRIA AVINHEZ BRANCO 20LT"]);
        $this->purchase($c, '2002', '2026-09-30', 5, 5, 768, ['unit_id' => '11002', 'unit_desc' => 'Quilograma']);
        $this->purchase($c, '2004', '2026-09-30', 10, 10, 13000, ['unit_id' => '11002', 'unit_desc' => 'Quilograma']);
    }

    private function cost(): RecipeCostService
    {
        return (new RecipeCostService($this->company->id))->load();
    }

    private function costOf(string $pid): array
    {
        $s = $this->cost();
        $v = RecipeCostService::versionInForce($s->versionsOf($pid), '2026-10-15');

        return $s->costVersion($v, $pid) + ['_yield' => $s->yieldInfo($pid, $v)];
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/restaurant/recipes{$tail}";
    }

    private function userWith(array $permissions): User
    {
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Perfil ' . implode(',', $permissions),
            'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
    }

    // ── Rendimento ────────────────────────────────────────────────────────────

    public function test_yield_is_suggested_never_applied_and_dishes_are_low_confidence(): void
    {
        $molho = $this->costOf(self::MOLHO);
        $y = $molho['_yield'];
        $this->assertFalse($y['confirmed']);
        $this->assertSame(['sugerido', 30.0], [$y['source'], $y['quantity']]);
        $this->assertStringContainsString('BIDON 30L', $y['reason']);
        $this->assertStringContainsString('«30LT»', $y['reason']);
        $this->assertTrue($y['looks_like_batch']);                                   // 6 L para 1 L de molho

        // Sem rendimento confirmado: conta 1 L (como antes) e o prato fica de baixa confiança.
        $franc = $this->costOf(self::FRANC);
        $line = $franc['lines'][0];
        $this->assertEqualsWithDelta($molho['cost'], $line['unit_cost_avg'], 1e-6);
        $this->assertTrue($line['low_confidence']);
        $this->assertTrue($franc['low_confidence']);
        $this->assertContains('yield_unconfirmed', array_column($line['warnings'], 'type'));

        // No detalhe do molho: o aviso de lote (ajustado ao A1) e o estado na lista.
        $d = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/' . self::MOLHO))->assertOk()->json('data');
        $texts = implode(' ', array_column($d['versions'][0]['warnings'], 'text'));
        $this->assertStringContainsString('Esta ficha parece ser de um lote (cerca de 30 L)', $texts);
        $this->assertStringContainsString('O PingWin conta a ficha por 1 Litro', $texts);
        $this->assertStringContainsString('usa o custo de stock do semi-produto, que está a zero', $texts);
        $this->assertTrue($d['article']['is_semi']);
        $this->assertContains('rendimento_por_confirmar', $d['flags']);
        $list = collect($this->actingAs($this->admin, 'sanctum')->getJson($this->url('?state=rendimento_por_confirmar'))->json('data.recipes.data'))->pluck('product_id')->all();
        $this->assertEqualsCanonicalizing([self::MOLHO, self::FRANC], $list);
    }

    public function test_confirmed_yield_divides_the_batch_and_clears_low_confidence(): void
    {
        $batch = $this->costOf(self::MOLHO)['cost'];
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/' . self::MOLHO . '/yield'), ['bom_id' => 'BM', 'quantity' => 30])
            ->assertOk()->assertJsonPath('data.yield.quantity', 30);
        $this->assertSame([1, $this->admin->id], [ProductBomYield::count(), ProductBomYield::first()->confirmed_by]);

        $franc = $this->costOf(self::FRANC);
        $line = $franc['lines'][0];
        $this->assertEqualsWithDelta($batch / 30, $line['unit_cost_avg'], 1e-6);
        $this->assertEqualsWithDelta(0.01 * $batch / 30, $line['line_cost'], 1e-6);
        $this->assertFalse($line['low_confidence']);
        $this->assertSame(30.0, $line['sub']['yield']['quantity']);

        $d = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/' . self::MOLHO))->json('data');
        $v = $d['versions'][0];
        $this->assertSame(['confirmado', 'Ana Admin'], [$v['yield']['source'], $v['yield']['confirmed_by']]);
        $this->assertEqualsWithDelta($v['cost_eur'] / 30, $v['cost_per_unit_eur'], 1e-4);
        $this->assertNotContains('batch', array_column($v['warnings'], 'type'));
        $this->assertNotContains('rendimento_por_confirmar',
            collect($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data.recipes.data'))->firstWhere('product_id', self::FRANC)['flags']);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/' . self::MOLHO . '/yield'), ['bom_id' => 'OUTRA', 'quantity' => 30])->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/' . self::MOLHO . '/yield'), ['bom_id' => 'BM', 'quantity' => 0])->assertStatus(422);
    }

    // ── Conversões ───────────────────────────────────────────────────────────

    public function test_quantities_in_descriptions(): void
    {
        $q = fn ($t, $f) => array_column(RecipeHints::quantitiesInText($t, $f), 'quantity');
        $this->assertSame([5.0], $q('AZEITE STA FILOMENA 5LT', 'L'));
        $this->assertSame([20.0], $q('VINHO 20L', 'L'));
        $this->assertSame([0.33], $q('CERVEJA 33CL', 'L'));
        $this->assertSame([2.5], $q('TOMATE CUBOS GULOSOS 2.5KG', 'KG'));
        $this->assertSame([15.0], $q('CEBOLA GRADA 80/110 15KG', 'KG'));
        $this->assertSame([0.00024], $q('OREGAOS 0.24 GR', 'KG'));
        $this->assertSame([24.0], $q('AGUA X24', 'UN'));
        $this->assertSame([], $q('COCA COLA ZERO 0.35', 'L'));
        $this->assertSame([], $q('VINHO 20L', 'KG'));                                 // família diferente
    }

    public function test_avinhez_factor_is_suggested_and_once_confirmed_brings_the_batch_close_to_pingwin(): void
    {
        $before = $this->costOf(self::MOLHO);
        $av = $before['lines'][0];
        $this->assertEqualsWithDelta(6 * 14.89, $av['line_cost'], 1e-6);               // qnt_base 1 por «Unidade»
        $this->assertSame('raw', $av['qty_source']);
        $this->assertTrue($av['needs_factor']);
        $conv = $av['conversion'];
        $this->assertSame(['11001', 'Unidade', 1.0], [$conv['unit_key'], $conv['unit_label'], $conv['current_factor']]);
        $s = $conv['suggestions'][0];
        $this->assertSame(20.0, $s['factor']);
        $this->assertStringContainsString('«20LT»', $s['reason']);                     // pela descrição da compra
        $this->assertStringContainsString('custo PingWin', $s['reason']);              // e pela razão de preços
        $this->assertEqualsWithDelta(0.7445, $s['unit_cost_eur'], 1e-4);
        $this->assertEqualsWithDelta(0.0, $s['diff_pct'], 0.1);
        $this->assertContains('fator_por_confirmar', $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/' . self::MOLHO))->json('data.flags'));

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/' . self::AVINHEZ . '/conversion'),
            ['unit_key' => '11001', 'unit_label' => 'Unidade', 'factor' => 20])->assertOk();

        $after = $this->costOf(self::MOLHO);
        $av = $after['lines'][0];
        $this->assertSame('confirmado', $av['qty_source']);                            // o confirmado manda sobre a qnt_base
        $this->assertEqualsWithDelta(6 * 14.89 / 20, $av['line_cost'], 1e-6);
        $this->assertFalse($av['needs_factor']);
        $this->assertEqualsWithDelta($after['pingwin_cost'], $after['cost'], 0.05);    // 12,15 € contra 12,15 €
        $this->assertSame('zero', $after['lines'][2]['cost_status']);                  // ossos: custo 0, informativo
        $this->assertTrue($after['complete']);
    }

    public function test_a_supplier_specific_factor_applies_only_to_that_supplier(): void
    {
        // Outro fornecedor vende ao litro ("Unidade" = 1 L) a 0,80 €.
        $this->purchase($this->company->id, self::AVINHEZ, '2026-10-01', 1, 1, 80, ['unit_id' => '11001', 'unit_desc' => 'Unidade']);
        DB::table('pingwin_supplier_documents')->where('docheader_id', 'D4')->update(['tax_number' => '999999990']);
        ProductUnitConversion::create(['company_id' => $this->company->id, 'product_pingwin_id' => self::AVINHEZ, 'unit_key' => '11001',
            'supplier_nif' => self::NIF, 'factor_to_base' => 20, 'source' => 'confirmado', 'confirmed_at' => now()]);

        $av = $this->costOf(self::MOLHO)['lines'][0];
        $this->assertSame('misto', $av['qty_source']);
        // 17 un. × 20 L do fornecedor confirmado + 1 un. × 1 L do outro (qnt_base do raw): (253,13 + 0,80) ÷ 341 L.
        $this->assertEqualsWithDelta((253.13 + 0.80) / 341, $av['unit_cost_avg'], 1e-4);
    }

    // ── Avisos ────────────────────────────────────────────────────────────────

    public function test_same_day_versions_empty_version_and_similar_purchased_article(): void
    {
        $c = $this->company->id;
        // Sangria: duas versões com a mesma data, a mais recente vazia.
        $this->persistBom($c, $this->bom('3001', [
            ['00000000', 'BS0', '2026-06-02', ['S1' => true], [$this->bomLine('2002', 1)]],
            ['00000001', 'BS1', '2026-06-02', ['S1' => true], []],
        ]));
        // Coca Cola 0: a ficha usa o 100366 (sem compras); compra-se o 100415.
        $this->catalog($c, '3100', ['description' => 'COCA-COLA ZERO GARRAFA']);
        $this->persistBom($c, $this->bom('3002', [['00000000', 'BC', '2026-05-06', ['S1' => true],
            [$this->bomLine('3100', 1, ['component_description' => 'COCA-COLA ZERO GARRAFA', 'unit_cost' => 0.5275, 'cost' => 0.5275])]]]));
        $this->purchase($c, '3101', '2026-10-09', 408, 408, 22153);
        DB::table('pingwin_supplier_document_lines')->where('product_pingwin_id', '3101')->update(['description' => 'COCA COLA ZERO 0.35', 'product_code' => '100415']);

        $as = $this->actingAs($this->admin, 'sanctum');
        $sangria = $as->getJson($this->url('/3001'))->assertOk()->json('data.versions');
        $inForce = collect($sangria)->firstWhere('in_force', true);
        $this->assertSame('00000001', $inForce['key']);
        $texts = array_column($inForce['warnings'], 'text');
        $this->assertContains('Há 2 versões com início em 02/06/2026: pela nossa regra (a criada depois) está em vigor a versão 2, que não tem linhas.', $texts);
        $this->assertContains('A versão em vigor não tem linhas.', $texts);

        $coca = $as->getJson($this->url('/3002'))->assertOk()->json('data.versions.0.lines.0');
        $this->assertSame(['3101', '100415', 'COCA COLA ZERO 0.35'], [$coca['similar']['product_id'], $coca['similar']['code'], $coca['similar']['description']]);
        $this->assertEqualsWithDelta(0.543, $coca['similar']['last_eur'], 1e-3);
        $this->assertContains('similar', array_column($coca['warnings'], 'type'));

        $ossos = $as->getJson($this->url('/' . self::MOLHO))->json('data.versions.0.lines.2');
        $this->assertSame(['zero', 'info'], [$ossos['cost_status'], collect($ossos['warnings'])->firstWhere('type', 'zero_cost')['level']]);
    }

    public function test_similar_names_ignore_accents_prefixes_calibres_and_packaging(): void
    {
        $sim = fn ($a, $b) => RecipeHints::similarity(RecipeHints::nameTokens($a), RecipeHints::nameTokens($b));
        $this->assertSame(1.0, $sim('COCA-COLA ZERO GARRAFA', 'COCA COLA ZERO 0.35'));
        $this->assertSame(1.0, $sim('MC Açúcar branco 1KG', 'ACUCAR BRANCO'));
        $this->assertSame(1.0, $sim('PE TOMATE CUBOS 2.5KG', 'TOMATE CUBOS LATA'));
        $this->assertLessThan(0.75, $sim('COCA COLA ZERO', 'COCA COLA'));              // 2 de 3 palavras: não é o mesmo artigo
        $this->assertSame(0.75, $sim('TOMATE CUBOS GULOSOS FRESCO', 'TOMATE CUBOS GULOSOS')); // 3 de 4: parecido
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_confirming_needs_restaurant_edit_permission_and_euros_stay_finance_only(): void
    {
        $viewer = $this->userWith(['restauracao.ver', 'financas.ver']);
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/' . self::MOLHO . '/yield'), ['bom_id' => 'BM', 'quantity' => 30])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/' . self::AVINHEZ . '/conversion'), ['unit_key' => '11001', 'factor' => 20])->assertForbidden();
        $this->assertSame(0, ProductBomYield::count() + ProductUnitConversion::count());

        // Edita sem ver as Finanças: confirma, mas o detalhe continua sem euros (incluindo o efeito das sugestões).
        $cook = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $this->actingAs($cook, 'sanctum')->postJson($this->url('/' . self::AVINHEZ . '/conversion'), ['unit_key' => '11001', 'factor' => 20, 'supplier_nif' => '516 832 379'])->assertOk();
        $this->assertSame(self::NIF, ProductUnitConversion::first()->supplier_nif);
        $json = json_encode($this->actingAs($cook, 'sanctum')->getJson($this->url('/' . self::MOLHO))->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $json);
        $this->assertStringNotContainsString('€', $json);

        // Tenancy: outra empresa não confirma nesta, nem com o id do artigo desta na dela.
        $other = Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->postJson($this->url('/' . self::MOLHO . '/yield'), ['bom_id' => 'BM', 'quantity' => 30])->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/companies/{$other->id}/restaurant/recipes/" . self::MOLHO . '/yield', ['bom_id' => 'BM', 'quantity' => 30])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/companies/{$other->id}/restaurant/recipes/" . self::AVINHEZ . '/conversion', ['unit_key' => '11001', 'factor' => 20])->assertNotFound();
    }
}
