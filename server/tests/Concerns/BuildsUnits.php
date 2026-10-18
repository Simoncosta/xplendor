<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Models\PingwinUnit;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\DB;

/**
 * Auditoria v2 · o espelho mínimo para as unidades: uma empresa de restauração com o PingWin, uma
 * loja, as unidades base e duas de embalagem, e os auxiliares de compras (com a descrição do
 * fornecedor), fichas de pratos vendidos e artigos de comparação da mesma família.
 */
trait BuildsUnits
{
    use BuildsRecipes;

    protected const DRINKS = 'Família \\ COMPRAS \\ BEBIDAS';

    protected const FOOD = 'Família \\ COMPRAS \\ COMIDAS';

    protected Company $company;

    protected int $c;

    protected int $loc;

    protected int $seq = 0;

    protected function setUpUnits(): void
    {
        $this->travelTo('2026-10-15 16:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009931', 'fiscal_name' => 'Restaurante Teste', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->c = $this->company->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'teste']]);
        $this->loc = PingwinLocation::create(['company_id' => $this->c, 'winrest_store_id' => 'S1', 'display_name' => 'Centro', 'is_active' => true, 'opened_on' => '2026-01-10'])->id;
        foreach ([['11001', 'Unidade', null, 1], ['11002', 'Quilograma', null, 1], ['11003', 'Litro', null, 1], ['GRD24', 'Grade 24', '11001', 24],
            ['CX6', 'Caixa 6 Unidades', '11001', 1]] as [$id, $d, $p, $v]) {
            PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'unit_value' => $v, 'is_active' => true]);
        }
    }

    protected function art(string $pid, string $desc, string $family = self::FOOD, string $base = '11001'): void
    {
        $this->catalog($this->c, $pid, ['description' => $desc, 'family' => $family, 'base_unit_id' => $base, 'stockconfig_id' => '7501',
            'forpurchase' => true, 'forsale' => false]);
    }

    /** Uma compra fechada (fatura de fornecedor), com a descrição do fornecedor e a unidade faturada. */
    protected function buy(string $pid, string $date, float $qnt, int $totalCents, string $desc, string $unitId = '11001', string $unitDesc = 'Unidade',
        float $uv = 1.0, string $nif = '500000001', string $supplier = 'Fornecedor A'): void
    {
        $doc = 'U' . (++$this->seq);
        DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $this->c, 'docheader_id' => $doc, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor', 'doc_date' => $date,
            'docstatus_id' => '8002', 'document' => "VFT T/{$this->seq}", 'tax_number' => $nif, 'entity_name' => $supplier, 'entity_pingwin_id' => "E{$nif}",
            'total_cents' => $totalCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pingwin_supplier_document_lines')->insert([
            'company_id' => $this->c, 'docheader_id' => $doc, 'line_number' => 1, 'product_pingwin_id' => $pid, 'description' => $desc,
            'qnt' => $qnt, 'unit_desc' => $unitDesc, 'price' => $qnt > 0 ? $totalCents / 100 / $qnt : 0, 'total_cents' => $totalCents,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qnt * $uv, 'unit_id' => $unitId, 'unit_value' => $uv]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Uma ficha em vigor de um prato vendido: [componente, quantidade, unidade, descrição da unidade]. */
    protected function dish(string $pid, array $lines, float $sold = 90): void
    {
        $this->catalog($this->c, $pid, ['description' => "Prato {$pid}", 'family' => 'Família \\ VENDAS', 'forsale' => true, 'forpurchase' => false, 'has_bom' => true]);
        $bomLines = [];
        foreach ($lines as $i => [$comp, $q, $unit, $unitDesc]) {
            $bomLines[] = $this->bomLine($comp, $q, ['_i' => $i, 'unit_id' => $unit, 'unit_desc' => $unitDesc]);
        }
        $this->persistBom($this->c, $this->bom($pid, [['V1', "B{$pid}", '2026-01-01', ['S1' => true], $bomLines]]));
        $this->sale($this->c, $this->loc, $pid, '2026-10-01', $sold, (int) ($sold * 300));
    }

    protected function peers(string $family, string $unitId, string $unitDesc, float $price, int $n = 3, string $base = '11001'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->art("PEER{$family}{$i}", "Artigo de comparação {$i}", $family, $base);
            $this->buy("PEER{$family}{$i}", '2026-09-01', 10, (int) round($price * 1000), "Artigo de comparação {$i}", $unitId, $unitDesc);
        }
    }

}
