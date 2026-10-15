<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 1 — os endpoints das fichas técnicas: lista por Pareto (cobertura, top sem ficha, estados,
 * filtros, ordenação), detalhe (versões, em vigor, lojas, "Usado em"), tenancy e os euros só para
 * quem vê as Finanças (o servidor não os envia aos outros).
 */
class RecipeEndpointsTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'trade_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'S1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'S2', 'display_name' => 'Costa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $c = $this->company->id;
        // Francesinha: ficha em vigor nas duas lojas, com o molho (semi-produto, só na BO).
        $this->catalog($c, '1001', ['description' => 'Francesinha', 'family' => 'Família \\ Comidas \\ Francesinhas', 'has_bom' => true, 'saleprice_cents' => 1460]);
        $this->catalog($c, '1002', ['description' => 'Molho francesinha', 'family' => 'Família \\ Compras \\ Produção', 'has_bom' => true, 'forsale' => false]);
        $this->persistBom($c, $this->bom('1002', [['00000000', 'BM', '2026-05-26', ['BO' => true, 'S1' => false, 'S2' => false], [$this->bomLine('2001', 6)]]]));
        $this->persistBom($c, $this->bom('1001', [['00000000', 'BF', '2026-05-26', ['S1' => true, 'S2' => true],
            [$this->bomLine('1002', 0.01, ['isbom' => 1]), $this->bomLine('2002', 0.15, ['unit_cost' => 13, 'cost' => 1.95])]]]));
        // Coca Cola 0: a versão de maio não tem a Costa ativa (a loja fica sem ficha em vigor).
        $this->catalog($c, '1003', ['description' => 'Coca Cola 0', 'has_bom' => true, 'saleprice_cents' => 290]);
        $this->persistBom($c, $this->bom('1003', [['00000000', 'BC', '2026-05-06', ['S1' => true, 'S2' => false],
            [$this->bomLine('2003', 1, ['unit_cost' => 0.5275, 'cost' => 0.5275])]]]));
        // Sem ficha: vende bem.
        $this->catalog($c, '1004', ['description' => 'Prato de batata', 'family' => 'Família \\ Comidas \\ Acompanhamentos']);

        $this->purchase($c, '2001', '2026-09-10', 6, 6, 600);
        $this->purchase($c, '2002', '2026-09-10', 10, 10, 13000);
        $this->purchase($c, '2003', '2026-09-30', 576, 576, 31275);

        $this->sale($c, $this->baixa->id, '1001', '2026-10-01', 100, 130000, 'Família \\ Comidas \\ Francesinhas'); // 1 300 €
        $this->sale($c, $this->baixa->id, '1004', '2026-10-01', 200, 70000, 'Família \\ Comidas \\ Acompanhamentos'); // 700 €
        $this->sale($c, $this->baixa->id, '1003', '2026-10-01', 50, 11700);   // 117 €
        $this->sale($c, $this->costa->id, '1003', '2026-10-02', 50, 11700);   // 117 €
        $this->sale($c, $this->baixa->id, '1001', '2026-06-01', 999, 9990000, 'Família \\ Comidas \\ Francesinhas'); // fora dos 90 dias
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/restaurant/recipes{$tail}";
    }

    private function userWithout(array $permissions): User
    {
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Cozinha',
            'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
    }

    public function test_list_by_pareto_with_coverage_states_and_costs(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $rows = collect($data['recipes']['data'])->keyBy('product_id');

        $this->assertSame(['1001', '1004', '1003', '1002'], array_column($data['recipes']['data'], 'product_id'));
        // Total 2 234 €: com ficha 1 300 + 234 = 1 534 → 68,7%; a batata (31,3%) está no top 80% sem ficha.
        $this->assertSame(68.7, $data['summary']['coverage_pct']);
        $this->assertSame(1, $data['summary']['top_without_bom']);
        $this->assertEqualsWithDelta(58.19, $rows['1001']['share_pct'], 0.01);
        $this->assertEqualsWithDelta(89.53, $rows['1004']['cum_pct'], 0.01);

        $this->assertSame('com_ficha', $rows['1001']['state']);
        $this->assertSame('sem_ficha', $rows['1004']['state']);
        $this->assertSame(['loja_sem_ficha', ['Costa']], [$rows['1003']['state'], $rows['1003']['missing_stores']]);

        // Francesinha: 0,01 × 6,00 (molho, por unidade base) + 0,15 × 13 = 2,01 €; preço 13,00 € → 15,5%.
        $f = $rows['1001'];
        $this->assertEqualsWithDelta(2.01, $f['cost_eur'], 1e-4);
        $this->assertEquals([13.0, 14.6, 15.5, 2, '2026-05-26'], [$f['price_eur'], $f['list_price_eur'], $f['food_cost_pct'], $f['components'], $f['valid_from']]);
        $this->assertEqualsWithDelta(10.99, $f['margin_eur'], 1e-4);
        $this->assertSame(['Baixa', 'Costa'], $f['stores']);
        $this->assertSame('Comidas › Francesinhas', $f['family']);
        $this->assertEqualsWithDelta(0.543, $rows['1003']['cost_eur'], 1e-3);
        $this->assertTrue($data['financial']['visible']);
    }

    public function test_filters_location_state_family_search_and_sort(): void
    {
        $as = $this->actingAs($this->admin, 'sanctum');
        $costa = $as->getJson($this->url("?location_id={$this->costa->id}"))->assertOk()->json('data.recipes.data');
        $coca = collect($costa)->firstWhere('product_id', '1003');
        $this->assertSame(['loja_sem_ficha', null], [$coca['state'], $coca['cost_eur']]);   // na Costa não há versão em vigor
        $this->assertEquals(100, $coca['share_pct']);                                       // só as vendas da Costa

        $this->assertSame(['1004'], array_column($as->getJson($this->url('?state=sem_ficha'))->json('data.recipes.data'), 'product_id'));
        $this->assertSame(['1001'], array_column($as->getJson($this->url('?family=' . urlencode('Comidas › Francesinhas')))->json('data.recipes.data'), 'product_id'));
        $this->assertSame(['1003'], array_column($as->getJson($this->url('?search=coca'))->json('data.recipes.data'), 'product_id'));
        $byCost = array_column($as->getJson($this->url('?sort=cost&dir=desc'))->json('data.recipes.data'), 'product_id');
        $this->assertSame(['1002', '1001', '1003', '1004'], $byCost);                    // vazios no fim
        $as->getJson($this->url('?sort=nada'))->assertStatus(422);
        $as->getJson($this->url('?location_id=999'))->assertNotFound();
    }

    public function test_detail_with_versions_lines_semi_product_and_used_in(): void
    {
        $d = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/1001'))->assertOk()->json('data');
        $this->assertSame('com_ficha', $d['state']);
        $v = $d['versions'][0];
        $this->assertTrue($v['in_force']);
        $this->assertSame([['Baixa', true, true], ['Costa', true, true]], array_map(fn ($s) => [$s['name'], $s['active'], $s['in_force']], $v['stores']));
        $molho = $v['lines'][0];
        $this->assertSame([true, 'ficha', 'ok'], [$molho['is_bom'], $molho['cost_source'], $molho['cost_status']]);
        $this->assertSame('2001', $molho['sub']['lines'][0]['component_id']);
        $this->assertEqualsWithDelta(1.95, $v['lines'][1]['pingwin_cost_eur'], 1e-6);

        $m = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/1002'))->assertOk()->json('data');
        $this->assertSame([['1001', true]], array_map(fn ($u) => [$u['product_id'], $u['in_force']], $m['used_in']));

        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/123456'))->assertNotFound();
    }

    public function test_without_finance_no_euros_are_sent(): void
    {
        $cook = $this->userWithout(['restauracao.ver']);
        $list = $this->actingAs($cook, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $json = json_encode($list, JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $json, 'nenhum campo em euros com valor');
        $this->assertSame(['visible' => false, 'note' => 'Sem acesso aos valores financeiros'], $list['financial']);
        // Ficam as quantidades, as percentagens, o estado e o food cost.
        $f = collect($list['recipes']['data'])->firstWhere('product_id', '1001');
        $this->assertEquals([100, 'com_ficha', 15.5], [$f['qty'], $f['state'], $f['food_cost_pct']]);
        $this->assertNotNull($f['share_pct']);
        $this->assertArrayNotHasKey('_net', $f);
        // Ordenar pelos euros não é permitido.
        $this->actingAs($cook, 'sanctum')->getJson($this->url('?sort=cost'))->assertStatus(422);

        $detail = json_encode($this->actingAs($cook, 'sanctum')->getJson($this->url('/1001'))->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $detail);
        $this->assertStringContainsString('"visible":false', $detail);
    }

    public function test_tenancy_and_permission(): void
    {
        $planId = DB::table('plans')->value('id');
        $other = Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url())->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/1001'))->assertForbidden();
        // A outra empresa não vê as fichas desta, nem pelo id do artigo.
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$other->id}/restaurant/recipes/1001")->assertStatus(404);
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$other->id}/restaurant/recipes")->json('data.recipes.data'));

        $noRestaurant = $this->userWithout(['bussola.ver']);
        $this->actingAs($noRestaurant, 'sanctum')->getJson($this->url())->assertForbidden();
    }
}
