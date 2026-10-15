<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\PingwinUnit;
use App\Models\PingwinWarehouse;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 7a — a Yuko de bolso para os testes do stock: duas lojas de vendas (Baixa = S1, Costa Cabral
 * = S2), o Economato (WBO, só serve o BO) e um armazém por loja (WB serve BO+S1; WC serve BO+S2), as
 * unidades, e auxiliares para stock, compras por armazém e o resultado do "Python" do stock.
 */
trait BuildsStock
{
    use BuildsRecipes;

    protected Company $company;
    protected int $c;
    protected int $locBaixa;
    protected int $locCosta;
    private int $stockDocSeq = 0;

    protected function setUpYuko(): void
    {
        $this->travelTo('2026-10-15 16:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->c = $this->company->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->locBaixa = PingwinLocation::create(['company_id' => $this->c, 'winrest_store_id' => 'S1', 'display_name' => 'Yuko Baixa', 'is_active' => true, 'opened_on' => '2026-01-10'])->id;
        $this->locCosta = PingwinLocation::create(['company_id' => $this->c, 'winrest_store_id' => 'S2', 'display_name' => 'Yuko Costa Cabral', 'is_active' => true, 'opened_on' => '2026-01-10'])->id;
        foreach ([['11001', 'Unidade', null, 1], ['11002', 'Quilograma', null, 1], ['11003', 'Litro', null, 1]] as [$id, $d, $p, $v]) {
            PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'unit_value' => $v, 'is_active' => true]);
        }
        $this->warehouse('WBO', '1', 'Armazém Yuko BO', ['BO']);
        $this->warehouse('WB', 'BAIXA', 'Armazém Yuko Baixa', ['BO', 'S1']);
        $this->warehouse('WC', '2', 'Armazém Yuko', ['BO', 'S2']);
    }

    protected function warehouse(string $id, string $code, string $name, array $served, bool $deleted = false): PingwinWarehouse
    {
        $all = ['BO' => 'Yuko BO', 'S1' => 'Tabern Yuko Baixa', 'S2' => 'Tabern Yuko Original'];

        return PingwinWarehouse::updateOrCreate(['company_id' => $this->c, 'pingwin_id' => $id], [
            'code' => $code, 'description' => $name, 'deleted' => $deleted, 'inv_costmethod' => 0, 'exit_costmethod' => 0,
            'stores' => array_map(fn ($sid, $n) => ['store_id' => $sid, 'code' => $sid, 'name' => $n, 'served' => in_array($sid, $served, true)], array_keys($all), array_values($all)),
        ]);
    }

    protected function stock(string $pid, string $wid, float $qnt, ?float $pcm = 0.5, array $extra = []): PingwinStock
    {
        return PingwinStock::updateOrCreate(['company_id' => $this->c, 'product_pingwin_id' => $pid, 'warehouse_pingwin_id' => $wid], array_merge([
            'unit_name' => 'Unidade', 'unit_value' => 1, 'qnt' => $qnt, 'pcm' => $pcm, 'pcu' => $pcm, 'valorization_date' => '2026-10-12',
            'min_stock' => null, 'max_stock' => null, 'disable_generic' => false, 'synced_at' => now(),
        ], $extra));
    }

    protected function movestock(string $pid, bool $moves = true): void
    {
        PingwinStockItem::updateOrCreate(['company_id' => $this->c, 'product_pingwin_id' => $pid],
            ['stockconfig_id' => $moves ? '7501' : '7500', 'stockconfig' => $moves ? 'Stock normal' : 'Sem stock', 'movestock' => $moves, 'stock_unit' => 'Unidade']);
    }

    /** Uma compra (fatura de fornecedor fechada) que entrou num armazém. */
    protected function boughtIn(string $pid, string $wid, string $date, float $qnt, int $totalCents): void
    {
        $doc = 'SD' . (++$this->stockDocSeq);
        DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $this->c, 'docheader_id' => $doc, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor', 'document' => "VFT BOVFT/{$this->stockDocSeq}",
            'doc_date' => $date, 'docstatus_id' => '8002', 'entity_name' => 'Fornecedor', 'entity_pingwin_id' => 'F1', 'tax_number' => '500000000',
            'total_cents' => $totalCents, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pingwin_supplier_document_lines')->insert([
            'company_id' => $this->c, 'docheader_id' => $doc, 'line_number' => 1, 'product_pingwin_id' => $pid, 'description' => "Linha {$pid}",
            'qnt' => $qnt, 'unit_desc' => 'Unidade', 'price' => $qnt > 0 ? $totalCents / 100 / $qnt : 0, 'total_cents' => $totalCents, 'warehouse_pingwin_id' => $wid,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qnt, 'unit_id' => '11001', 'unit_value' => 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Vendas de vários dias seguidos numa loja (a mesma quantidade por dia). */
    protected function soldDaily(string $pid, int $locationId, string $from, int $days, float $qtyPerDay, int $netPerDay = 300): void
    {
        for ($d = 0; $d < $days; $d++) {
            $this->sale($this->c, $locationId, $pid, date('Y-m-d', strtotime("{$from} +{$d} days")), $qtyPerDay, $netPerDay);
        }
    }

    /** O resultado do modo "stock" do Python para um artigo (a forma do read_stock). */
    protected static function stockRead(string $pid, array $rows, array $article = []): array
    {
        return [
            'article' => array_merge(['id' => $pid, 'code' => "P{$pid}", 'description' => "Artigo {$pid}", 'stockconfig_id' => '7501', 'stockconfig' => 'Stock normal',
                'movestock' => 1, 'base_unit_id' => '11001', 'stock_unit_id' => '11001', 'stock_unit' => 'Unidade', 'status' => 1, 'deleted' => 0], $article),
            'rows' => array_map(fn ($r) => array_merge([
                'warehouse_code' => '1', 'warehouse_name' => 'Armazém', 'unit_name' => 'Unidade', 'unit_value' => 1.0, 'qnt' => 0.0, 'pcm' => 0.5, 'pcu' => 0.5,
                'valorization_date' => '2026-10-12', 'min_stock' => null, 'max_stock' => null, 'disable_generic' => 0, 'qnt_pend_cli' => 0.0, 'qnt_pend_sup' => 0.0, 'stk_prov' => 0.0,
            ], $r), $rows),
            'warehouses' => [],
        ];
    }
}
