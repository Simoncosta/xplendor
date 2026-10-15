<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Transfers\EncomendaPorEnviarRule;
use App\Audit\Rules\Restaurant\Transfers\EntradaCustoRule;
use App\Audit\Rules\Restaurant\Transfers\EnvioSemRececaoRule;
use App\Audit\Rules\Restaurant\Transfers\FatorEmbalagemRule;
use App\Audit\Rules\Restaurant\Transfers\GuiaOrigemErradaRule;
use App\Audit\Rules\Restaurant\Transfers\PcmEntreArmazensRule;
use App\Audit\Rules\Restaurant\Transfers\RececaoSemEnvioRule;
use App\Audit\Rules\Restaurant\Transfers\RecebidoDiferenteRule;
use App\Models\PingwinStockItem;
use App\Models\ProductUnitConversion;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7c — as regras T1 a T8 com os documentos reais de 15/10: a GA BOGA/4 (3 grades a sair do Armazém
 * Yuko, sem Entrada), a GA BOGA/5 (4 grades de COCA COLA ZERO a sair do Armazém Yuko, ao preço de uma
 * unidade) e a EA BOEA/1 (5 grades a 0,53 €, o preço de UMA lata: o erro do PingWin na conversão).
 */
class TransferAuditTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();   // hoje: 15/10/2026
        StockTransferRoute::create(['company_id' => $this->c, 'name' => 'Economato → Original', 'origin_warehouse_id' => 'WBO', 'origin_store_id' => 'BO',
            'dest_warehouse_id' => 'WC', 'dest_store_id' => 'S2']);
        foreach ([['100415', 'COCA COLA ZERO 0.35'], ['100416', 'COCA COLA 0.35'], ['100417', 'FANTA LARANJA 0.35']] as [$id, $d]) {
            $this->catalog($this->c, $id, ['code' => $id, 'description' => $d, 'forsale' => false, 'forpurchase' => true]);
            $this->movestock($id);
            ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => $id, 'unit_key' => 'GRD24', 'unit_label' => 'Grade 24', 'supplier_nif' => '', 'factor_to_base' => 24, 'source' => 'confirmado']);
        }
        $this->stock('100415', 'WBO', 5112, 0.534564);
        $this->stock('100416', 'WBO', 500, 1.0);
        $this->stock('100417', 'WBO', 500, 1.0);
    }

    private function doc(string $id, string $kind, string $document, string $date, array $lines, array $over = []): StockTransferRequest
    {
        $dc = ['guia' => '1102', 'entrada' => '1104', 'encomenda' => '62590524561076436'][$kind];
        $r = StockTransferRequest::create(array_merge(['company_id' => $this->c, 'pingwin_doc_id' => $id, 'docconfig_id' => $dc, 'kind' => $kind, 'document' => $document,
            'store_id' => 'BO', 'store_name' => 'Yuko BO', 'doc_date' => $date, 'docstatus_id' => '8002'], $over));
        foreach ($lines as $i => $l) {
            $r->lines()->create(array_merge(['line_number' => $i + 1, 'code' => $l['product_id'], 'description' => "Artigo {$l['product_id']}", 'unit_desc' => 'Grade 24', 'unit_value' => 24], $l));
        }

        return $r;
    }

    /** Os três documentos de 15/10. */
    private function realDocs(): void
    {
        $this->doc('GA4', 'guia', 'GA BOGA/4', '2026-10-15', [
            ['product_id' => '100415', 'qnt' => 1, 'qnt_base' => 24, 'price' => 23.56, 'warehouse_id' => 'WC', 'qnt_delivered' => 0],
            ['product_id' => '100416', 'qnt' => 1, 'qnt_base' => 24, 'price' => 25.4, 'warehouse_id' => 'WC', 'qnt_delivered' => 0],
            ['product_id' => '100417', 'qnt' => 1, 'qnt_base' => 24, 'price' => 24.04, 'warehouse_id' => 'WC', 'qnt_delivered' => 0],
        ], ['store_name' => 'Tabern Yuko Original']);
        $this->doc('GA5', 'guia', 'GA BOGA/5', '2026-10-15', [['product_id' => '100415', 'qnt' => 4, 'qnt_base' => 96, 'price' => 0.529999, 'warehouse_id' => 'WC', 'qnt_delivered' => 4]]);
        $this->doc('EA1', 'entrada', 'EA BOEA/1', '2026-10-15', [['product_id' => '100415', 'qnt' => 5, 'qnt_base' => 120, 'price' => 0.529999, 'warehouse_id' => 'WC', 'qnt_delivered' => 5]]);
    }

    private function findings(string $rule): array
    {
        return iterator_to_array(app($rule)->run($this->c, new AuditScope($this->c, 'restauracao', 'transferencias')), false);
    }

    public function test_t1_guides_that_left_the_restaurant_warehouse(): void
    {
        $this->realDocs();
        $f = collect($this->findings(GuiaOrigemErradaRule::class))->keyBy('entityLabel');
        $this->assertSame(['GA BOGA/4', 'GA BOGA/5'], $f->keys()->sort()->values()->all());
        $this->assertSame('BOGA/5: tirou o stock do Armazém Yuko', $f['GA BOGA/5']->title);
        $this->assertSame((int) round(96 * 0.534564 * 100), $f['GA BOGA/5']->impactCents);
        $this->assertSame('media', $f['GA BOGA/5']->severity);
        $this->assertStringContainsString('As Guias de envio devem sair do Armazém Yuko BO', $f['GA BOGA/4']->seen);
    }

    public function test_t2_catches_ea_boea_1_with_the_price_of_one_can(): void
    {
        $this->realDocs();
        $f = $this->findings(EntradaCustoRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('EA BOEA/1', $f[0]->entityLabel);
        $cost = 5 * 0.529999 / 120;
        $this->assertSame((int) round(120 * (0.534564 - $cost) * 100), $f[0]->impactCents);
        $this->assertStringContainsString('entrou a 0,0221 € por unidade; o PCM no Economato é 0,53 €', $f[0]->seen);
        // Ao PCM × fator, não é achado.
        StockTransferRequest::where('pingwin_doc_id', 'EA1')->first()->lines()->update(['price' => 0.534564 * 24]);
        $this->assertSame([], $this->findings(EntradaCustoRule::class));
    }

    public function test_t3_t4_t5_pair_guides_and_entries(): void
    {
        $this->realDocs();
        // T5: a EA BOEA/1 recebeu 120 latas; a GA BOGA/5 enviou 96.
        $f = $this->findings(RecebidoDiferenteRule::class);
        $this->assertCount(1, $f);
        $this->assertStringContainsString('entraram 120 unidades de 100415 Artigo 100415; a GA BOGA/5 enviou 96 unidades', $f[0]->seen);
        $this->assertSame((int) round(24 * 0.534564 * 100), $f[0]->impactCents);
        // T4: nenhuma (a EA BOEA/1 emparelha com a GA BOGA/5).
        $this->assertSame([], $this->findings(RececaoSemEnvioRule::class));
        // T3: a GA BOGA/4 ainda é de hoje; amanhã já é achado.
        $this->assertSame([], $this->findings(EnvioSemRececaoRule::class));
        $this->travelTo('2026-10-17 10:00:00');
        $f3 = $this->findings(EnvioSemRececaoRule::class);
        $this->assertSame(['GA BOGA/4'], array_map(fn ($x) => $x->entityLabel, $f3));
        $this->assertSame((int) round(24 * (0.534564 + 1 + 1) * 100), $f3[0]->impactCents);
        // T4: uma Entrada sem Guia nos 3 dias antes.
        $this->doc('EA9', 'entrada', 'EA BOEA/9', '2026-10-17', [['product_id' => '100416', 'qnt' => 2, 'qnt_base' => 48, 'price' => 24, 'warehouse_id' => 'WC']]);
        $f4 = $this->findings(RececaoSemEnvioRule::class);
        $this->assertSame(['EA BOEA/9'], array_map(fn ($x) => $x->entityLabel, $f4));
        $this->assertSame(4800, $f4[0]->impactCents);
        // Os anulados não contam.
        StockTransferRequest::where('pingwin_doc_id', 'EA9')->update(['docstatus_id' => '8003']);
        $this->assertSame([], $this->findings(RececaoSemEnvioRule::class));
    }

    public function test_t6_pack_factor_different_from_confirmed(): void
    {
        $this->catalog($this->c, 'A', ['code' => '101343', 'description' => 'TESTE XPLENDOR STOCK A', 'forsale' => false, 'forpurchase' => true]);
        $this->movestock('A');
        $this->stock('A', 'WBO', 60, 0.5);
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'A', 'unit_key' => 'CX6', 'unit_label' => 'Caixa 6 Unidades', 'supplier_nif' => '', 'factor_to_base' => 6, 'source' => 'confirmado']);
        $this->doc('ENC35', 'encomenda', 'ENCF BOENCF/35', '2026-10-15', [['product_id' => 'A', 'qnt' => 2, 'unit_desc' => 'Caixa 6 Unidades', 'unit_value' => 1, 'qnt_base' => 2, 'warehouse_id' => 'WC']]);
        $f = $this->findings(FatorEmbalagemRule::class);
        $this->assertCount(1, $f);
        $this->assertSame(['artigo_unidade', 'A:caixa 6 unidades'], [$f[0]->entityType, $f[0]->entityId]);
        $this->assertStringContainsString('O PingWin conta cada «Caixa 6 Unidades» como 1 unidade; a XPLENDOR tem confirmado 6 unidades', $f[0]->why);
        $this->assertSame(500, $f[0]->impactCents);   // 2 × (6 − 1) × 0,50 €
        // As grades da GA BOGA/5 (unit_value 24 = confirmado) não são achado.
        $this->realDocs();
        $this->assertCount(1, $this->findings(FatorEmbalagemRule::class));
    }

    public function test_t7_pcm_far_apart_between_warehouses_like_avinhez(): void
    {
        $this->catalog($this->c, 'AVZ', ['code' => '100200', 'description' => 'AVINHEZ BRANCO', 'forsale' => false, 'forpurchase' => true]);
        $this->stock('AVZ', 'WBO', 300, 14.89, ['unit_name' => 'Litro']);
        $this->stock('AVZ', 'WB', 40, 0.74, ['unit_name' => 'Litro']);
        $this->stock('AVZ', 'WC', 10, 15.2, ['unit_name' => 'Litro']);
        $f = collect($this->findings(PcmEntreArmazensRule::class))->keyBy('entityId');
        $this->assertSame(['AVZ'], $f->keys()->all());
        $this->assertSame('AVINHEZ BRANCO: com o custo muito diferente entre armazéns', $f['AVZ']->title);
        $this->assertSame((int) round((40 * (14.89 - 0.74) + 10 * (15.2 - 14.89)) * 100), $f['AVZ']->impactCents);
        $this->assertSame('alta', $f['AVZ']->severity);
        $this->assertStringContainsString('No Armazém Yuko BO está a 14,89 €; no Armazém Yuko Baixa, a 0,74 €', $f['AVZ']->seen);
    }

    public function test_t8_order_not_sent_after_three_days_is_info(): void
    {
        $o = $this->doc('ENC1', 'encomenda', 'ENCFB BOENCFB/1', '2026-10-10', [['product_id' => '100415', 'qnt' => 4, 'qnt_base' => 96, 'warehouse_id' => 'WC']],
            ['store_name' => 'Tabern Yuko Original']);
        $this->doc('ENC2', 'encomenda', 'ENCFB BOENCFB/2', '2026-10-14', [['product_id' => '100415', 'qnt' => 1, 'qnt_base' => 24, 'warehouse_id' => 'WC']]);
        $this->doc('ENC3', 'encomenda', 'ENCFB BOENCFB/3', '2026-10-01', [], ['converted' => true]);
        $f = $this->findings(EncomendaPorEnviarRule::class);
        $this->assertSame(['ENCFB BOENCFB/1'], array_map(fn ($x) => $x->entityLabel, $f));
        $this->assertNull($f[0]->severity);   // a da regra: informação
        $this->assertSame('info', app(EncomendaPorEnviarRule::class)->severity());
        $this->assertStringContainsString('está por enviar há 5 dias', $f[0]->seen);
        // Com um envio na XPLENDOR, deixa de ser achado.
        StockTransfer::create(['company_id' => $this->c, 'request_id' => $o->id, 'origin_warehouse_id' => 'WBO', 'origin_store_id' => 'BO', 'dest_warehouse_id' => 'WC',
            'dest_store_id' => 'S2', 'status' => 'enviado']);
        $this->assertSame([], $this->findings(EncomendaPorEnviarRule::class));
    }
}
