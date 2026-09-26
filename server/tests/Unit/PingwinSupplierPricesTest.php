<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplierPrice;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin › tab Compras (C1): espelho de tbsupprice. Testa o mapeamento das
 * linhas (cêntimos, ligação ao supplier unificado, is_active), a idempotência do upsert e
 * a reconciliação (linhas que deixam de vir → is_active=false). Só leitura/espelho.
 */
class PingwinSupplierPricesTest extends TestCase
{
    use RefreshDatabase;

    private PingwinService $service;
    private Company $company;
    private int $catalogItemId;
    private string $productId = '584955579139630628';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PingwinService::class);

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create([
            'nipc' => '500000123', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId,
        ]);

        $item = PingwinCatalogItem::create([
            'company_id' => $this->company->id, 'pingwin_id' => $this->productId,
        ]);
        $this->catalogItemId = $item->id;

        // Supplier unificado (source=pingwin) que a linha vai referenciar.
        DB::table('suppliers')->insert([
            'company_id' => $this->company->id, 'source' => 'pingwin', 'pingwin_id' => 'SUP-MAKRO',
            'name' => 'Makro', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(array $over = []): array
    {
        return array_merge([
            'id' => '9001', 'product_id' => $this->productId,
            'supplier_id' => 'SUP-MAKRO', 'supplier_name' => 'Makro',
            'supprice_header_id' => 'H1', 'table_name' => 'Tabela Geral',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 'EUR',
            'unit_id' => 'KG', 'unit_name' => 'Quilograma',
            'sup_product_description' => 'MC MACA GOLDEN I 70/75', 'sup_product_code' => 'MK-123',
            'sup_product_barcode' => '5600000000017',
            'price' => '1.24', 'currprecision' => 2, 'discount1' => '0', 'discount2_mul' => '1',
            'deleted' => 0,
        ], $over);
    }

    private function sync(array $lines): array
    {
        return $this->service->syncSupplierPricesFromRead($this->company->id, $this->catalogItemId, $this->productId, $lines);
    }

    public function test_espelha_linha_com_centimos_e_liga_ao_supplier(): void
    {
        $shaped = $this->sync([$this->line()]);

        $this->assertCount(1, $shaped);
        $s = $shaped[0];
        $this->assertSame(124, $s['price_cents']);              // "1.24" → 124
        $this->assertSame('Quilograma', $s['unit']['name']);
        $this->assertSame(2, $s['currprecision']);
        $this->assertSame('Makro', $s['supplier']['name']);

        // Ligou ao supplier unificado local.
        $localSupplierId = DB::table('suppliers')->where('company_id', $this->company->id)
            ->where('pingwin_id', 'SUP-MAKRO')->value('id');
        $this->assertSame((int) $localSupplierId, $s['supplier']['id']);

        $this->assertDatabaseHas('pingwin_supplier_prices', [
            'company_id' => $this->company->id, 'line_pingwin_id' => '9001',
            'catalog_item_id' => $this->catalogItemId, 'price_cents' => 124, 'is_active' => true,
        ]);
    }

    public function test_upsert_idempotente(): void
    {
        $this->sync([$this->line()]);
        $this->sync([$this->line(['price' => '1.30'])]); // mesma linha (id 9001), preço muda

        $this->assertSame(1, PingwinSupplierPrice::where('company_id', $this->company->id)->count());
        $this->assertSame(130, PingwinSupplierPrice::where('line_pingwin_id', '9001')->value('price_cents'));
    }

    public function test_reconciliacao_desativa_linha_que_deixa_de_vir(): void
    {
        $this->sync([$this->line()]);            // linha 9001 ativa
        $shaped = $this->sync([]);               // leitura seguinte sem linhas

        $this->assertCount(0, $shaped);          // shape só devolve ativas
        $this->assertFalse((bool) PingwinSupplierPrice::where('line_pingwin_id', '9001')->value('is_active'));
    }

    public function test_linha_deleted_fica_inativa(): void
    {
        $shaped = $this->sync([$this->line(['deleted' => 1])]);
        $this->assertCount(0, $shaped);
        $this->assertDatabaseHas('pingwin_supplier_prices', ['line_pingwin_id' => '9001', 'is_active' => false]);
    }

    public function test_preco_com_mais_casas_arredonda_para_centimos(): void
    {
        // Documenta a assunção de 2 casas: "1.2456" → 125 cêntimos (round). O currprecision
        // vem guardado para se detetar divergências (o raw preserva o original).
        $shaped = $this->sync([$this->line(['price' => '1.2456', 'currprecision' => 4])]);
        $this->assertSame(125, $shaped[0]['price_cents']);
        $this->assertSame(4, $shaped[0]['currprecision']);
        $this->assertSame('1.2456', PingwinSupplierPrice::where('line_pingwin_id', '9001')->value('raw')['price']);
    }
}
