<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\ProductBomYield;
use App\Models\RestaurantStoreWarehouse;
use App\Services\Restaurant\StockConsumptionService;
use App\Services\Restaurant\StockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7a — o consumo teórico: vendas × ficha em vigor nesse dia e nessa loja, os preparados da
 * cozinha expandidos pelo rendimento confirmado (sem ele: ÷ 1 e "estimativa"), a venda direta de um
 * artigo que mexe stock, a loja → armazém (do PingWin quando só um armazém serve a loja; senão, o da
 * XPLENDOR) e os dias de cobertura.
 */
class StockConsumptionTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
    }

    private function service(): StockConsumptionService
    {
        return new StockConsumptionService($this->c);
    }

    public function test_the_version_in_force_on_each_day_and_in_each_store(): void
    {
        $this->catalog($this->c, 'COCA0', ['description' => 'Coca Cola 0']);
        // Até 30/09 a ficha usa a garrafa (100366); desde 01/10, a 0.35 (100415). A versão nova só na Baixa.
        $this->persistBom($this->c, $this->bom('COCA0', [
            ['00000000', 'V1', '2026-05-06', ['S1' => true, 'S2' => true], [$this->bomLine('100366', 1)]],
            ['00000001', 'V2', '2026-10-01', ['S1' => true, 'S2' => false], [$this->bomLine('100415', 1)]],
        ]));
        $this->soldDaily('COCA0', $this->locBaixa, '2026-09-25', 10, 10);   // 25/09 a 04/10
        $this->soldDaily('COCA0', $this->locCosta, '2026-09-25', 10, 5);

        $daily = collect($this->service()->daily('2026-09-25', '2026-10-04'));
        $sum = fn ($cid, $store) => (float) $daily->where('component_id', $cid)->where('store_id', $store)->sum('qty');
        $this->assertSame(60.0, $sum('100366', 'S1'));     // 6 dias × 10 com a V1
        $this->assertSame(40.0, $sum('100415', 'S1'));     // 4 dias × 10 com a V2
        $this->assertSame(50.0, $sum('100366', 'S2'));     // a V2 não está ativa na Costa: continua a V1
        $this->assertSame(0.0, $sum('100415', 'S2'));
        $this->assertSame(['ficha'], $daily->pluck('via')->unique()->values()->all());
    }

    public function test_kitchen_preparations_expand_by_the_confirmed_yield(): void
    {
        $this->catalog($this->c, 'FRAN', ['description' => 'Francesinha']);
        $this->catalog($this->c, 'MOLHO', ['description' => 'MOLHO FRANCESINHA 30LT', 'forsale' => false]);
        // A francesinha leva 0,1 L de molho; o lote de molho leva 15 L de vinho e 6 kg de cebola.
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BF', '2026-05-26', ['S1' => true], [
            $this->bomLine('MOLHO', 0.1, ['isbom' => 1]), $this->bomLine('PAO', 1, ['_i' => 1]),
        ]]]));
        $this->persistBom($this->c, $this->bom('MOLHO', [['00000000', 'BM', '2026-05-26', ['BO' => true], [
            $this->bomLine('AVINHEZ', 15), $this->bomLine('CEBOLA', 6, ['_i' => 1, 'waste' => 10]),
        ]]]));
        $this->soldDaily('FRAN', $this->locBaixa, '2026-10-01', 1, 30);

        // Sem rendimento confirmado: ÷ 1 (cada litro de molho conta como um lote inteiro) e estimativa.
        $rows = collect($this->service()->daily('2026-10-01', '2026-10-01'));
        $this->assertEqualsWithDelta(45.0, $rows->firstWhere('component_id', 'AVINHEZ')['qty'], 1e-9);     // 30 × 0,1 × 15
        $this->assertTrue($rows->firstWhere('component_id', 'AVINHEZ')['low_confidence']);
        $this->assertSame(30.0, $rows->firstWhere('component_id', 'PAO')['qty']);
        $this->assertNull($rows->firstWhere('component_id', 'MOLHO'));                                      // expandido

        // Rendimento confirmado de 30 L: ÷ 30 (e o desperdício da cebola conta).
        ProductBomYield::create(['company_id' => $this->c, 'product_pingwin_id' => 'MOLHO', 'bom_pingwin_id' => 'BM', 'quantity' => 30, 'unit_label' => 'Litro', 'source' => 'confirmado']);
        $rows = collect($this->service()->daily('2026-10-01', '2026-10-01'));
        $this->assertEqualsWithDelta(1.5, $rows->firstWhere('component_id', 'AVINHEZ')['qty'], 1e-9);      // 30 × 0,1 × 15 ÷ 30
        $this->assertEqualsWithDelta(0.66, $rows->firstWhere('component_id', 'CEBOLA')['qty'], 1e-9);      // 30 × 0,1 × 6 × 1,1 ÷ 30
        $this->assertFalse($rows->firstWhere('component_id', 'AVINHEZ')['low_confidence']);
    }

    public function test_an_article_sold_without_a_recipe_consumes_itself_only_if_it_moves_stock(): void
    {
        $this->catalog($this->c, 'AGUA', ['description' => 'Água 0,5']);
        $this->catalog($this->c, 'CAFE', ['description' => 'Serviço de café']);
        $this->movestock('AGUA', true);
        $this->movestock('CAFE', false);
        $this->soldDaily('AGUA', $this->locCosta, '2026-10-01', 2, 12);
        $this->soldDaily('CAFE', $this->locCosta, '2026-10-01', 2, 40);
        $rows = collect($this->service()->daily('2026-10-01', '2026-10-02'));
        $this->assertSame(24.0, $rows->where('component_id', 'AGUA')->sum('qty'));
        $this->assertSame(['venda'], $rows->where('component_id', 'AGUA')->pluck('via')->unique()->values()->all());
        $this->assertSame(0, $rows->where('component_id', 'CAFE')->count());
    }

    public function test_store_to_warehouse_from_the_pingwin_and_from_the_xplendor(): void
    {
        $map = $this->service()->storeWarehouses();
        $this->assertSame(['WB', 'pingwin'], [$map['S1']['warehouse_id'], $map['S1']['source']]);
        $this->assertSame(['WC', 'pingwin'], [$map['S2']['warehouse_id'], $map['S2']['source']]);

        // Um segundo armazém a servir a Baixa: deixa de ser claro.
        $this->warehouse('WT', '4', 'XPLENDOR TESTE', ['S1']);
        $map = $this->service()->storeWarehouses();
        $this->assertSame([null, null], [$map['S1']['warehouse_id'], $map['S1']['source']]);
        $this->assertEqualsCanonicalizing(['WB', 'WT'], $map['S1']['candidates']);

        // Definido na XPLENDOR: manda.
        RestaurantStoreWarehouse::create(['company_id' => $this->c, 'store_pingwin_id' => 'S1', 'warehouse_pingwin_id' => 'WBO']);
        $map = $this->service()->storeWarehouses();
        $this->assertSame(['WBO', 'xplendor'], [$map['S1']['warehouse_id'], $map['S1']['source']]);
    }

    public function test_28_day_average_by_warehouse_and_coverage_days(): void
    {
        $this->catalog($this->c, 'COCA0', ['description' => 'Coca Cola 0']);
        $this->catalog($this->c, '100366', ['description' => 'COCA-COLA ZERO GARRAFA', 'forsale' => false, 'forpurchase' => true]);
        $this->persistBom($this->c, $this->bom('COCA0', [['00000000', 'V1', '2026-05-06', ['S1' => true, 'S2' => true], [$this->bomLine('100366', 1)]]]));
        // As vendas lidas acabam a 08/10 (a janela acaba aí, não hoje).
        $this->soldDaily('COCA0', $this->locBaixa, '2026-09-11', 28, 17);
        $this->soldDaily('COCA0', $this->locCosta, '2026-09-11', 28, 43);
        $avg = $this->service()->averages();
        $this->assertSame(['from' => '2026-09-11', 'to' => '2026-10-08', 'days' => 28], $avg['window']);
        $c = $avg['components']['100366'];
        $this->assertEqualsWithDelta(60.0, $c['per_day'], 1e-9);
        $this->assertEqualsWithDelta(17.0, $c['by_warehouse']['WB'], 1e-9);
        $this->assertEqualsWithDelta(43.0, $c['by_warehouse']['WC'], 1e-9);
        $this->assertSame(['COCA0'], array_keys($c['sources']));

        // Dias de cobertura no ecrã: stock ÷ consumo por dia (por armazém e no total).
        $this->stock('100366', 'WB', 170);
        $this->stock('100366', 'WC', 4300);
        $detail = (new StockReportService($this->c))->article('100366');
        $wb = collect($detail['warehouses'])->firstWhere('warehouse_id', 'WB');
        $this->assertSame([17.0, 10.0], [$wb['per_day'], $wb['coverage_days']]);
        $this->assertSame(['cobertura_60'], collect($detail['warehouses'])->firstWhere('warehouse_id', 'WC')['states']);   // 100 dias
        $this->assertSame([60.0, 74.5], [$detail['total']['per_day'], $detail['total']['coverage_days']]);
    }
}
