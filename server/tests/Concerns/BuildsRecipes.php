<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\PingwinCatalogItem;
use App\Services\PingwinBomSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 1 — monta fichas técnicas (na forma do read_bom do Python, gravadas pelo persist) e compras
 * (linhas de faturas de fornecedor fechadas) para os testes das fichas técnicas.
 */
trait BuildsRecipes
{
    private int $docSeq = 0;

    /** Uma linha da ficha (forma do parse_bom_lines). */
    protected function bomLine(string $componentId, float $qntBase, array $extra = []): array
    {
        return array_merge([
            'key' => sprintf('%08d', $extra['_i'] ?? 0), 'productbom_id' => '', 'component_id' => $componentId,
            'component_code' => "C{$componentId}", 'component_description' => "Componente {$componentId}",
            'unit_id' => '11001', 'unit_desc' => 'Unidade', 'qnt' => $qntBase, 'qnt_base' => $qntBase,
            'waste' => 0.0, 'recovery' => 0.0, 'unit_cost' => 0.0, 'cost' => 0.0, 'isbom' => 0, 'status' => 1,
        ], array_diff_key($extra, ['_i' => 1]));
    }

    /**
     * Uma ficha (forma do read_bom). $versions: [[key, id, valid_from, stores [store_id => active], lines, deleted?]].
     * As linhas vão iguais para "" e para o armazém W1 (custo × 2 no W1).
     */
    protected function bom(string $productId, array $versions, array $extra = []): array
    {
        return array_merge([
            'id' => $productId, 'code' => "P{$productId}", 'description' => "Artigo {$productId}",
            'units' => ['base' => '11001', 'sale' => '11001', 'purchase' => '11001', 'stock' => '11001'],
            'forsale' => 1, 'forpurchase' => 0, 'forproduction' => 0, 'stockconfig_id' => '7501', 'cost_warehouse_id' => null,
            'unit_list' => [], 'prices' => ['saleprice' => 2.9, 'saleprice_wo_tax' => 2.357724],
            'warehouses' => [['id' => 'W1', 'description' => 'Armazém 1']],
            'versions' => array_map(function ($v) {
                $lines = array_values(array_map(fn ($l, $i) => $l + ['key' => sprintf('%08d', $i)], $v[4], array_keys($v[4])));
                foreach ($lines as $i => $l) {
                    $lines[$i]['key'] = sprintf('%08d', $i);
                    $lines[$i]['productbom_id'] = $v[1];
                }

                return [
                    'key' => $v[0], 'id' => $v[1], 'valid_from' => $v[2], 'deleted' => (int) ($v[5] ?? 0), 'bom_type' => '',
                    'stores' => array_map(fn ($sid, $active) => ['store_id' => (string) $sid, 'store_code' => "S{$sid}", 'store_name' => "Loja {$sid}", 'active' => $active],
                        array_keys($v[3]), array_values($v[3])),
                    'lines' => [
                        '' => $lines,
                        'W1' => array_map(fn ($l) => array_merge($l, ['unit_cost' => $l['unit_cost'] * 2, 'cost' => $l['cost'] * 2]), $lines),
                    ],
                ];
            }, $versions),
        ], $extra);
    }

    protected function persistBom(int $companyId, array $bom): void
    {
        app(PingwinBomSyncService::class)->persist($companyId, $bom);
    }

    protected function catalog(int $companyId, string $pingwinId, array $extra = []): PingwinCatalogItem
    {
        return PingwinCatalogItem::create(array_merge([
            'company_id' => $companyId, 'pingwin_id' => $pingwinId, 'code' => "P{$pingwinId}", 'description' => "Artigo {$pingwinId}",
            'family' => 'Família \\ Bebidas \\ Sumos', 'forsale' => true, 'forpurchase' => false, 'has_bom' => false, 'is_active' => true,
        ], $extra));
    }

    /** Uma compra (linha de fatura de fornecedor fechada): total sem IVA já com descontos, qnt_base no raw. */
    protected function purchase(int $companyId, string $productId, string $date, float $qnt, float $qntBase, int $totalCents, array $extra = []): void
    {
        $doc = 'D' . (++$this->docSeq);
        DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $companyId, 'docheader_id' => $doc, 'docconfig_id' => $extra['docconfig_id'] ?? '1209',
            'doctype' => 'Fatura de fornecedor', 'doc_date' => $date, 'docstatus_id' => $extra['docstatus_id'] ?? '8002',
            'total_cents' => $totalCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pingwin_supplier_document_lines')->insert([
            'company_id' => $companyId, 'docheader_id' => $doc, 'line_number' => 1, 'product_pingwin_id' => $productId,
            'qnt' => $qnt, 'unit_desc' => $extra['unit_desc'] ?? 'Unidade', 'price' => $qnt > 0 ? $totalCents / 100 / $qnt : 0,
            'total_cents' => $totalCents,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qntBase, 'unit_id' => $extra['unit_id'] ?? '11001', 'unit_value' => $qnt > 0 ? $qntBase / $qnt : 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function sale(int $companyId, int $locationId, string $productId, string $date, float $qty, int $netCents, string $family = 'Família \\ Bebidas \\ Sumos'): void
    {
        DB::table('pingwin_item_sales_daily')->insert([
            'company_id' => $companyId, 'location_id' => $locationId, 'business_date' => $date, 'product_pingwin_id' => $productId,
            'product_code' => "P{$productId}", 'product_name' => "Artigo {$productId}", 'family_path' => $family,
            'quantity' => $qty, 'net_cents' => $netCents, 'tax_cents' => 0, 'gross_cents' => $netCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
