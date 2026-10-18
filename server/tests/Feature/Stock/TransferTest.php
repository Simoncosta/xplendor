<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\WritePingwinTransferJob;
use App\Models\PermissionProfile;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockWrite;
use App\Models\ProductUnitConversion;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRoute;
use App\Models\User;
use App\Services\PingwinService;
use App\Services\Restaurant\StockSyncService;
use App\Services\Restaurant\Transfers\TransferBlocked;
use App\Services\Restaurant\Transfers\TransferOrdersService;
use App\Services\Restaurant\Transfers\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7c — transferências do Economato para os restaurantes: o preço (PCM × fator), os bloqueios do
 * fator e da confirmação do preço, a escolha P2 (conversão da encomenda) ou P3 (Guia nova), os estados
 * (automática e manual, falhou a entrada e repetir, anular pela ordem inversa), as guardas e a ACL,
 * e a leitura das encomendas, Guias e Entradas.
 */
class TransferTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private User $admin;

    private StockTransferRoute $auto;

    private StockTransferRoute $manual;

    /** @var list<array> */
    public array $calls = [];

    /** @var callable(array): array */
    public $respond;

    private const ADDR = ['address' => 'Rua de Teste, 1', 'postalcode' => '4000-001', 'postalcode_description' => 'Porto', 'city' => 'Porto'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
        $this->admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin', 'name' => 'Ana Gestora']);
        foreach ([['A', '101343', 'TESTE XPLENDOR STOCK A'], ['B', '101344', 'TESTE XPLENDOR STOCK B']] as [$id, $code, $d]) {
            $this->catalog($this->c, $id, ['code' => $code, 'description' => $d, 'forsale' => false, 'forpurchase' => true]);
            $this->movestock($id);
            PingwinStockItem::where('product_pingwin_id', $id)->update(['stock_unit_id' => '11001']);
        }
        $this->stock('A', 'WBO', 60, 0.5);
        $this->stock('B', 'WBO', 10, 1.0);
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'A', 'unit_key' => 'CX6', 'unit_label' => 'Caixa 6 Unidades', 'supplier_nif' => '', 'factor_to_base' => 6, 'source' => 'confirmado']);
        $this->auto = StockTransferRoute::create(['company_id' => $this->c, 'name' => 'Economato → Original', 'origin_warehouse_id' => 'WBO', 'origin_store_id' => 'BO',
            'dest_warehouse_id' => 'WC', 'dest_store_id' => 'S2', 'auto_receive' => true, 'load_address' => self::ADDR, 'unload_address' => self::ADDR]);
        $this->manual = StockTransferRoute::create(['company_id' => $this->c, 'name' => 'Economato → Baixa', 'origin_warehouse_id' => 'WBO', 'origin_store_id' => 'BO',
            'dest_warehouse_id' => 'WB', 'dest_store_id' => 'S1', 'auto_receive' => false, 'load_address' => self::ADDR, 'unload_address' => self::ADDR]);
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
        config(['services.pingwin.stockdoc_write_allowlist' => '', 'stock_transfers.send_proven' => true, 'stock_transfers.new_guide_proven' => false]);
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->c}/restaurant/transfers{$tail}";
    }

    private function order(array $lines = [['A', 'Caixa 6 Unidades', 2, 1]]): StockTransferRequest
    {
        $r = StockTransferRequest::create(['company_id' => $this->c, 'pingwin_doc_id' => 'ENC35', 'docconfig_id' => '62590524561076436', 'kind' => 'encomenda',
            'document' => 'ENCF BOENCF/35', 'store_id' => 'S2', 'store_name' => 'Tabern Yuko Original', 'doc_date' => '2026-10-15', 'docstatus_id' => '8002', 'route_id' => $this->auto->id]);
        foreach ($lines as $i => [$pid, $unit, $qnt, $uv]) {
            $r->lines()->create(['line_number' => $i + 1, 'product_id' => $pid, 'code' => $pid === 'A' ? '101343' : '101344', 'description' => "Artigo {$pid}", 'qnt' => $qnt,
                'unit_desc' => $unit, 'unit_value' => $uv, 'qnt_base' => $qnt * $uv]);
        }

        return $r;
    }

    private static function saved(string $id, string $number, string $wh, array $lines): array
    {
        return ['ok' => true, 'saved' => true, 'doc_id' => $id, 'doc_number' => $number, 'lines' => array_map(fn ($l) => $l + ['warehouse_id' => $wh, 'unit_id' => '11001'], $lines)];
    }

    // ── Preparar: o preço e os bloqueios ────────────────────────────────────

    public function test_without_routes_they_are_proposed_from_the_data_economato_to_each_restaurant(): void
    {
        // Nada de lojas ou armazéns no código: o Economato é o armazém que não serve nenhuma loja com vendas
        // e o destino é o armazém de cada restaurante, com os nomes da base de dados.
        StockTransferRoute::where('company_id', $this->c)->delete();
        $this->assertSame(['warehouse_id' => 'WBO', 'store_id' => 'BO', 'name' => 'Armazém Yuko BO'], TransferService::economato($this->c));

        $routes = (new TransferService($this->c))->routes();

        $this->assertSame([['WBO', 'BO', 'WB', 'S1'], ['WBO', 'BO', 'WC', 'S2']],
            $routes->map(fn ($r) => [$r->origin_warehouse_id, $r->origin_store_id, $r->dest_warehouse_id, $r->dest_store_id])->all());
        $this->assertSame(['Armazém Yuko BO → Yuko Baixa', 'Armazém Yuko BO → Yuko Costa Cabral'], $routes->pluck('name')->all());
    }

    public function test_price_is_origin_pcm_times_the_confirmed_factor_and_the_guide_goes_in_stock_units(): void
    {
        $req = $this->order();
        $line = $req->lines()->first();
        $prep = (new TransferService($this->c))->prepare($this->auto, [['product_id' => 'A', 'qty' => 2, 'unit' => 'Caixa 6 Unidades', 'request_line_id' => $line->id, 'factor_ack' => true]]);
        $l = $prep['lines'][0];
        $this->assertSame([6.0, 12.0, 0.5, 3.0, 6.0, []], [$l['factor'], $l['qty_base'], $l['origin_pcm_eur'], $l['price_per_unit_eur'], $l['value_eur'], $l['blocks']]);
        $this->assertSame(6.0, $prep['total_value_eur']);
        $this->assertFalse($prep['blocked']);
    }

    public function test_factor_blocks_the_line_until_confirmed(): void
    {
        $req = $this->order([['A', 'Caixa 6 Unidades', 2, 1], ['B', 'Grade 24', 1, 24]]);
        [$la, $lb] = $req->lines()->orderBy('line_number')->get()->all();
        $svc = new TransferService($this->c);
        $prep = $svc->prepare($this->auto, [
            ['product_id' => 'A', 'qty' => 2, 'unit' => 'Caixa 6 Unidades', 'request_line_id' => $la->id],
            ['product_id' => 'B', 'qty' => 1, 'unit' => 'Grade 24', 'request_line_id' => $lb->id],
        ]);
        $this->assertTrue($prep['blocked']);
        $this->assertSame('fator_diferente', $prep['lines'][0]['blocks'][0]['code']);   // o PingWin conta a caixa como 1; a XPLENDOR tem 6
        $this->assertStringContainsString('O PingWin conta cada «Caixa 6 Unidades» como 1 unidade', $prep['lines'][0]['blocks'][0]['text']);
        $this->assertSame('fator_por_confirmar', $prep['lines'][1]['blocks'][0]['code']); // a grade não tem conversão confirmada
        $this->assertNull($prep['lines'][1]['qty_base']);
        // Confirmado no modal: a linha da caixa desbloqueia.
        $ok = $svc->prepare($this->auto, [['product_id' => 'A', 'qty' => 2, 'unit' => 'Caixa 6 Unidades', 'request_line_id' => $la->id, 'factor_ack' => true]]);
        $this->assertFalse($ok['blocked']);
    }

    public function test_pcm_zero_or_far_from_last_purchase_needs_confirmation_and_low_stock_warns(): void
    {
        $svc = new TransferService($this->c);
        $this->stock('B', 'WBO', 3, 0.0);
        $p = $svc->prepare($this->auto, [['product_id' => 'B', 'qty' => 5]]);
        $this->assertSame(['pcm_zero', 'stock_insuficiente'], [$p['lines'][0]['confirm']['code'], $p['lines'][0]['warning']['code']]);
        $this->assertTrue($p['needs_confirmation']);
        $this->stock('B', 'WBO', 10, 1.5, ['pcu' => 1.0]);   // 50% acima do último preço de compra
        $p = $svc->prepare($this->auto, [['product_id' => 'B', 'qty' => 5]]);
        $this->assertSame('preco_afastado', $p['lines'][0]['confirm']['code']);
        $this->assertStringContainsString('afasta-se 50', $p['lines'][0]['confirm']['text']);
        $this->assertFalse($svc->prepare($this->auto, [['product_id' => 'B', 'qty' => 5, 'price_confirmed' => true]])['needs_confirmation']);
        $this->stock('B', 'WBO', 10, 1.2, ['pcu' => 1.0]);   // 20%: dentro do limite
        $this->assertNull($svc->prepare($this->auto, [['product_id' => 'B', 'qty' => 5]])['lines'][0]['confirm']);
    }

    // ── Enviar: P2/P3, estados, guardas ───────────────────────────────────

    public function test_send_from_order_uses_p2_and_auto_route_records_guide_and_entry(): void
    {
        Bus::fake();
        $req = $this->order();
        $line = $req->lines()->first();
        $t = (new TransferService($this->c))->send($this->auto, $req, [['product_id' => 'A', 'qty' => 2, 'unit' => 'Caixa 6 Unidades', 'request_line_id' => $line->id, 'factor_ack' => true]], $this->admin->id);
        Bus::assertDispatched(WritePingwinTransferJob::class);
        $this->respond = fn (array $p) => match ($p['mode']) {
            'doc_write' => $p['docconfig_id'] === '1102'
                ? self::saved('GA6', 'GA BOGA/6', 'WBO', [['product_id' => 'A', 'qnt' => 12, 'price' => 0.5]])
                : self::saved('EA3', 'EA BOEA/3', 'WC', [['product_id' => 'A', 'qnt' => 12, 'price' => 0.5]]),
            'stock' => ['ok' => true, 'errors' => [], 'seconds' => [], 'stock' => ['A' => self::stockRead('A', [['warehouse_id' => 'WBO', 'qnt' => 48.0]])]],
            default => ['ok' => false],
        };
        (new WritePingwinTransferJob(PingwinStockWrite::firstOrFail()->id))->handle(app(PingwinService::class), app(StockSyncService::class));

        $writes = collect($this->calls)->where('mode', 'doc_write')->values();
        $this->assertCount(2, $writes);
        $ga = $writes[0];
        $this->assertSame(['1102', 'ENC35', 'WBO', 'BO', 'WBO', '8002'], [$ga['docconfig_id'], $ga['source_id'], $ga['entity_id'], $ga['store_id'], $ga['expected_warehouse'], $ga['docstatus']]);
        $this->assertSame([['product_id' => 'A', 'code' => '101343', 'unit_id' => '11001', 'qnt' => 12.0, 'price' => 0.5]], $ga['lines']);   // na unidade de stock, ao PCM
        $this->assertSame(['expedition' => self::ADDR, 'delivery' => self::ADDR], $ga['addresses']);
        $this->assertSame([], $ga['allowed_codes']);
        $ea = $writes[1];
        $this->assertSame(['1104', 'GA6', 'WC', 'WC'], [$ea['docconfig_id'], $ea['source_id'], $ea['entity_id'], $ea['expected_warehouse']]);
        $t->refresh();
        $this->assertSame(['recebido', 'GA BOGA/6', 'EA BOEA/3', 'conversao'], [$t->status, $t->exit_doc_number, $t->entry_doc_number, $t->method]);
        $this->assertSame(['ok', 'ok'], PingwinStockWrite::orderBy('id')->pluck('status')->all());
        $this->assertSame(['guia_saida', 'entrada_armazem'], PingwinStockWrite::orderBy('id')->pluck('kind')->all());
        $this->assertTrue($req->fresh()->converted);
    }

    public function test_send_without_order_is_p3_and_stays_blocked_until_proven(): void
    {
        Bus::fake();
        $svc = new TransferService($this->c);
        try {
            $svc->send($this->auto, null, [['product_id' => 'B', 'qty' => 2]], $this->admin->id);
            $this->fail('devia recusar');
        } catch (TransferBlocked $e) {
            $this->assertStringContainsString('STOCK_TRANSFER_NEW_GUIDE_PROVEN', $e->getMessage());
        }
        config(['stock_transfers.new_guide_proven' => true]);
        $t = $svc->send($this->auto, null, [['product_id' => 'B', 'qty' => 2]], $this->admin->id);
        $this->respond = fn (array $p) => $p['mode'] === 'doc_write' && $p['docconfig_id'] === '1102'
            ? ['ok' => false, 'saved' => false, 'error' => 'O PingWin recusou o documento: Morada do local de carga em falta']
            : ['ok' => false];
        (new WritePingwinTransferJob(PingwinStockWrite::latest('id')->first()->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $ga = collect($this->calls)->firstWhere('mode', 'doc_write');
        $this->assertArrayNotHasKey('source_id', $ga);   // P3: Guia nova
        $this->assertSame(['falhou_envio', 'nova'], [$t->fresh()->status, $t->fresh()->method]);
        $this->assertSame('O PingWin recusou o documento: Morada do local de carga em falta Nada foi gravado no PingWin.', $t->fresh()->error_message);
        $this->assertCount(1, collect($this->calls)->where('mode', 'doc_write'));   // nunca reenvia sozinho
    }

    public function test_entry_failure_then_retry_only_the_entry(): void
    {
        Bus::fake();
        $t = $this->sentTransfer($this->auto);
        $this->respond = fn (array $p) => match (true) {
            $p['mode'] === 'doc_write' && $p['docconfig_id'] === '1102' => self::saved('GA6', 'GA BOGA/6', 'WBO', [['product_id' => 'B', 'qnt' => 2, 'price' => 1.0]]),
            $p['mode'] === 'doc_write' => ['ok' => false, 'saved' => false, 'error' => 'HTTP 500'],
            default => ['ok' => false],
        };
        (new WritePingwinTransferJob(PingwinStockWrite::firstOrFail()->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $t->refresh();
        $this->assertSame('falhou_rececao', $t->status);
        $this->assertStringStartsWith('A Guia GA BOGA/6 foi gravada, mas a Entrada falhou', $t->error_message);

        $w = (new TransferService($this->c))->receive($t->load('lines'), $this->admin->id);
        $this->respond = fn (array $p) => $p['mode'] === 'doc_write' ? self::saved('EA3', 'EA BOEA/3', 'WC', [['product_id' => 'B', 'qnt' => 2, 'price' => 1.0]]) : ['ok' => false];
        $this->calls = [];
        (new WritePingwinTransferJob($w->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame(['1104'], collect($this->calls)->where('mode', 'doc_write')->pluck('docconfig_id')->all());   // só a entrada
        $this->assertSame('recebido', $t->fresh()->status);
    }

    public function test_manual_route_waits_for_the_restaurant_and_records_the_difference(): void
    {
        Bus::fake();
        $t = $this->sentTransfer($this->manual);
        $this->respond = fn (array $p) => $p['mode'] === 'doc_write' ? self::saved('GA7', 'GA BOGA/7', 'WBO', [['product_id' => 'B', 'qnt' => 2, 'price' => 1.0]]) : ['ok' => false];
        (new WritePingwinTransferJob(PingwinStockWrite::firstOrFail()->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame('enviado', $t->fresh()->status);
        $this->assertCount(1, collect($this->calls)->where('mode', 'doc_write'));   // sem entrada automática

        $restaurant = $this->userWith(['restauracao.ver', 'restauracao.criar']);
        $lineId = $t->lines()->first()->id;
        $this->actingAs($restaurant, 'sanctum')->postJson($this->url("/{$t->id}/receive"), ['received' => [(string) $lineId => '1.5']])->assertStatus(202);
        $this->respond = fn (array $p) => $p['mode'] === 'doc_write' ? self::saved('EA4', 'EA BOEA/4', 'WB', [['product_id' => 'B', 'qnt' => 1.5, 'price' => 1.0]]) : ['ok' => false];
        $this->calls = [];
        (new WritePingwinTransferJob(PingwinStockWrite::latest('id')->first()->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $entry = collect($this->calls)->firstWhere('mode', 'doc_write');
        $this->assertSame([1.5, 'WB'], [$entry['lines'][0]['qnt'], $entry['entity_id']]);
        $this->assertSame('recebido_com_diferenca', $t->fresh()->status);
        // Receber mais do que se enviou é recusado.
        $t2 = $this->sentTransfer($this->manual, 'B', 2, 'enviado');
        $this->actingAs($restaurant, 'sanctum')->postJson($this->url("/{$t2->id}/receive"), ['received' => [(string) $t2->lines()->first()->id => '3']])->assertStatus(422);
    }

    public function test_void_annuls_entry_then_guide(): void
    {
        Bus::fake();
        $t = $this->sentTransfer($this->auto, 'B', 2, 'recebido');
        $t->update(['exit_doc_id' => 'GA6', 'exit_doc_number' => 'GA BOGA/6', 'entry_doc_id' => 'EA3', 'entry_doc_number' => 'EA BOEA/3']);
        $w = (new TransferService($this->c))->void($t->load('lines'), $this->admin->id);
        $this->respond = fn (array $p) => $p['mode'] === 'stockdoc_status' ? ['ok' => true, 'docstatus_id' => '8003'] : ['ok' => false];
        (new WritePingwinTransferJob($w->id))->handle(app(PingwinService::class), app(StockSyncService::class));
        $this->assertSame([['EA3', '8003'], ['GA6', '8003']], collect($this->calls)->where('mode', 'stockdoc_status')->map(fn ($c) => [$c['doc_id'], $c['status']])->values()->all());
        $this->assertSame('anulado', $t->fresh()->status);
    }

    public function test_guards_and_acl(): void
    {
        Bus::fake();
        $req = $this->order([['B', 'Unidade', 2, 1]]);
        $line = $req->lines()->first();
        $body = ['route_id' => $this->auto->id, 'request_id' => $req->id, 'lines' => [['product_id' => 'B', 'qty' => 2, 'request_line_id' => $line->id]]];
        // Ver e preparar: restauracao.ver. Enviar: restauracao.editar.
        $viewer = $this->userWith(['restauracao.ver']);
        $this->actingAs($viewer, 'sanctum')->getJson($this->url())->assertOk()->assertJsonPath('data.can.send', false)->assertJsonPath('data.pending.0.document', 'ENCF BOENCF/35');
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/prepare'), $body)->assertOk()->assertJsonPath('data.prepare.send_block', null);
        $this->actingAs($viewer, 'sanctum')->postJson($this->url(), $body)->assertForbidden();
        // Não provado: 403 com o porquê; nada na fila.
        config(['stock_transfers.send_proven' => false]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), $body)->assertForbidden()->assertJsonFragment(['message' => 'O envio de transferências para o PingWin ainda não foi provado neste ambiente (STOCK_TRANSFER_SEND_PROVEN). A preparação funciona; o envio fica bloqueado.']);
        config(['stock_transfers.send_proven' => true, 'services.pingwin.stockdoc_write_allowlist' => '101343']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), $body)->assertForbidden();
        $this->assertSame(0, PingwinStockWrite::count());
        // Sem morada de carga: o PingWin recusa a Guia; a XPLENDOR nem tenta.
        config(['services.pingwin.stockdoc_write_allowlist' => '']);
        $this->auto->update(['load_address' => null]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), $body)->assertForbidden()->assertJsonFragment(['message' => 'Falta a morada de carga do circuito (o PingWin recusa a Guia sem ela). Preencha-a no separador Circuito.']);
        // Configurar: restauracao.configurar.
        $this->actingAs($viewer, 'sanctum')->putJson($this->url("/routes/{$this->auto->id}"), ['load_address' => self::ADDR])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("/routes/{$this->auto->id}"), ['load_address' => self::ADDR, 'auto_receive' => false])->assertOk()
            ->assertJsonPath('data.route.addresses_ok', true)->assertJsonPath('data.route.auto_receive', false);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), $body)->assertStatus(202);
        $this->assertSame(1, PingwinStockWrite::where('kind', 'guia_saida')->count());
        // Um envio por encomenda; outra empresa não vê.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), $body)->assertStatus(422);
        $otherCompany = \App\Models\Company::create(['nipc' => '500100144', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $other = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'admin']);
        $this->actingAs($other, 'sanctum')->getJson($this->url())->assertForbidden();
    }

    // ── Leitura das encomendas, Guias e Entradas ───────────────────────────

    public function test_orders_sync_reads_orders_guides_and_entries_and_marks_converted(): void
    {
        $this->respond = function (array $p) {
            if ($p['mode'] !== 'doc_list') {
                return ['ok' => false];
            }
            if (! empty($p['hide_converted'])) {
                return ['ok' => true, 'documents' => [['id' => 'ENC36']]];
            }
            $doc = fn ($id, $dc, $doc, $store, $lines) => ['id' => $id, 'docconfig_id' => $dc, 'document' => $doc, 'store_id' => $store, 'store' => 'Loja', 'entity_id' => 'E',
                'entity_name' => ' Armazem Produção', 'doc_date' => '20261015T00:00:00', 'docstatus_id' => '8002', 'employee' => 'Gestão', 'total' => 0,
                'read' => ['header' => ['id' => $id], 'details' => $lines]];

            return ['ok' => true, 'documents' => [
                $doc('ENC35', '62590524561076436', 'ENCF BOENCF/35', 'S2', [['id' => 'L1', 'product_id' => 'A', 'product_code' => '101343', 'description' => 'A', 'qnt' => 2,
                    'unit_id' => 'CX', 'unit_desc' => 'Caixa 6 Unidades', 'unit_value' => 1, 'qnt_base' => 2, 'qnt_delivered' => 2, 'price' => 0, 'warehouse_id' => 'WC']]),
                $doc('ENC36', '62590524561076436', 'ENCF BOENCF/36', 'S1', []),
                $doc('REQ1', '1207', 'REQI 1', 'S1', []),
                $doc('GA4', '1102', 'GA BOGA/4', 'S2', [['id' => 'G1', 'product_id' => 'A', 'qnt' => 1, 'unit_desc' => 'Grade 24', 'unit_value' => 24, 'qnt_base' => 24, 'price' => 23.56,
                    'warehouse_id' => 'WC', 'raw' => ['src_docmovdetail_id' => '']]]),
                $doc('EA1', '1104', 'EA BOEA/1', 'BO', []),
            ]];
        };
        $r = app(TransferOrdersService::class)->sync($this->c);
        $this->assertSame(['requests' => 5, 'lines' => 2, 'errors' => 0], $r);
        $kinds = StockTransferRequest::orderBy('id')->get()->map(fn ($q) => [$q->document, $q->kind, $q->converted])->all();
        $this->assertSame([['ENCF BOENCF/35', 'encomenda', true], ['ENCF BOENCF/36', 'encomenda', false], ['REQI 1', 'requisicao', false],
            ['GA BOGA/4', 'guia', false], ['EA BOEA/1', 'entrada', false]], $kinds);
        $l = StockTransferRequest::where('document', 'ENCF BOENCF/35')->first()->lines()->first();
        $this->assertSame([2.0, 'Caixa 6 Unidades', 1.0, 2.0, 2.0], [$l->qnt, $l->unit_desc, $l->unit_value, $l->qnt_base, $l->qnt_delivered]);
        $this->assertSame($this->auto->id, StockTransferRequest::where('document', 'ENCF BOENCF/35')->first()->route_id);
        $list = collect($this->calls)->where('mode', 'doc_list')->first();
        $this->assertSame(['584955579139630867', '62590524561076436', '1207', '585727986578670852', '585727986578670856', '1102', '1007', '1104'],
            array_map('strval', $list['docconfig_ids']));
        $this->assertTrue($list['with_lines']);
    }

    private function sentTransfer(StockTransferRoute $route, string $pid = 'B', float $qty = 2, ?string $status = null): StockTransfer
    {
        config(['stock_transfers.new_guide_proven' => true]);
        $t = (new TransferService($this->c))->send($route, null, [['product_id' => $pid, 'qty' => $qty]], $this->admin->id);
        if ($status) {
            PingwinStockWrite::query()->delete();
            $t->update(['status' => $status, 'exit_doc_id' => 'GA' . $t->id, 'exit_doc_number' => 'GA BOGA/' . $t->id]);
        }

        return $t;
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
    }
}
