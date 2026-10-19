<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PermissionProfile;
use App\Models\PingwinUnit;
use App\Models\ProductUnitConversion;
use App\Models\RestaurantForecast;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Restaurant\PurchaseSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Compras sugeridas do Economato (só leitura): a mediana semanal das 8 semanas sem a compra única nem
 * a semana fora do normal, o ritmo do fornecedor (terças) e a próxima entrega, o ajuste pela previsão
 * (só com os dias lidos e com limites) e o feriado, o stock contado descontado, a embalagem confirmada,
 * o erro medido e os € só com as Finanças.
 */
class PurchaseSuggestionTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();   // hoje: quinta, 15/10/2026; o Economato é o WBO
        PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => 'SACO5', 'description' => 'Saco 5 kg', 'parent_pingwin_id' => '11002', 'unit_value' => 5, 'is_active' => true]);
        $this->catalog($this->c, 'BATATA', ['description' => 'Batata', 'forpurchase' => true, 'forsale' => false, 'base_unit_id' => '11002']);
        $this->catalog($this->c, 'VINHO', ['description' => 'Vinho', 'forpurchase' => true, 'forsale' => false, 'base_unit_id' => '11003']);
        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'BATATA', 'unit_key' => 'SACO5', 'unit_label' => 'Saco 5 kg', 'supplier_nif' => '',
            'factor_to_base' => 5, 'source' => 'confirmado']);
        // Batata à Makro todas as terças: 2 sacos (10 kg, 10 €); a 08/09, 20 sacos (fora do normal).
        foreach (['2026-08-18', '2026-08-25', '2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06'] as $d) {
            $this->buy('BATATA', $d, $d === '2026-09-08' ? 20 : 2, 'SACO5', 'Saco 5 kg', 5, $d === '2026-09-08' ? 10000 : 1000);
        }
        $this->buy('VINHO', '2026-09-15', 12, '11003', 'Litro', 1, 6000);   // compra única
    }

    private function buy(string $pid, string $date, float $qnt, string $unit, string $unitDesc, float $unitValue, int $cents): void
    {
        $id = 'H' . (++$this->seq);
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->c, 'docheader_id' => $id, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor',
            'document' => "VFT BOVFT/{$this->seq}", 'doc_date' => $date, 'docstatus_id' => '8002', 'entity_name' => 'Makro', 'entity_pingwin_id' => 'MK', 'tax_number' => '502000001',
            'total_cents' => $cents, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->c, 'docheader_id' => $id, 'line_number' => 1, 'product_pingwin_id' => $pid, 'description' => $pid,
            'qnt' => $qnt, 'unit_desc' => $unitDesc, 'price' => $cents / 100 / $qnt, 'total_cents' => $cents,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qnt * $unitValue, 'unit_id' => $unit, 'unit_value' => $unitValue]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_weekly_median_without_one_offs_or_outliers_and_the_supplier_rhythm(): void
    {
        $svc = new PurchaseSuggestionService($this->c);
        $m = $svc->weeklyMedians('2026-10-12');
        $this->assertSame(['502000001|BATATA'], array_keys($m));   // o vinho (compra única) fica de fora
        $this->assertSame(10.0, $m['502000001|BATATA']['median']);   // a semana de 100 kg fica de fora
        $r = $svc->rhythm()['502000001'];
        $this->assertSame([2], $r['usual_days']);                   // terças
        $this->assertSame(7.0, $r['interval_days']);
        $this->assertSame('2026-10-20', $r['next']);                 // a terça seguinte
    }

    public function test_suggestion_uses_the_counted_stock_and_rounds_to_the_confirmed_pack(): void
    {
        $count = StockCount::create(['company_id' => $this->c, 'warehouse_id' => 'WBO', 'scope' => 'todos', 'moment' => 'fecho', 'inventory_date' => '2026-10-13',
            'blind' => false, 'status' => 'enviada', 'confirmed_at' => '2026-10-13 23:00:00']);
        DB::table('stock_count_lines')->insert(['count_id' => $count->id, 'product_id' => 'BATATA', 'code' => 'PBATATA', 'description' => 'Batata', 'qty_base' => 4,
            'created_at' => now(), 'updated_at' => now()]);
        $s = (new PurchaseSuggestionService($this->c))->suggest();
        $this->assertSame(['date' => '2026-10-13', 'articles' => 1], $s['stock']);
        $l = $s['suppliers'][0]['lines'][0];
        // 10 kg × 1 (sem previsão) × 7/7 − 4 kg contados = 6 kg → 2 sacos de 5 kg.
        $this->assertSame([2, 'Saco 5 kg', true, 4.0, 500, 1000], [$l['suggested'], $l['unit'], $l['pack_confirmed'], $l['stock_base'], $l['price_cents'], $l['total_cents']]);
        $this->assertSame('2026-10-20', $s['suppliers'][0]['next_delivery']);
        $this->assertSame('Sem previsão para a semana seguinte: sem ajuste.', $s['forecast']['note']);

        $count->update(['confirmed_at' => null]);   // sem contagem confirmada: avisa e não desconta
        $s = (new PurchaseSuggestionService($this->c))->suggest();
        $this->assertNull($s['stock']);
        $this->assertSame(2, $s['suppliers'][0]['lines'][0]['suggested']);   // 10 kg → 2 sacos
    }

    public function test_forecast_factor_uses_only_the_days_read_is_capped_and_warns_of_holidays(): void
    {
        // 4 semanas fechadas (14/09 a 11/10) com 1 000 €/dia, mas só 20 dias lidos: a média por semana é 7 000 €.
        foreach (CarbonImmutable::parse('2026-09-14')->daysUntil('2026-10-11') as $i => $d) {
            if ($i % 4 !== 3) {
                DB::table('pingwin_daily_sales')->insert(['company_id' => $this->c, 'location_id' => $this->locBaixa, 'business_date' => $d->toDateString(), 'invoiced_cents' => 100000, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('pingwin_locations')->where('id', $this->locCosta)->update(['is_active' => false]);
        $fc = fn (float $perDay) => array_map(fn ($d) => RestaurantForecast::updateOrCreate(['location_id' => $this->locBaixa, 'business_date' => $d->toDateString(), 'metric' => 'invoiced'],
            ['company_id' => $this->c, 'model' => 'media', 'value' => $perDay]), iterator_to_array(CarbonImmutable::parse('2026-10-19')->daysUntil('2026-10-25')));
        $fc(1100);
        $f = (new PurchaseSuggestionService($this->c))->forecastFactor();
        $this->assertSame([1.1, 7000.0], [$f['factor'], $f['average']]);
        $fc(5000);   // uma previsão estranha: o ajuste fica em 130 %
        $f = (new PurchaseSuggestionService($this->c))->forecastFactor();
        $this->assertSame(1.3, $f['factor']);
        $this->assertStringContainsString('O ajuste fica limitado a 130 %.', $f['note']);
        $this->assertSame([], $f['holidays']);
        $dec = (new PurchaseSuggestionService($this->c, CarbonImmutable::parse('2026-11-26')))->forecastFactor();
        $this->assertSame(['2026-12-01' => 'Restauração da Independência'], $dec['holidays']);   // a semana de 30/11 tem um feriado
    }

    public function test_measured_error_and_weeks_without_purchases(): void
    {
        $e = (new PurchaseSuggestionService($this->c))->measuredError();
        $this->assertCount(4, $e['weeks']);
        $this->assertNotNull($e['pct']);
        $this->assertSame(0.0, $e['weeks'][3]['error_pct']);   // semana de 05/10: sugeridos 10 kg, comprados 10 kg
        DB::table('pingwin_supplier_documents')->where('doc_date', '2026-10-06')->delete();
        DB::table('pingwin_supplier_document_lines')->whereNotIn('docheader_id', DB::table('pingwin_supplier_documents')->pluck('docheader_id'))->delete();
        $e = (new PurchaseSuggestionService($this->c))->measuredError();
        $this->assertNull($e['weeks'][3]['error_pct']);
        $this->assertSame('Sem compras lidas nesta semana.', $e['weeks'][3]['note']);
    }

    public function test_endpoint_hides_the_euros_without_finance(): void
    {
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $url = "/api/v1/companies/{$this->c}/restaurant/purchase-suggestions";
        $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.suppliers.0.lines.0.price_cents', 500);
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'Cozinha', 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions(['restauracao.ver']);
        $cook = User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
        $r = $this->actingAs($cook, 'sanctum')->getJson($url)->assertOk();
        $this->assertNull($r->json('data.suppliers.0.lines.0.price_cents'));
        $this->assertSame(2, $r->json('data.suppliers.0.lines.0.suggested'));
        $this->assertFalse($r->json('data.financial.visible'));
    }
}
