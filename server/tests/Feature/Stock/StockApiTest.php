<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\WritePingwinStockJob;
use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\PingwinStock;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\RestaurantStoreWarehouse;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7a — a API do stock e dos armazéns: a lista por Pareto do valor e o detalhe por armazém (os
 * euros só com financas.ver), a escrita do mínimo/máximo e dos armazéns (restauracao.editar, em fila,
 * uma de cada vez), a guarda do dev, a releitura (ok / conflito / não bate), apagar só sem stock e com
 * o nome escrito outra vez, o armazém por omissão da loja, e o isolamento por empresa.
 */
class StockApiTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private User $admin;

    /** @var list<array> pedidos ao "Python" */
    public array $calls = [];

    /** @var callable(array $payload): array */
    public $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
        $this->admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin', 'name' => 'Ana Admin']);
        $this->catalog($this->c, '100415', ['code' => '100415', 'description' => 'COCA COLA ZERO 0.35', 'forsale' => false, 'forpurchase' => true, 'family' => 'Família \\ COMPRAS \\ BEBIDAS \\ REFRIGERANTES']);
        $this->catalog($this->c, '100366', ['code' => '100366', 'description' => 'COCA-COLA ZERO GARRAFA', 'forsale' => false, 'forpurchase' => true, 'family' => 'Família \\ COMPRAS \\ BEBIDAS \\ REFRIGERANTES']);
        $this->stock('100415', 'WBO', 5112, 0.534564);
        $this->stock('100415', 'WB', 1896, 0.527748);
        $this->stock('100415', 'WC', 3432, 0.527746);
        $this->stock('100366', 'WB', -2637, 0.5275);
        $this->respond = fn (array $p) => ['ok' => true];
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                $this->test->calls[] = $payload;

                return ($this->test->respond)($payload);
            }
        });
        config(['services.pingwin.stock_write_allowlist' => '', 'services.pingwin.warehouse_write_allowlist' => '']);
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->c}/restaurant{$tail}";
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
    }

    // ── Lista e detalhe ──────────────────────────────────────────────────────

    public function test_list_by_value_pareto_with_filters_and_euros_only_with_finance(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/stock'))->assertOk();
        $rows = $res->json('data.stock.data');
        $this->assertSame(['100415', '100366'], array_column($rows, 'product_id'));
        $this->assertSame(10440, (int) $rows[0]['qnt']);
        $this->assertEqualsWithDelta(5544.53, $rows[0]['value_eur'], 0.01);
        $this->assertSame(['sem_consumo'], $rows[0]['states']);
        $this->assertSame(['negativo'], $rows[1]['states']);
        $this->assertArrayNotHasKey('_value', $rows[0]);
        $this->assertSame(1, $res->json('data.summary.top80'));
        $this->assertSame('COMPRAS › BEBIDAS', $rows[0]['family_group']);

        $baixa = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/stock?warehouse_id=WB&state=negativo'))->assertOk();
        $this->assertSame(['100366'], array_column($baixa->json('data.stock.data'), 'product_id'));
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/stock?warehouse_id=NOPE'))->assertNotFound();

        // Sem financas.ver: tudo menos os euros (e não pode ordenar por eles).
        $cook = $this->userWith(['restauracao.ver']);
        $json = json_encode($this->actingAs($cook, 'sanctum')->getJson($this->url('/stock'))->assertOk()->json('data'));
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $json);
        $this->assertStringContainsString('"visible":false', $json);
        $this->actingAs($cook, 'sanctum')->getJson($this->url('/stock?sort=value'))->assertStatus(422);
        $detail = json_encode($this->actingAs($cook, 'sanctum')->getJson($this->url('/stock/100415'))->assertOk()->json('data'));
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $detail);
    }

    public function test_article_detail_by_warehouse_with_the_guard(): void
    {
        config(['services.pingwin.stock_write_allowlist' => '0']);
        $d = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/stock/100415'))->assertOk()->json('data');
        $this->assertSame(['WBO', 'WC', 'WB'], array_column($d['warehouses'], 'warehouse_id'));   // pelo código: 1, 2, BAIXA
        $this->assertSame(10440, (int) $d['total']['qnt']);
        $this->assertSame('desligada', $d['write_guard']['mode']);
        $this->assertTrue($d['can_edit']);
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/stock/999'))->assertNotFound();
    }

    // ── Mínimo e máximo ──────────────────────────────────────────────────────

    public function test_limits_are_queued_validated_and_one_at_a_time(): void
    {
        Bus::fake();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5, 'max_stock' => 50])
            ->assertStatus(202)->assertJsonPath('data.write.status', 'queued');
        $w = PingwinStockWrite::firstOrFail();
        $this->assertSame(['limites', '100415', 'WB'], [$w->kind, $w->product_pingwin_id, $w->warehouse_pingwin_id]);
        $this->assertEquals(['min_stock' => 5, 'max_stock' => 50], $w->payload);
        $this->assertSame(['min_stock' => null, 'max_stock' => null, 'disable_generic' => 0], $w->expected);
        Bus::assertDispatched(WritePingwinStockJob::class, fn ($j) => $j->writeId === $w->id);

        // Uma de cada vez; mínimo > máximo; armazém sem linha; nada a mudar.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WC', 'min_stock' => 1])->assertStatus(422);
        $w->update(['status' => 'ok']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WC', 'min_stock' => 9, 'max_stock' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('min_stock');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100366/limits'), ['warehouse_id' => 'WC', 'min_stock' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WC', 'min_stock' => null])
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WC', 'min_stock' => -1])->assertStatus(422);
    }

    public function test_dev_guard_on_limits(): void
    {
        Bus::fake();
        config(['services.pingwin.stock_write_allowlist' => '101335']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])
            ->assertForbidden()->assertJsonFragment(['message' => 'Neste ambiente só se grava no artigo de teste autorizado (101335).']);
        config(['services.pingwin.stock_write_allowlist' => '0']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])->assertForbidden();
        $this->assertSame(0, PingwinStockWrite::count());
        config(['services.pingwin.stock_write_allowlist' => '101335,100415']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])->assertStatus(202);
    }

    public function test_the_job_rereads_updates_the_mirror_and_reports_conflicts(): void
    {
        $reread = self::stockRead('100415', [
            ['warehouse_id' => 'WBO', 'qnt' => 5112.0, 'pcm' => 0.534564], ['warehouse_id' => 'WB', 'qnt' => 1896.0, 'pcm' => 0.527748, 'min_stock' => 5.0, 'max_stock' => 50.0],
            ['warehouse_id' => 'WC', 'qnt' => 3432.0, 'pcm' => 0.527746],
        ]);
        $this->respond = fn (array $p) => ['ok' => true, 'saved' => true, 'checks' => ['min_stock' => true, 'max_stock' => true], 'before' => [], 'after' => $reread['rows'][1], 'reread' => $reread];
        $w = PingwinStockWrite::create(['company_id' => $this->c, 'user_id' => $this->admin->id, 'kind' => 'limites', 'product_pingwin_id' => '100415', 'warehouse_pingwin_id' => 'WB',
            'payload' => ['min_stock' => 5, 'max_stock' => 50], 'expected' => ['min_stock' => null, 'max_stock' => null, 'disable_generic' => 0], 'status' => 'queued']);
        (new WritePingwinStockJob($w->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('ok', $w->fresh()->status);
        $this->assertSame(['mode' => 'stock_limits_write', 'product_id' => '100415', 'warehouse_id' => 'WB', 'allowed_product_ids' => []],
            array_intersect_key($this->calls[0], array_flip(['mode', 'product_id', 'warehouse_id', 'allowed_product_ids'])));
        $this->assertSame([5.0, 50.0], [PingwinStock::where('warehouse_pingwin_id', 'WB')->where('product_pingwin_id', '100415')->value('min_stock'),
            PingwinStock::where('warehouse_pingwin_id', 'WB')->where('product_pingwin_id', '100415')->value('max_stock')]);

        // O PingWin tinha outro valor: não grava.
        $this->respond = fn () => ['ok' => false, 'saved' => false, 'conflict' => true, 'diff' => ['min_stock' => ['esperado' => null, 'pingwin' => 3]]];
        $w2 = PingwinStockWrite::create(['company_id' => $this->c, 'kind' => 'limites', 'product_pingwin_id' => '100415', 'warehouse_pingwin_id' => 'WB', 'payload' => ['min_stock' => 7], 'expected' => [], 'status' => 'queued']);
        (new WritePingwinStockJob($w2->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('conflict', $w2->fresh()->status);

        // Gravado, mas a releitura não bate.
        $this->respond = fn () => ['ok' => false, 'saved' => true, 'checks' => ['min_stock' => false], 'reread' => $reread];
        $w3 = PingwinStockWrite::create(['company_id' => $this->c, 'kind' => 'limites', 'product_pingwin_id' => '100415', 'warehouse_pingwin_id' => 'WB', 'payload' => ['min_stock' => 8], 'expected' => [], 'status' => 'queued']);
        (new WritePingwinStockJob($w3->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('mismatch', $w3->fresh()->status);

        // A guarda do Python (ou qualquer erro): falha, sem repetir.
        $this->respond = fn () => ['ok' => false, 'error' => 'RuntimeError: stock: escrita recusada no artigo 100415 (só autorizado em 101335).'];
        $w4 = PingwinStockWrite::create(['company_id' => $this->c, 'kind' => 'limites', 'product_pingwin_id' => '100415', 'warehouse_pingwin_id' => 'WB', 'payload' => ['min_stock' => 9], 'expected' => [], 'status' => 'queued']);
        (new WritePingwinStockJob($w4->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame(['failed', 'Neste ambiente esta gravação não é permitida (guarda do dev).'], [$w4->fresh()->status, $w4->fresh()->error]);
    }

    // ── Armazéns ─────────────────────────────────────────────────────────────

    public function test_warehouses_list_create_edit_and_delete_rules(): void
    {
        Bus::fake();
        $list = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/warehouses'))->assertOk()->json('data');
        $this->assertSame(['WBO', 'WC', 'WB'], array_column($list['warehouses'], 'id'));            // por código: 1, 2, BAIXA
        $this->assertSame(['Yuko Baixa'], collect($list['warehouses'])->firstWhere('id', 'WB')['default_for']);
        $this->assertSame([['id' => 0, 'label' => 'PCM'], ['id' => 1, 'label' => 'PCU']], $list['costmethods']);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'armazém yuko bo'])->assertStatus(422);   // nome repetido
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'XPLENDOR TESTE', 'store_ids' => ['S9']])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'XPLENDOR TESTE', 'store_ids' => ['BO'], 'inv_costmethod' => 7])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'XPLENDOR TESTE', 'store_ids' => ['BO'], 'inv_costmethod' => 1, 'exit_costmethod' => 1])
            ->assertStatus(202);
        $w = PingwinStockWrite::firstOrFail();
        $this->assertSame(['armazem_criar', ['description' => 'XPLENDOR TESTE', 'inv_costmethod' => 1, 'exit_costmethod' => 1, 'store_ids' => ['BO']]], [$w->kind, $w->payload]);
        $w->update(['status' => 'ok']);

        // Editar: só o que muda, com o que a pessoa viu para o PingWin comparar.
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/WB'), ['description' => 'Armazém Yuko Baixa', 'store_ids' => ['S1', 'BO'], 'inv_costmethod' => 1])->assertStatus(202);
        $e = PingwinStockWrite::latest('id')->first();
        $this->assertSame(['inv_costmethod' => 1], $e->payload);
        $this->assertSame(['description' => 'Armazém Yuko Baixa', 'inv_costmethod' => 0, 'exit_costmethod' => 0, 'store_ids' => ['BO', 'S1']], $e->expected);
        $e->update(['status' => 'ok']);

        // Apagar: com stock, recusa; sem stock, só com o nome escrito outra vez.
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url('/warehouses/WB'), ['confirm_name' => 'Armazém Yuko Baixa'])
            ->assertStatus(422)->assertJsonFragment(['Só se apaga um armazém sem stock. Este tem stock em 2 artigos.']);
        $this->warehouse('WT', '4', 'XPLENDOR TESTE', ['BO']);
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url('/warehouses/WT'), ['confirm_name' => 'xplendor'])->assertStatus(422)->assertJsonValidationErrors('confirm_name');
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url('/warehouses/WT'), ['confirm_name' => 'XPLENDOR TESTE'])->assertStatus(202);
        $this->assertSame('armazem_apagar', PingwinStockWrite::latest('id')->value('kind'));
    }

    public function test_dev_guard_on_warehouses_by_name(): void
    {
        Bus::fake();
        config(['services.pingwin.warehouse_write_allowlist' => 'XPLENDOR TESTE']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'Armazém Novo'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/WB'), ['inv_costmethod' => 1])->assertForbidden();
        $this->warehouse('WT', '4', 'XPLENDOR TESTE', ['BO']);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/WT'), ['description' => 'Outro nome'])->assertForbidden();   // nem mudar o nome para fora
        $this->assertSame(0, PingwinStockWrite::count());
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'XPLENDOR TESTE 2'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/WT'), ['inv_costmethod' => 1])->assertStatus(202);
    }

    public function test_the_job_mirrors_the_created_and_deleted_warehouse(): void
    {
        $form = ['id' => 'WN', 'code' => '4', 'description' => 'XPLENDOR TESTE', 'deleted' => 0, 'inv_costmethod' => 0, 'exit_costmethod' => 0, 'main_store_id' => null,
            'stores' => [['store_id' => 'BO', 'code' => 'BO', 'name' => 'Yuko BO', 'served' => true]]];
        $this->respond = fn () => ['ok' => true, 'saved' => true, 'pingwin_id' => 'WN', 'code' => '4', 'checks' => ['ativo' => true], 'reread' => $form];
        $w = PingwinStockWrite::create(['company_id' => $this->c, 'kind' => 'armazem_criar', 'payload' => ['description' => 'XPLENDOR TESTE'], 'status' => 'queued']);
        (new WritePingwinStockJob($w->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame(['ok', 'WN'], [$w->fresh()->status, $w->fresh()->warehouse_pingwin_id]);
        $this->assertSame(['BO'], PingwinWarehouse::where('pingwin_id', 'WN')->first()->servedStoreIds());
        $this->assertSame(['mode' => 'warehouse_create', 'fields' => ['description' => 'XPLENDOR TESTE'], 'allowed_warehouse_names' => []], $this->calls[0] ? array_intersect_key($this->calls[0], array_flip(['mode', 'fields', 'allowed_warehouse_names'])) : []);

        RestaurantStoreWarehouse::create(['company_id' => $this->c, 'store_pingwin_id' => 'S1', 'warehouse_pingwin_id' => 'WN']);
        $this->respond = fn () => ['ok' => true, 'deleted' => true, 'in_state0_ativos' => false, 'in_state1_anulados' => true];
        $d = PingwinStockWrite::create(['company_id' => $this->c, 'kind' => 'armazem_apagar', 'warehouse_pingwin_id' => 'WN', 'payload' => ['description' => 'XPLENDOR TESTE'], 'status' => 'queued']);
        (new WritePingwinStockJob($d->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('ok', $d->fresh()->status);
        $this->assertTrue(PingwinWarehouse::where('pingwin_id', 'WN')->value('deleted'));
        $this->assertSame(0, RestaurantStoreWarehouse::count());   // a escolha da loja que apontava para ele sai
    }

    public function test_store_default_warehouse_is_set_in_the_xplendor_only(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/store-defaults'), ['store_id' => 'S1', 'warehouse_id' => 'WBO'])->assertOk();
        $this->assertSame('WBO', RestaurantStoreWarehouse::where('store_pingwin_id', 'S1')->value('warehouse_pingwin_id'));
        $this->assertSame([], $this->calls);   // nada vai para o PingWin
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/store-defaults'), ['store_id' => 'S1', 'warehouse_id' => null])->assertOk();
        $this->assertSame(0, RestaurantStoreWarehouse::count());
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/store-defaults'), ['store_id' => 'S9', 'warehouse_id' => 'WBO'])->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/warehouses/store-defaults'), ['store_id' => 'S1', 'warehouse_id' => 'NOPE'])->assertNotFound();
    }

    // ── ACL e isolamento ─────────────────────────────────────────────────────

    public function test_acl_view_vs_edit_and_company_isolation(): void
    {
        Bus::fake();
        $viewer = $this->userWith(['restauracao.ver', 'financas.ver']);
        $this->actingAs($viewer, 'sanctum')->getJson($this->url('/stock'))->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson($this->url('/warehouses'))->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/stock/100415/refresh'))->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/warehouses'), ['description' => 'X'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson($this->url('/warehouses/WB'), ['inv_costmethod' => 1])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->deleteJson($this->url('/warehouses/WB'), ['confirm_name' => 'Armazém Yuko Baixa'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson($this->url('/warehouses/store-defaults'), ['store_id' => 'S1', 'warehouse_id' => 'WBO'])->assertForbidden();
        $nobody = $this->userWith(['financas.ver']);
        $this->actingAs($nobody, 'sanctum')->getJson($this->url('/stock'))->assertForbidden();
        $this->actingAs($nobody, 'sanctum')->getJson($this->url('/warehouses'))->assertForbidden();

        $cook = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $this->actingAs($cook, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])->assertStatus(202);
        $writeId = PingwinStockWrite::value('id');
        $this->actingAs($cook, 'sanctum')->getJson($this->url("/stock/writes/{$writeId}"))->assertOk()->assertJsonPath('data.write.kind', 'limites');

        // Outra empresa: não vê nem escreve nesta, nem com os ids desta na dela.
        $other = Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/stock'))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/stock/100415'))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson($this->url('/stock/100415/limits'), ['warehouse_id' => 'WB', 'min_stock' => 5])->assertForbidden();
        $own = "/api/v1/companies/{$other->id}/restaurant";
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson("{$own}/stock")->assertOk()->json('data.stock.data'));
        $this->actingAs($stranger, 'sanctum')->getJson("{$own}/stock/100415")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("{$own}/stock/100415/limits", ['warehouse_id' => 'WB', 'min_stock' => 5])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("{$own}/stock/writes/{$writeId}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson("{$own}/warehouses/WB", ['confirm_name' => 'Armazém Yuko Baixa'])->assertStatus(422);
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson("{$own}/warehouses")->assertOk()->json('data.warehouses'));
    }
}
