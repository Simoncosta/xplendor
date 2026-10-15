<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\WritePingwinCountJob;
use App\Models\PermissionProfile;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinStockWrite;
use App\Models\ProductUnitConversion;
use App\Models\StockCount;
use App\Models\StockCountZone;
use App\Models\StockCountZoneItem;
use App\Models\User;
use App\Services\PingwinService;
use App\Services\Restaurant\StockCountService;
use App\Services\Restaurant\StockSyncService;
use App\Services\Restaurant\StockUnits;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7b — contagens: a data pelo momento, o ABC, a soma de unidades (só a de stock e as conversões
 * confirmadas, nunca os fatores do PingWin), vazio × 0, a revisão e a recontagem, o envio bloqueado
 * enquanto o PASSO 0.3 não estiver provado, a guarda, o job (ok / 500 / unidade), a ACL e a cega.
 */
class StockCountTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private User $admin;

    /** @var list<array> */
    public array $calls = [];

    /** @var callable(array): array */
    public $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
        $this->admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin', 'name' => 'Ana Gestora']);
        foreach ([['A1', '1001', 'COCA COLA ZERO 0.35'], ['A2', '1002', 'AGUA 0.5'], ['A3', '1003', 'LIMAO'], ['A4', '1004', 'GUARDANAPOS']] as [$id, $code, $d]) {
            PingwinCountableProduct::create(['company_id' => $this->c, 'product_id' => $id, 'code' => $code, 'description' => $d, 'refreshed_at' => now()]);
            $this->catalog($this->c, $id, ['code' => $code, 'description' => $d, 'forsale' => false, 'forpurchase' => true]);
            $this->movestock($id);
        }
        $this->stock('A1', 'WB', 1896, 0.5);
        $this->stock('A2', 'WB', 100, 0.3);
        $this->stock('A3', 'WB', 4, 0.2);
        // A4 não tem stock nem movimento na Baixa: não entra.
        $this->respond = fn (array $p) => ['ok' => false, 'error' => 'sem resposta'];
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                $this->test->calls[] = $payload;

                return ($this->test->respond)($payload);
            }
        });
        config(['services.pingwin.stockdoc_write_allowlist' => '', 'stock_counts.unlisted_untouched_proven' => true]);
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->c}/restaurant/stock-counts{$tail}";
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
    }

    private function newCount(string $scope = 'completa', ?int $zone = null): StockCount
    {
        return (new StockCountService($this->c))->create('WB', $scope, $zone, 'antes_abertura', true, $this->admin->id);
    }

    public function test_date_by_moment_and_abc(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 23:30', 'Europe/Lisbon');
        $this->assertSame('2026-10-15', StockCountService::inventoryDate('antes_abertura', $now));
        $this->assertSame('2026-10-16', StockCountService::inventoryDate('depois_fecho', $now));   // o PingWin só guarda o dia

        $this->assertSame(['x' => 'A', 'y' => 'A', 'z' => 'B', 'w' => 'C'], StockCountService::classify(['x' => 70, 'y' => 15, 'z' => 10, 'w' => 5]));
        $abc = (new StockCountService($this->c))->abc('WB');   // 948 € (A); 30 € entra com 96,9% já acumulados (C); 0,80 € (C)
        $this->assertSame(['A1' => 'A', 'A2' => 'C', 'A3' => 'C'], $abc);
    }

    public function test_units_only_stock_unit_and_confirmed_conversions(): void
    {
        $u = new StockUnits($this->c);
        $this->assertSame([['unit' => 'Unidade', 'factor' => 1.0, 'source' => 'stock']], $u->for('A1')['units']);
        // A "Caixa 6" do PingWin (unit_value 1) não conta: sem conversão confirmada, é recusada.
        try {
            $u->sum('A1', [['qty' => 2, 'unit' => 'Caixa 6']]);
            $this->fail('devia recusar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('não tem uma conversão confirmada', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'A1', 'unit_key' => 'GRD24', 'unit_label' => 'Grade 24', 'supplier_nif' => '', 'factor_to_base' => 24, 'source' => 'confirmado']);
        $u = new StockUnits($this->c);
        $this->assertSame(['entries' => [['qty' => 2.0, 'unit' => 'Grade 24', 'factor' => 24.0], ['qty' => 5.0, 'unit' => 'Unidade', 'factor' => 1.0]], 'qty_base' => 53.0],
            $u->sum('A1', [['qty' => '2', 'unit' => 'Grade 24'], ['qty' => '5', 'unit' => 'Unidade']]));
        $this->assertSame(['entries' => [], 'qty_base' => null], $u->sum('A1', [['qty' => '', 'unit' => 'Unidade']]));     // vazio = não contado
        $this->assertSame(0.0, $u->sum('A1', [['qty' => '0', 'unit' => 'Unidade']])['qty_base']);                       // 0 = zero contado
        $this->assertSame(1.5, $u->sum('A1', [['qty' => '1,5']])['qty_base']);
    }

    public function test_create_counting_lines_zone_order_and_blind_for_counters(): void
    {
        $zone = StockCountZone::create(['company_id' => $this->c, 'warehouse_id' => 'WB', 'name' => 'Câmara', 'position' => 0]);
        StockCountZoneItem::create(['zone_id' => $zone->id, 'product_id' => 'A3', 'position' => 0]);
        $counter = $this->userWith(['restauracao.ver']);
        $res = $this->actingAs($counter, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WB', 'scope' => 'completa', 'moment' => 'depois_fecho', 'blind' => false])->assertCreated();
        $this->assertTrue($res->json('data.count.blind'));                       // só o gestor desliga a cega
        $id = $res->json('data.count.id');
        $show = $this->actingAs($counter, 'sanctum')->getJson($this->url("/{$id}"))->assertOk()->json('data');
        $this->assertSame(['A3', 'A2', 'A1'], array_column($show['lines'], 'product_id'));   // zona primeiro; "Sem zona" no fim, por nome
        $this->assertSame([null, null, null], array_column($show['lines'], 'theoretical'));   // cega: sem teórico
        $this->assertArrayNotHasKey('review', $show);
        $this->assertSame('2026-10-16', $show['count']['inventory_date']);

        $a = $this->newCount('artigos_a');
        $this->assertSame(['A1'], $a->lines()->pluck('product_id')->all());
    }

    public function test_save_line_empty_vs_zero_then_review_and_recount(): void
    {
        $count = $this->newCount();
        $lines = $count->lines()->get()->keyBy('product_id');
        $counter = $this->userWith(['restauracao.ver']);
        $this->actingAs($counter, 'sanctum')->putJson($this->url("/{$count->id}/lines/{$lines['A1']->id}"), ['entries' => [['qty' => '1800', 'unit' => 'Unidade'], ['qty' => '3', 'unit' => 'Unidade']]])
            ->assertOk()->assertJsonPath('data.line.qty_base', 1803);
        $this->actingAs($counter, 'sanctum')->putJson($this->url("/{$count->id}/lines/{$lines['A2']->id}"), ['entries' => [['qty' => '0', 'unit' => 'Unidade']]])->assertOk()->assertJsonPath('data.line.qty_base', 0);
        $this->actingAs($counter, 'sanctum')->putJson($this->url("/{$count->id}/lines/{$lines['A3']->id}"), ['entries' => []])->assertOk()->assertJsonPath('data.line.qty_base', null);
        $this->actingAs($counter, 'sanctum')->postJson($this->url("/{$count->id}/finish"))->assertOk()->assertJsonPath('data.count.status', 'em_revisao');
        $this->actingAs($counter, 'sanctum')->postJson($this->url("/{$count->id}/send"))->assertForbidden();             // só o gestor envia
        $this->actingAs($counter, 'sanctum')->postJson($this->url("/{$count->id}/recount"), ['line_ids' => [1]])->assertForbidden();

        $review = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/{$count->id}"))->assertOk()->json('data.review');
        $rows = collect($review['rows'])->keyBy('product_id');
        $this->assertSame(['A1', 'A2', 'A3'], array_column($review['rows'], 'product_id'));   // por |€|: 46,50 € e 30 €; A3 não contado no fim
        $this->assertEqualsWithDelta(-93.0, $rows['A1']['diff'], 1e-9);
        $this->assertEqualsWithDelta(-46.5, $rows['A1']['diff_eur'], 1e-9);
        $this->assertTrue($rows['A1']['recount_suggested']);                                 // |€| ≥ 25
        $this->assertTrue($rows['A2']['recount_suggested']);                                 // −100%
        $this->assertEquals(['counted' => 2, 'not_counted' => 1, 'with_diff' => 2], array_intersect_key($review['totals'], array_flip(['counted', 'not_counted', 'with_diff'])));

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/recount"), ['line_ids' => [$lines['A1']->id]])->assertOk();
        $l = $lines['A1']->fresh();
        $this->assertSame([null, 1803.0, true], [$l->qty_base, $l->previous_qty_base, $l->recount_requested]);
        $this->assertSame(['em_contagem', true], [$count->fresh()->status, $count->fresh()->blind]);   // volta ao contador, cega
    }

    public function test_send_is_blocked_until_partial_counts_are_proven_and_by_the_guard(): void
    {
        Bus::fake();
        $count = $this->newCount();
        (new StockCountService($this->c))->saveLine($count, $count->lines()->where('product_id', 'A1')->first(), [['qty' => 1890]], $this->admin->id);
        $count->update(['status' => 'em_revisao']);

        config(['stock_counts.unlisted_untouched_proven' => false]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/send"))->assertStatus(422)
            ->assertJsonFragment(['O envio está bloqueado: ainda não ficou provado no PingWin que uma Contagem só altera os artigos que lista. Até lá, os artigos não contados poderiam ficar a zero.']);
        config(['stock_counts.unlisted_untouched_proven' => true, 'services.pingwin.stockdoc_write_allowlist' => '0']);
        $this->assertSame('desligada', $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/{$count->id}"))->json('data.review.send_block.mode'));
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/send"))->assertStatus(422);
        config(['services.pingwin.stockdoc_write_allowlist' => '9999']);
        $this->assertSame('lista', (new StockCountService($this->c))->sendBlock($count)['mode']);
        config(['services.pingwin.stockdoc_write_allowlist' => '1001']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/send"))->assertStatus(202);
        $this->assertSame('a_enviar', $count->fresh()->status);
        Bus::assertDispatched(WritePingwinCountJob::class);
        $this->assertSame(0, count($this->calls));                                                      // nada foi ao PingWin no pedido
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/send"))->assertStatus(422);   // um documento por ação
    }

    private function sentCount(): array
    {
        $count = $this->newCount();
        $svc = new StockCountService($this->c);
        $svc->saveLine($count, $count->lines()->where('product_id', 'A1')->first(), [['qty' => 1890]], $this->admin->id);
        $svc->saveLine($count, $count->lines()->where('product_id', 'A2')->first(), [['qty' => 0]], $this->admin->id);
        $count->update(['status' => 'em_revisao']);
        Bus::fake();
        $svc->approveAndSend($count, $this->admin->id);

        return [$count->fresh(), PingwinStockWrite::latest('id')->first()];
    }

    public function test_job_sends_in_the_stock_unit_confirms_by_list_and_movements(): void
    {
        $this->stock('A1', 'WB', 1896, 0.5, ['unit_name' => 'Unidade']);
        \App\Models\PingwinStockItem::where('product_pingwin_id', 'A1')->update(['stock_unit_id' => '11001']);
        \App\Models\PingwinStockItem::where('product_pingwin_id', 'A2')->update(['stock_unit_id' => '11001']);
        [$count, $write] = $this->sentCount();
        $this->respond = function (array $p) {
            return match ($p['mode']) {
                'count_write' => ['ok' => true, 'saved' => true, 'doc_id' => 'D1', 'doc_number' => 'CNTW BOCNTW/2', 'steps' => ['cabecalho'], 'lines' => [
                    ['product_id' => 'A1', 'unit_id' => '11001', 'theoretical_qnt' => 1896, 'unit_cost' => 0.5, 'qnt_difference' => -6, 'price_difference' => -3],
                    ['product_id' => 'A2', 'unit_id' => '11001', 'theoretical_qnt' => 100, 'unit_cost' => 0.3, 'qnt_difference' => -100, 'price_difference' => -30]]],
                'stock_documents' => ['ok' => true, 'documents' => [['id' => 'D1', 'docstatus_id' => '8002', 'entity_id' => 'WB', 'document' => 'CNTW BOCNTW/2']]],
                // O tbstock não reflete a Contagem logo a seguir (passo 0-A da 7c): a confirmação vem dos Movimentos.
                'stock' => ['ok' => true, 'errors' => [], 'seconds' => [], 'stock' => [
                    'A1' => self::stockRead('A1', [['warehouse_id' => 'WB', 'qnt' => 1896.0]]), 'A2' => self::stockRead('A2', [['warehouse_id' => 'WB', 'qnt' => 100.0]])]],
                'movements' => ['ok' => true, 'movements' => [
                    ['docheader_id' => 'D1', 'warehouse_id' => 'WB', 'product_code' => '1001', 'bom_code' => '1001', 'qnt' => 1890, 'stk_signal' => 1, 'docstatus' => 'Fechado'],
                    ['docheader_id' => 'D1', 'warehouse_id' => 'WB', 'product_code' => '1002', 'bom_code' => '1002', 'qnt' => 0, 'stk_signal' => 1, 'docstatus' => 'Fechado'],
                    ['docheader_id' => 'OUTRO', 'warehouse_id' => 'WB', 'product_code' => '1001', 'bom_code' => '1001', 'qnt' => 7, 'stk_signal' => 1, 'docstatus' => 'Fechado']]],
                default => ['ok' => false],
            };
        };
        (new WritePingwinCountJob($write->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame(['code_from' => '1001', 'code_to' => '1002'], array_intersect_key(collect($this->calls)->firstWhere('mode', 'movements'), array_flip(['code_from', 'code_to'])));
        $sent = collect($this->calls)->firstWhere('mode', 'count_write');
        $this->assertSame(['store_id' => 'BO', 'warehouse_id' => 'WB', 'inventory_date' => '2026-10-15', 'docstatus' => '8002', 'allowed_codes' => []],
            array_intersect_key($sent, array_flip(['store_id', 'warehouse_id', 'inventory_date', 'docstatus', 'allowed_codes'])));
        $this->assertSame([['product_id' => 'A1', 'code' => '1001', 'description' => 'COCA COLA ZERO 0.35', 'physical_qnt' => 1890.0, 'unit_id' => '11001'],
            ['product_id' => 'A2', 'code' => '1002', 'description' => 'AGUA 0.5', 'physical_qnt' => 0.0, 'unit_id' => '11001']], $sent['lines']);
        $count->refresh();
        $this->assertSame(['enviada', 'CNTW BOCNTW/2', 'ok'], [$count->status, $count->pingwin_doc_number, $write->fresh()->status]);
        $this->assertNotNull($count->confirmed_at);
        $l = $count->lines()->where('product_id', 'A1')->first();
        $this->assertSame([1896.0, -6.0, -3.0, 'confirmado', 1890.0], [$l->pingwin_theoretical_qnt, $l->pingwin_qnt_difference, $l->pingwin_price_difference, $l->confirm_status, $l->confirmed_qty]);
    }

    public function test_job_save_500_fails_without_retry_and_shows_the_pingwin_message(): void
    {
        \App\Models\PingwinStockItem::query()->update(['stock_unit_id' => '11001']);
        [$count, $write] = $this->sentCount();
        $this->respond = fn () => ['ok' => false, 'saved' => false, 'pingwin_message' => 'Existem documentos que estão num estado em que não atualizam stock.',
            'error' => 'Existem documentos que estão num estado em que não atualizam stock. O documento não foi gravado no PingWin.'];
        (new WritePingwinCountJob($write->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame(['falhou', 'Existem documentos que estão num estado em que não atualizam stock. O documento não foi gravado no PingWin.'], [$count->fresh()->status, $count->fresh()->error_message]);
        $this->assertSame(['failed', 1], [$write->fresh()->status, count($this->calls)]);
        // A guarda do Python: falha com a explicação, sem gravar.
        $count->update(['status' => 'em_revisao']);
        $w2 = (new StockCountService($this->c))->approveAndSend($count, $this->admin->id);
        $this->respond = fn () => ['ok' => false, 'error' => 'RuntimeError: documento de stock: escrita recusada (artigos fora da guarda: 1001).'];
        (new WritePingwinCountJob($w2->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertStringStartsWith('Neste ambiente esta gravação não é permitida', $count->fresh()->error_message);
    }

    public function test_job_refuses_before_pingwin_when_the_stock_unit_is_unknown(): void
    {
        [$count, $write] = $this->sentCount();     // stock_unit_id desconhecido
        (new WritePingwinCountJob($write->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame([], $this->calls);
        $this->assertStringStartsWith('Não se sabe a unidade de stock de 1001', $count->fresh()->error_message);
    }

    public function test_void_after_sent_and_company_isolation(): void
    {
        \App\Models\PingwinStockItem::query()->update(['stock_unit_id' => '11001']);
        [$count] = $this->sentCount();
        $count->update(['status' => 'enviada', 'pingwin_doc_id' => 'D1', 'pingwin_doc_number' => 'CNTW BOCNTW/2']);
        Bus::fake();
        $this->actingAs($this->userWith(['restauracao.ver']), 'sanctum')->postJson($this->url("/{$count->id}/void"))->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/{$count->id}/void"))->assertStatus(202);
        $this->assertSame(['target' => 'contagem', 'id' => $count->id, 'doc_id' => 'D1', 'doc_number' => 'CNTW BOCNTW/2'], PingwinStockWrite::latest('id')->first()->payload);
        Bus::assertDispatched(\App\Jobs\VoidPingwinStockDocumentJob::class);

        $other = \App\Models\Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => \Illuminate\Support\Facades\DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(\App\Services\CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url("/{$count->id}"))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$other->id}/restaurant/stock-counts/{$count->id}")->assertNotFound();
    }
}
