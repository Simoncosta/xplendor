<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\WritePingwinWasteJob;
use App\Models\PermissionProfile;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockWrite;
use App\Models\ProductUnitConversion;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Models\WasteBatch;
use App\Models\WasteEntry;
use App\Services\PingwinService;
use App\Services\Restaurant\RealConsumptionService;
use App\Services\Restaurant\StockMovementsService;
use App\Services\Restaurant\StockSyncService;
use App\Services\Restaurant\WasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7b — desperdício (registo, explosão da ficha num só nível, motivos, editar e apagar antes de
 * enviar, envio por armazém com a guarda, o job e o relatório) e consumo real × teórico (a fórmula,
 * as transferências, o desperdício, as unidades sem conversão confirmada).
 */
class WasteAndConsumptionTest extends TestCase
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
        foreach ([['PAO', '2001', 'PAO'], ['MOLHO', '100162', 'MOLHO FRANCESINHA 30LT'], ['BIFE', '2002', 'BIFE'], ['VINHO', '2003', 'VINHO']] as [$id, $code, $d]) {
            PingwinCountableProduct::create(['company_id' => $this->c, 'product_id' => $id, 'code' => $code, 'description' => $d, 'refreshed_at' => now()]);
            $this->catalog($this->c, $id, ['code' => $code, 'description' => $d, 'forsale' => false, 'forpurchase' => true]);
            $this->movestock($id);
            PingwinStockItem::where('product_pingwin_id', $id)->update(['stock_unit_id' => '11001']);
            $this->stock($id, 'WC', 100, 1.5);
        }
        $this->catalog($this->c, 'FRAN', ['code' => '227', 'description' => 'Francesinha', 'forsale' => true]);
        // A francesinha: 2 pães, 0,2 bife e 0,1 do molho (preparado da cozinha com stock: vai como está).
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BF', '2026-05-26', ['S2' => true], [
            $this->bomLine('PAO', 2), $this->bomLine('BIFE', 0.2, ['_i' => 1]), $this->bomLine('MOLHO', 0.1, ['_i' => 2, 'isbom' => 1]),
        ]]]));
        $this->persistBom($this->c, $this->bom('MOLHO', [['00000000', 'BM', '2026-05-26', ['BO' => true], [$this->bomLine('VINHO', 15)]]]));
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
        config(['services.pingwin.stockdoc_write_allowlist' => '', 'stock_counts.waste_send_proven' => true]);
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->c}/restaurant/waste{$tail}";
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
    }

    public function test_register_dish_explodes_one_level_and_reasons(): void
    {
        $cook = $this->userWith(['restauracao.ver']);
        $this->actingAs($cook, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WC', 'product_id' => 'FRAN', 'qty' => '3', 'reason' => 'devolvido'])->assertCreated();
        $e = WasteEntry::firstOrFail();
        $this->assertSame(['FRAN', 3.0, 'FRAN'], [$e->product_id, $e->qty_base, $e->source_product_id]);
        $this->assertEqualsWithDelta((6 + 0.6 + 0.3) * 1.5, $e->value_estimate, 1e-6);
        $parts = collect((new WasteService($this->c))->explode('FRAN', 3, 'WC', '2026-10-15'))->pluck('qty', 'product_id')->all();
        $this->assertEqualsCanonicalizing(['PAO' => 6.0, 'BIFE' => 0.6, 'MOLHO' => 0.3], $parts);   // o molho vai como está (um só nível)
        $this->assertArrayNotHasKey('VINHO', $parts);

        $this->actingAs($cook, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WC', 'product_id' => 'PAO', 'qty' => '2', 'reason' => 'outro'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($cook, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WC', 'product_id' => 'PAO', 'qty' => '0', 'reason' => 'validade'])->assertStatus(422);
        $this->actingAs($cook, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WC', 'product_id' => 'PAO', 'qty' => '1', 'unit' => 'Saco 10', 'reason' => 'validade'])->assertStatus(422);
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'PAO', 'unit_key' => 'S10', 'unit_label' => 'Saco 10', 'supplier_nif' => '', 'factor_to_base' => 10, 'source' => 'confirmado']);
        $this->actingAs($cook, 'sanctum')->postJson($this->url(), ['warehouse_id' => 'WC', 'product_id' => 'PAO', 'qty' => '1,5', 'unit' => 'Saco 10', 'reason' => 'outro', 'note' => 'caiu ao chão'])
            ->assertCreated()->assertJsonPath('data.entry.qty_base', 15);
    }

    public function test_send_one_document_per_warehouse_with_lines_and_obs_then_job(): void
    {
        $svc = new WasteService($this->c);
        $svc->save(null, ['warehouse_id' => 'WC', 'product_id' => 'FRAN', 'qty' => '1', 'reason' => 'devolvido'], $this->admin->id);
        $svc->save(null, ['warehouse_id' => 'WC', 'product_id' => 'VINHO', 'qty' => '2', 'reason' => 'partido'], $this->admin->id);
        $cook = $this->userWith(['restauracao.ver']);
        $this->actingAs($cook, 'sanctum')->postJson($this->url('/send'), ['warehouse_id' => 'WC'])->assertForbidden();

        config(['stock_counts.waste_send_proven' => false]);      // PASSO 0.5 por provar: bloqueado
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('?warehouse_id=WC'))->assertOk()->assertJsonPath('data.send_guard.mode', 'por_provar');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/send'), ['warehouse_id' => 'WC'])->assertStatus(422);
        config(['stock_counts.waste_send_proven' => true, 'services.pingwin.stockdoc_write_allowlist' => '0']);
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url('?warehouse_id=WC'))->assertOk()->assertJsonPath('data.send_guard.mode', 'desligada');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/send'), ['warehouse_id' => 'WC'])->assertStatus(422);
        config(['services.pingwin.stockdoc_write_allowlist' => '2001,2002,100162,2003']);
        Bus::fake();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/send'), ['warehouse_id' => 'WC'])->assertStatus(202);
        $batch = WasteBatch::firstOrFail();
        $this->assertEquals([['PAO', 2.0], ['BIFE', 0.2], ['MOLHO', 0.1], ['VINHO', 2.0]], array_map(fn ($l) => [$l['product_id'], $l['qty']], $batch->lines));
        $this->assertSame('Devolvido pelo cliente · Ana Gestora', $batch->lines[0]['obs']);
        $this->assertSame(2, WasteEntry::where('batch_id', $batch->id)->count());
        Bus::assertDispatched(WritePingwinWasteJob::class);
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url('/' . WasteEntry::first()->id))->assertStatus(422);   // já enviado

        $write = PingwinStockWrite::latest('id')->first();
        $this->respond = fn (array $p) => match ($p['mode']) {
            'waste_write' => ['ok' => true, 'saved' => true, 'doc_id' => 'W1', 'doc_number' => 'DESP BODESP/2', 'total' => 6.45, 'lines' => []],
            'stock_documents' => ['ok' => true, 'documents' => [['id' => 'W1', 'docstatus_id' => '8002', 'total' => 6.45, 'document' => 'DESP BODESP/2']]],
            'stock' => ['ok' => true, 'stock' => [], 'errors' => [], 'seconds' => []],
            default => ['ok' => false],
        };
        (new WritePingwinWasteJob($write->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $sent = collect($this->calls)->firstWhere('mode', 'waste_write');
        $this->assertSame(['WC', '8002', 'XPLENDOR · Desperdício · 2 registos', '2001,2002,100162,2003'],
            [$sent['warehouse_id'], $sent['docstatus'], $sent['header_obs'], implode(',', $sent['allowed_codes'])]);
        $this->assertEquals(['product_id' => 'PAO', 'code' => '2001', 'qnt' => 2.0, 'obs' => 'Devolvido pelo cliente · Ana Gestora', 'unit_id' => '11001'], $sent['lines'][0]);
        $this->assertSame(['enviada', 'DESP BODESP/2', 6.45, 'ok'], [$batch->fresh()->status, $batch->fresh()->pingwin_doc_number, $batch->fresh()->pingwin_total, $write->fresh()->status]);
    }

    public function test_failed_waste_send_returns_the_entries_to_pending(): void
    {
        $svc = new WasteService($this->c);
        $svc->save(null, ['warehouse_id' => 'WC', 'product_id' => 'VINHO', 'qty' => '2', 'reason' => 'partido'], $this->admin->id);
        Bus::fake();
        $write = $svc->send('WC', $this->admin->id);
        $this->respond = fn () => ['ok' => false, 'saved' => false, 'error' => 'O PingWin recusou o documento: Documento inválido'];
        (new WritePingwinWasteJob($write->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('falhou', WasteBatch::first()->status);
        $this->assertSame(1, WasteEntry::whereNull('batch_id')->count());
        $this->assertSame('failed', $write->fresh()->status);
    }

    public function test_report_by_reason_product_and_week_in_euros_only_with_finance(): void
    {
        $svc = new WasteService($this->c);
        $svc->save(null, ['warehouse_id' => 'WC', 'product_id' => 'VINHO', 'qty' => '2', 'reason' => 'partido'], $this->admin->id);
        $svc->save(null, ['warehouse_id' => 'WC', 'product_id' => 'PAO', 'qty' => '10', 'reason' => 'validade'], $this->admin->id);
        $r = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/report'))->assertOk()->json('data.report');
        $this->assertEquals([['validade', 15.0], ['partido', 3.0]], array_map(fn ($x) => [$x['key'], $x['value_eur']], $r['by_reason']));
        $this->assertEquals(18.0, $r['total_eur']);
        $cook = $this->userWith(['restauracao.ver']);
        $this->assertNull($this->actingAs($cook, 'sanctum')->getJson($this->url('/report'))->json('data.report.total_eur'));
    }

    public function test_movements_are_read_by_the_worker_and_then_served_from_cache(): void
    {
        Bus::fake([\App\Jobs\FetchStockMovementsJob::class]);
        $this->catalog($this->c, '5550001', ['code' => '2001', 'description' => 'PAO']);   // os ids do PingWin são numéricos
        $url = "/api/v1/companies/{$this->c}/restaurant/stock/5550001/movements?from=2026-07-17&to=2026-10-15";
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertStatus(202)->assertJsonPath('data.pending', true);
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertStatus(202);
        Bus::assertDispatchedTimes(\App\Jobs\FetchStockMovementsJob::class, 1);                 // uma leitura de cada vez
        $this->assertSame([], $this->calls);                                                       // o pedido HTTP não fala com o PingWin

        $this->respond = fn (array $p) => ['ok' => true, 'movements' => [
            ['bom_code' => '2001', 'product_code' => 'XP', 'doc_date' => '2026-09-01', 'document' => 'VFT/1', 'docconfig_id' => '1209', 'unit_description' => 'Unidade', 'qnt' => 4, 'stk_signal' => 1, 'warehouse_id' => 'WC', 'docstatus' => 'Fechado'],
            ['bom_code' => '2001', 'product_code' => 'XP', 'doc_date' => '2026-09-02', 'document' => 'ENC/1', 'docconfig_id' => '1200', 'unit_description' => 'Unidade', 'qnt' => 9, 'stk_signal' => 0, 'warehouse_id' => 'WC', 'docstatus' => 'Fechado'],
        ]];
        (new \App\Jobs\FetchStockMovementsJob($this->c, '2026-07-17', '2026-10-15', '2001', '2001'))->handle(app(StockMovementsService::class));
        $rows = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('data.movements');
        $this->assertSame([['ENC/1', 0, false], ['VFT/1', 4, true]], array_map(fn ($m) => [$m['document'], (int) $m['signed_qty'], $m['moves_stock']], $rows));
    }

    public function test_real_consumption_between_two_confirmed_counts(): void
    {
        $mk = function (string $date, array $qty, string $doc) {
            $c = StockCount::create(['company_id' => $this->c, 'warehouse_id' => 'WC', 'scope' => 'completa', 'moment' => 'antes_abertura', 'inventory_date' => $date,
                'blind' => true, 'status' => 'enviada', 'pingwin_doc_number' => $doc, 'confirmed_at' => now()]);
            foreach ($qty as $pid => $q) {
                StockCountLine::create(['count_id' => $c->id, 'product_id' => $pid, 'code' => ['PAO' => '2001', 'BIFE' => '2002', 'VINHO' => '2003'][$pid], 'description' => $pid,
                    'stock_unit' => 'Unidade', 'qty_base' => $q, 'unit_cost_snapshot' => 1.5]);
            }
        };
        $mk('2026-10-01', ['PAO' => 100, 'BIFE' => 10, 'VINHO' => 20], 'CNTW/1');
        $mk('2026-10-08', ['PAO' => 40, 'BIFE' => 4, 'VINHO' => 5], 'CNTW/2');
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'PAO', 'unit_key' => 'S10', 'unit_label' => 'Saco 10', 'supplier_nif' => '', 'factor_to_base' => 10, 'source' => 'confirmado']);
        $m = fn ($code, $date, $doc, $cfg, $unit, $qnt, $sig, $wh = 'WC', $status = 'Fechado') => ['bom_code' => $code, 'product_code' => 'X' . $code, 'doc_date' => $date, 'document' => $doc,
            'docconfig_id' => $cfg, 'unit_description' => $unit, 'qnt' => $qnt, 'stk_signal' => $sig, 'warehouse_id' => $wh, 'docstatus' => $status];
        $this->respond = fn (array $p) => $p['mode'] === 'movements' ? ['ok' => true, 'movements' => [
            $m('2001', '2026-10-03', 'VFT/1', '1209', 'Saco 10', 3, 1),          // +30 pães
            $m('2001', '2026-10-04', 'GT/1', '1219', 'Unidade', 5, -1),          // transferência para fora
            $m('2001', '2026-10-05', 'VFT/2', '1209', 'Unidade', 7, 1, 'WB'),    // outro armazém: não conta
            $m('2001', '2026-10-06', 'ENC/1', '1200', 'Unidade', 9, 0),          // encomenda: não mexe
            $m('2001', '2026-10-08', 'VFT/3', '1209', 'Unidade', 50, 1),         // no dia da 2.ª contagem: fora
            $m('2001', '2026-10-02', 'CNTW/9', '1106', 'Unidade', 99, 1),        // contagens: fora
            $m('2003', '2026-10-03', 'VFT/4', '1209', 'Garrafão', 2, 1),         // sem conversão confirmada
        ]] : ['ok' => false];
        // Consumo teórico: 10 francesinhas vendidas na Costa Cabral (S2 → WC) entre as contagens.
        $this->soldDaily('FRAN', $this->locCosta, '2026-10-02', 2, 5);
        // Desperdício enviado nesse armazém: 3 pães.
        $b = WasteBatch::create(['company_id' => $this->c, 'warehouse_id' => 'WC', 'date' => '2026-10-04', 'status' => 'enviada']);
        WasteEntry::create(['company_id' => $this->c, 'warehouse_id' => 'WC', 'product_id' => 'PAO', 'code' => '2001', 'description' => 'PAO', 'qty' => 3, 'unit' => 'Unidade',
            'factor' => 1, 'qty_base' => 3, 'reason' => 'validade', 'registered_at' => '2026-10-04 10:00:00', 'batch_id' => $b->id]);

        $res = (new RealConsumptionService($this->c, app(StockMovementsService::class)))->compute('WC');
        $mv = collect($this->calls)->firstWhere('mode', 'movements');
        $this->assertSame(['2026-10-01', '2026-10-07', '2001', '2003'], [$mv['start'], $mv['end'], $mv['code_from'], $mv['code_to']]);   // uma chamada, do menor ao maior código
        $rows = collect($res['rows'])->keyBy('product_id');
        $pao = $rows['PAO'];
        $this->assertSame([100.0, 30.0, 5.0, 40.0, 85.0, 20.0, 3.0, 62.0, 93.0],
            [$pao['initial'], $pao['entries'], $pao['transfers_out'], $pao['final'], $pao['real'], $pao['theoretical'], $pao['waste'], $pao['deviation'], $pao['deviation_eur']]);
        $this->assertEqualsWithDelta(6.0 - 2.0, $rows['BIFE']['real'] - $rows['BIFE']['theoretical'], 1e-9);   // 10 − 4 = 6 real; 10 × 0,2 = 2 teórico
        $this->assertSame(['Garrafão'], $rows['VINHO']['without_conversion']);
        $this->assertNull($rows['VINHO']['deviation_eur']);
        $this->assertSame('Só é fiável com fichas técnicas corretas e transferências registadas no PingWin.', $res['warning']);
        $this->assertSame('PAO', $res['rows'][0]['product_id']);   // por |€|

        // Com uma só contagem confirmada, não aparece.
        StockCount::where('pingwin_doc_number', 'CNTW/1')->update(['confirmed_at' => null]);
        $this->assertNull((new RealConsumptionService($this->c, app(StockMovementsService::class)))->compute('WC'));
    }
}
