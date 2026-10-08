<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantFamilyCategory;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\FamilyCategoryRules;
use App\Services\Restaurant\RestaurantFamilyCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * XPLENDOR — F1-3 do marketing da restauração: regras das categorias, sugestão da IA
 * (simulada), confirmação só pela equipa, qualidade dos dados e acesso por empresa.
 */
class RestaurantMarketingDataTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private Company $auto;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;
    private User $admin;
    private User $user;
    private User $otherAdmin;
    private User $autoAdmin;

    /** As 23 famílias com vendas da Yuko (captura h1b) e o peso relativo (cêntimos em 8 dias). */
    private const YUKO_FAMILIES = [
        '10' => ['Família \\ Comidas \\ Francesinhas', 4000000],
        '11' => ['Família \\ Comidas \\ Acompanhamentos', 600000],
        '12' => ['Família \\ Bebidas \\ Cerveja', 460000],
        '13' => ['Família \\ Comidas \\ Cozinha', 410000],
        '14' => ['Família \\ Bebidas \\ Sumos', 290000],
        '15' => ['Família \\ Bebidas \\ Sangria', 235000],
        '16' => ['Família \\ Sobremesas \\ Sobremesas', 235000],
        '17' => ['Família \\ Cafetaria \\ Cafetaria', 145000],
        '18' => ['Família \\ Comidas \\ Petiscos', 145000],
        '19' => ['Família \\ Bebidas \\ Agua', 130000],
        '20' => ['Família \\ Comidas \\ Crianças', 62000],
        '21' => ['Família \\ Bebidas \\ Cerv Internacional', 55000],
        '22' => ['Família \\ Comidas \\ Pao', 48000],
        '23' => ['Família \\ Bebidas \\ Espirituosas', 21000],
        '24' => ['Família \\ Bebidas \\ Vinho Branco', 21000],
        '25' => ['Família \\ Comidas \\ Tapas', 14000],
        '26' => ['Família \\ Bebidas \\ Vinhos a copo', 14000],
        '27' => ['Família \\ Uber Eats \\ Comida', 14000],
        '28' => ['Família \\ Bebidas \\ Vinho Tinto', 7000],
        '29' => ['Família \\ Bebidas \\ Vinho Verde', 7000],
        '30' => ['Família \\ Comidas \\ Sandwiches', 7000],
        '31' => ['Família \\ Comidas \\ Aperitivos', 3000],
        '32' => ['Família \\ Bebidas \\ Portos', 3000],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 10:00:00');
        $this->configureAi();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009500', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500009501', 'fiscal_name' => 'Outro', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500009502', 'fiscal_name' => 'Stand', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
        }
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1099845342604', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '584955579139649880', 'display_name' => 'Costa Cabral', 'is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->autoAdmin = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    private function seedYukoSales(string $date = '2026-10-01'): void
    {
        $rows = [];
        foreach (self::YUKO_FAMILIES as $id => [$path, $cents]) {
            $rows[] = ['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $date,
                'product_pingwin_id' => "P{$id}", 'product_name' => "Artigo {$id}", 'family_pingwin_id' => (string) $id,
                'family_path' => $path, 'quantity' => 1, 'net_cents' => $cents, 'tax_cents' => 0, 'gross_cents' => $cents];
        }
        PingwinItemSale::insert($rows);
    }

    private function url(string $suffix, ?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/integrations/pingwin/' . $suffix;
    }

    // ── Regras ─────────────────────────────────────────────────────────────────

    public function test_rules_suggest_20_of_the_23_yuko_families_and_leave_ambiguous_ones(): void
    {
        $expected = [
            'Francesinhas' => 'pratos', 'Acompanhamentos' => 'acompanhamentos', 'Cerveja' => 'cerveja', 'Cozinha' => 'pratos',
            'Sumos' => 'sem_alcool', 'Sangria' => null, 'Sobremesas' => 'sobremesas', 'Cafetaria' => 'cafetaria',
            'Petiscos' => 'petiscos', 'Agua' => 'sem_alcool', 'Crianças' => 'infantil', 'Cerv Internacional' => 'cerveja',
            'Pao' => null, 'Espirituosas' => 'cocktails', 'Vinho Branco' => 'vinho', 'Tapas' => 'petiscos',
            'Vinhos a copo' => 'vinho', 'Comida' => 'entrega', 'Vinho Tinto' => 'vinho', 'Vinho Verde' => 'vinho',
            'Sandwiches' => null, 'Aperitivos' => 'petiscos', 'Portos' => 'vinho',
        ];
        foreach (self::YUKO_FAMILIES as [$path]) {
            $leaf = FamilyCategoryRules::leaf($path);
            $this->assertSame($expected[$leaf], FamilyCategoryRules::suggest($path), $path);
        }
        $this->assertSame('entrega', FamilyCategoryRules::suggest('Família \\ Uber Eats \\ Bebidas'));
        // Taxas e faturas da Uber não são vendas de produto: Excluir.
        $this->assertSame('excluir', FamilyCategoryRules::suggest('Família \\ Uber Eats \\ Taxas'));
        $this->assertSame('excluir', FamilyCategoryRules::suggest('Família \\ Uber Eats \\ Faturas'));
        $this->assertSame('excluir', FamilyCategoryRules::suggest('Família \\ Diversos \\ Staff Meal'));
        $this->assertNull(FamilyCategoryRules::suggest(''));
        $this->assertNull(FamilyCategoryRules::suggest('Família'));
    }

    public function test_rules_only_suggest_and_never_touch_a_confirmed_category(): void
    {
        $this->seedYukoSales();
        $service = app(RestaurantFamilyCategoryService::class);
        $service->refresh($this->company->id);

        $this->assertSame(23, RestaurantFamilyCategory::where('company_id', $this->company->id)->count());
        $this->assertSame(0, RestaurantFamilyCategory::whereNotNull('category')->count()); // nada confirmado sozinho
        $this->assertSame(20, RestaurantFamilyCategory::where('suggested_by', 'rules')->count());

        $service->confirm($this->company->id, [['family_pingwin_id' => '10', 'category' => 'petiscos']], $this->admin);
        $service->refresh($this->company->id);
        $row = RestaurantFamilyCategory::where('family_pingwin_id', '10')->first();
        $this->assertSame('petiscos', $row->category);        // a escolha da equipa fica
        $this->assertSame('pratos', $row->suggested_category); // a sugestão das regras continua visível
        $this->assertSame($this->admin->id, $row->confirmed_by_user_id);
    }

    // ── IA (simulada) ──────────────────────────────────────────────────────────

    public function test_ai_is_asked_only_for_families_without_suggestion_and_only_suggests(): void
    {
        $this->seedYukoSales();
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse(['familias' => [
            ['familia' => 'Família \\ Bebidas \\ Sangria', 'categoria' => 'cocktails'],
            ['familia' => 'Família \\ Comidas \\ Pao', 'categoria' => 'acompanhamentos'],
            ['familia' => 'Família \\ Comidas \\ Sandwiches', 'categoria' => 'inventada'], // chave inválida → ignorada
            ['familia' => 'Família \\ Comidas \\ Francesinhas', 'categoria' => 'excluir'],  // não foi pedida → ignorada
        ]])]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('family-categories/ai-suggest'))->assertOk();

        $this->assertSame(2, $response->json('data.suggested'));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'Sangria') && str_contains($body, 'Sandwiches') && ! str_contains($body, 'Francesinhas');
        });
        $this->assertSame('ai', RestaurantFamilyCategory::where('family_pingwin_id', '15')->value('suggested_by'));
        $this->assertSame('cocktails', RestaurantFamilyCategory::where('family_pingwin_id', '15')->value('suggested_category'));
        $this->assertNull(RestaurantFamilyCategory::where('family_pingwin_id', '30')->value('suggested_category'));
        $this->assertSame('pratos', RestaurantFamilyCategory::where('family_pingwin_id', '10')->value('suggested_category'));
        $this->assertSame(0, RestaurantFamilyCategory::whereNotNull('category')->count());
        $this->assertDatabaseHas('ai_requests', ['company_id' => $this->company->id, 'mode' => 'family_categories', 'status' => 'done']);

        // Um utilizador sem permissão não pede à IA.
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('family-categories/ai-suggest'))->assertStatus(403);
    }

    // ── Ecrã das categorias ────────────────────────────────────────────────────

    public function test_list_is_ordered_by_revenue_with_share_and_categories(): void
    {
        $this->seedYukoSales();
        $data = $this->actingAs($this->user, 'sanctum')->getJson($this->url('family-categories'))->assertOk()->json('data');

        $this->assertSame('Francesinhas', $data['families'][0]['family']);
        $this->assertSame('rules', $data['families'][0]['suggested_by']);
        $this->assertEqualsWithDelta(57.8, $data['families'][0]['share_pct'], 0.1);
        $this->assertSame(23, $data['pending']);
        $this->assertCount(13, $data['categories']);
        $this->assertSame(['value' => 'entrega', 'label' => 'Entrega'], $data['categories'][10]);
        $this->assertFalse($data['can_manage']);
    }

    public function test_only_who_configures_integrations_confirms_and_tenancy_is_enforced(): void
    {
        $this->seedYukoSales();
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('family-categories'))->assertOk();
        $body = ['items' => [['family_pingwin_id' => '10', 'category' => 'pratos'], ['family_pingwin_id' => '27', 'category' => 'entrega']]];

        $this->actingAs($this->user, 'sanctum')->putJson($this->url('family-categories'), $body)->assertStatus(403);
        $this->actingAs($this->otherAdmin, 'sanctum')->putJson($this->url('family-categories'), $body)->assertStatus(403);
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson($this->url('family-categories'))->assertStatus(403);
        $this->actingAs($this->autoAdmin, 'sanctum')->getJson($this->url('family-categories', $this->auto->id))->assertStatus(403); // sem módulo
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('family-categories'), ['items' => [['family_pingwin_id' => '10', 'category' => 'sushi']]])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('family-categories'), ['items' => [['family_pingwin_id' => '999', 'category' => 'pratos']]])->assertStatus(422);
        $this->assertSame(0, RestaurantFamilyCategory::whereNotNull('category')->count());

        $data = $this->actingAs($this->admin, 'sanctum')->putJson($this->url('family-categories'), $body)->assertOk()->json('data');
        $this->assertSame(21, $data['pending']);
        $first = collect($data['families'])->firstWhere('family_pingwin_id', '10');
        $this->assertSame('pratos', $first['category']);
        $this->assertSame($this->admin->name, $first['confirmed_by']);
    }

    public function test_families_cover_the_whole_history_in_card_and_list(): void
    {
        $this->seedYukoSales();
        // Uma família que só vendeu em maio (fora dos 90 dias).
        PingwinItemSale::insert(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => '2026-05-10',
            'product_pingwin_id' => 'P90', 'product_name' => 'Taxa de entrega', 'family_pingwin_id' => '90',
            'family_path' => 'Família \\ Uber Eats \\ Taxas', 'quantity' => 1, 'net_cents' => 1500, 'tax_cents' => 0, 'gross_cents' => 1500]);

        $list = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('family-categories'))->assertOk()->json('data');
        $this->assertSame(24, $list['pending']);
        $old = collect($list['families'])->firstWhere('family_pingwin_id', '90');
        $this->assertFalse($old['recent']);
        $this->assertSame('2026-05-10', $old['last_sale_date']);
        $this->assertSame(1500, $old['net_cents_total']);
        $this->assertSame('excluir', $old['suggested_category']);
        $this->assertSame('90', collect($list['families'])->last()['family_pingwin_id']); // as antigas no fim
        $this->assertTrue($list['families'][0]['recent']);

        $card = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('marketing-data'))->assertOk()->json('data');
        $this->assertSame(24, $card['families']['total']);      // o mesmo número nos dois ecrãs
        $this->assertSame(24, $card['families']['unconfirmed']);
    }

    // ── Postos de venda do relatório anual (só root) ───────────────────────────

    public function test_only_root_sets_the_annual_locals_and_they_survive_a_reconnect(): void
    {
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        \App\Models\CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'u', 'database' => 'yuko']]);
        $body = ['locals' => '584955579139621602, 62590524561075475'];

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('annual-locals'), $body)->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('annual-locals'), $body)->assertStatus(403);
        $this->actingAs($root, 'sanctum')->putJson($this->url('annual-locals'), ['locals' => 'Yuko, Uber'])->assertStatus(422);
        $this->actingAs($root, 'sanctum')->putJson($this->url('annual-locals'), $body)->assertOk()
            ->assertJsonPath('data.annual_locals', '584955579139621602,62590524561075475');

        $pingwin = app(\App\Services\PingwinService::class);
        $this->assertSame('584955579139621602,62590524561075475', $pingwin->annualLocals($this->company->id));
        // Só o root vê o valor no cartão.
        $this->actingAs($root, 'sanctum')->getJson($this->url('marketing-data'))->assertJsonPath('data.can_edit_locals', true)
            ->assertJsonPath('data.annual_locals', '584955579139621602,62590524561075475');
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('marketing-data'))->assertJsonPath('data.can_edit_locals', false)
            ->assertJsonPath('data.annual_locals', null);

        // Voltar a ligar o PingWin (novas credenciais) não apaga os postos de venda.
        $pingwin->saveCredentials($this->company->id, ['username' => 'novo', 'database' => 'yuko', 'annual_locals' => 'injetado'], 'senha');
        $this->assertSame('584955579139621602,62590524561075475', $pingwin->annualLocals($this->company->id));

        // Limpar: vazio = todos os postos de venda.
        $this->actingAs($root, 'sanctum')->putJson($this->url('annual-locals'), ['locals' => ''])->assertOk();
        $this->assertSame('', $pingwin->annualLocals($this->company->id));
    }

    // ── Dados para o marketing ─────────────────────────────────────────────────

    public function test_marketing_data_card(): void
    {
        $this->seedYukoSales();
        // Baixa: abertura manual 01/08, início detetado 01/05 → aviso (diferem mais de 7 dias).
        $this->baixa->forceFill(['opened_on' => '2026-08-01', 'sales_first_month' => '2026-05-01', 'sales_since' => '2026-05-01',
            'sales_start_checked_at' => now(), 'history_complete_at' => now()])->save();
        // Costa Cabral: só o mês detetado; sem abertura manual.
        $this->costa->forceFill(['sales_first_month' => '2026-06-01', 'sales_start_checked_at' => now()])->save();
        foreach ([['2026-09-30', 'ok'], ['2026-10-01', 'ok'], ['2026-10-02', 'mismatch'], ['2026-10-03', 'unverified'], ['2026-06-01', 'ok']] as [$d, $s]) {
            PingwinItemSalesDay::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $d, 'status' => $s, 'synced_at' => now()]);
        }
        foreach (['P10', 'P11', 'P12'] as $id) {
            PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'description' => $id, 'is_active' => true]);
        }
        app(RestaurantFamilyCategoryService::class)->refresh($this->company->id);
        app(RestaurantFamilyCategoryService::class)->confirm($this->company->id, [['family_pingwin_id' => '10', 'category' => 'pratos']], $this->admin);

        $data = $this->actingAs($this->user, 'sanctum')->getJson($this->url('marketing-data'))->assertOk()->json('data');

        $this->assertFalse($data['enabled']);
        $this->assertEquals(['sold' => 23, 'missing' => 20, 'coverage_pct' => 13.0], $data['catalog']);
        $this->assertSame(['checked' => 4, 'ok' => 2, 'marked' => 1], $data['days']); // 01/06 fica fora dos 90 dias
        $this->assertSame(23, $data['families']['total']);
        $this->assertSame(22, $data['families']['unconfirmed']);
        $this->assertEqualsWithDelta(42.2, $data['families']['revenue_unconfirmed_pct'], 0.1);

        [$baixa, $costa] = $data['locations'];
        $this->assertSame('2026-08-01', $baixa['effective_start']); // a abertura manual manda
        $this->assertSame('2026-05-01', $baixa['detected_start']);
        $this->assertTrue($baixa['start_warning']);
        $this->assertSame(92, $baixa['start_difference_days']);
        $this->assertTrue($baixa['history_complete']);
        $this->assertSame(5, $baixa['days_read']);
        $this->assertSame('2026-06-01', $costa['effective_start']);
        $this->assertTrue($costa['detected_is_month']);
        $this->assertFalse($costa['start_warning']);
        $this->assertFalse($costa['history_complete']);

        $this->actingAs($this->otherAdmin, 'sanctum')->getJson($this->url('marketing-data'))->assertStatus(403);
    }
}
