<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Services\CarMarketIntelligenceService;
use App\Support\PricePosition;
use App\Support\StockAge;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Fase 2a: fontes únicas de DIAS EM STOCK (StockAge) e de PREÇO VS
 * MERCADO (PricePosition). Prova a equivalência PHP == SQL, os limiares por tipo,
 * as faixas/preço efetivo/confiança mínima e que os consumidores mantêm o formato.
 */
class StockAgeAndPricePositionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:30:00'));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500006001', 'fiscal_name' => 'Stand', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $this->brandId = $brand->id;
        $this->modelId = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function car(array $attrs = []): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => $this->company->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'price_gross' => 20000, 'promo_price_gross' => null,
        ], array_diff_key($attrs, ['created_at' => 1, 'car_created_at' => 1, 'sold_at' => 1])));

        // Datas forçadas diretamente (o factory/Eloquent poria created_at = agora).
        $dates = array_intersect_key($attrs, ['created_at' => 1, 'car_created_at' => 1, 'sold_at' => 1]);
        if ($dates !== []) {
            DB::table('cars')->where('id', $car->id)->update($dates);
        }

        return $car->fresh();
    }

    private function aggregate(Car $car, array $attrs = []): CarMarketAggregate
    {
        return CarMarketAggregate::create(array_merge([
            'car_id' => $car->id, 'vehicle_type' => $car->vehicle_type, 'status' => 'success', 'confidence' => 'high',
            'comparables_count' => 8, 'median_price' => 20000, 'car_price_gross' => 1, 'promo_price_gross' => null,
        ], $attrs));
    }

    // ── DIAS EM STOCK: PHP == SQL ────────────────────────────────────────────

    public function test_days_in_stock_php_equals_sql_across_cases(): void
    {
        $cases = [
            // entrada às 23:59 de ontem → 1 dia de calendário (não 0 por horas)
            'late_entry' => ['created_at' => '2026-10-04 23:59:00'],
            // entrada às 00:01 de hoje → 0
            'today' => ['created_at' => '2026-10-05 00:01:00'],
            // car_created_at manda sobre created_at
            'official_date' => ['created_at' => '2026-10-01 09:00:00', 'car_created_at' => '2026-08-01 18:00:00'],
            // vendida: conta até sold_at (não até hoje)
            'sold' => ['status' => 'sold', 'created_at' => '2026-06-01 15:00:00', 'sold_at' => '2026-07-11 08:00:00'],
            // status sold sem sold_at → até hoje
            'sold_no_date' => ['status' => 'sold', 'created_at' => '2026-09-25 12:00:00'],
            // sold_at preenchido mas viatura reativada (não sold) → até hoje
            'reactivated' => ['status' => 'active', 'created_at' => '2026-09-01 12:00:00', 'sold_at' => '2026-09-10 12:00:00'],
            // entrada no futuro → nunca negativo
            'future' => ['created_at' => '2026-10-09 12:00:00'],
            // mudança de ano
            'cross_year' => ['created_at' => '2025-12-31 22:00:00'],
        ];

        $cars = [];
        foreach ($cases as $name => $attrs) {
            $cars[$name] = $this->car($attrs);
        }

        foreach ([null, CarbonImmutable::parse('2026-09-15 16:00:00')] as $asOf) {
            $sql = DB::table('cars')->whereIn('id', collect($cars)->pluck('id'))
                ->selectRaw('id, ' . StockAge::sqlExpr('cars', $asOf) . ' as d')
                ->pluck('d', 'id');

            foreach ($cars as $name => $car) {
                $this->assertSame($car->daysInStock($asOf), (int) $sql[$car->id], "PHP≠SQL em '{$name}' (asOf=" . ($asOf?->toDateString() ?? 'hoje') . ')');
            }
        }

        // Valores esperados (âncoras explícitas).
        $this->assertSame(1, $cars['late_entry']->daysInStock());
        $this->assertSame(0, $cars['today']->daysInStock());
        $this->assertSame(65, $cars['official_date']->daysInStock());   // 01/08 → 05/10
        $this->assertSame(40, $cars['sold']->daysInStock());            // 01/06 → 11/07
        $this->assertSame(0, $cars['future']->daysInStock());
        $this->assertSame(278, $cars['cross_year']->daysInStock());
    }

    public function test_in_stock_universe_and_thresholds_by_type(): void
    {
        $this->assertSame(['active', 'available_soon', 'reserved'], Car::IN_STOCK_STATUSES);

        $this->assertTrue(StockThresholds::isStale('car', 45));
        $this->assertFalse(StockThresholds::isStale('car', 44));
        $this->assertFalse(StockThresholds::isStale('motorhome', 119));
        $this->assertTrue(StockThresholds::isStale('motorhome', 120));
        $this->assertFalse(StockThresholds::isStale('car', null));

        // Curva do IPS derivada do limiar: carros = valores históricos.
        $this->assertSame([15, 30, 60], StockThresholds::ageBandsFor('car'));
        $this->assertSame([40, 80, 160], StockThresholds::ageBandsFor('motorhome'));

        // Limiar em SQL == PHP.
        foreach (['car', 'motorcycle', 'motorhome', 'caravan'] as $type) {
            $c = $this->car(['vehicle_type' => $type]);
            $sql = (int) DB::table('cars')->where('id', $c->id)->selectRaw(StockThresholds::sqlThresholdExpr('cars') . ' as t')->value('t');
            $this->assertSame(StockThresholds::ageThresholdFor($type), $sql, $type);
        }
    }

    // ── PREÇO VS MERCADO ─────────────────────────────────────────────────────

    public function test_effective_price_single_rule(): void
    {
        $this->assertSame(18000.0, PricePosition::effectivePrice(20000, 18000));   // promo válida
        $this->assertSame(20000.0, PricePosition::effectivePrice(20000, 21000));   // promo acima do bruto → ignora
        $this->assertSame(20000.0, PricePosition::effectivePrice(20000, 0));       // promo 0 → ignora
        $this->assertSame(20000.0, PricePosition::effectivePrice(20000, null));
        $this->assertNull(PricePosition::effectivePrice(null, 15000));             // sem bruto → sem preço
    }

    public function test_four_bands_boundaries_and_three_scale(): void
    {
        $this->assertSame('overpriced', PricePosition::band(10.0));
        $this->assertSame('slightly_high', PricePosition::band(9.99));
        $this->assertSame('slightly_high', PricePosition::band(3.0));
        $this->assertSame('fair', PricePosition::band(2.99));
        $this->assertSame('fair', PricePosition::band(-5.0));
        $this->assertSame('competitive', PricePosition::band(-5.01));
        $this->assertNull(PricePosition::band(null));

        $this->assertSame('above_market', PricePosition::threeScale('overpriced'));
        $this->assertSame('above_market', PricePosition::threeScale('slightly_high'));
        $this->assertSame('aligned_market', PricePosition::threeScale('fair'));
        $this->assertSame('below_market', PricePosition::threeScale('competitive'));
    }

    public function test_uses_current_car_price_and_latest_aggregate_with_median(): void
    {
        $car = $this->car(['price_gross' => 22000, 'promo_price_gross' => 21000]); // efetivo 21 000
        $this->aggregate($car, ['median_price' => 20000, 'car_price_gross' => 99999]); // a cópia guardada NÃO conta
        $this->aggregate($car, ['status' => 'pending', 'median_price' => null]);        // mais recente, sem mediana → ignorado

        $pos = PricePosition::for($car);

        $this->assertSame(21000.0, $pos['effective_price']);
        $this->assertSame(5.0, $pos['difference_pct']);
        $this->assertSame('slightly_high', $pos['band']);
        $this->assertSame('above_market', $pos['position']);
    }

    public function test_above_market_requires_minimum_confidence(): void
    {
        $car = $this->car(['price_gross' => 25000]);
        $this->aggregate($car, ['median_price' => 20000, 'confidence' => 'low']); // +25% mas confiança baixa

        $pos = PricePosition::for($car);
        $this->assertSame('overpriced', $pos['band']);                 // a faixa é mostrada…
        $this->assertSame('insufficient_data', $pos['position']);      // …mas não se sinaliza "acima"
        $this->assertTrue($pos['signal_suppressed']);

        // Abaixo do mercado com confiança baixa continua a valer (não é um alerta).
        $cheap = $this->car(['price_gross' => 15000]);
        $this->aggregate($cheap, ['median_price' => 20000, 'confidence' => 'low']);
        $this->assertSame('below_market', PricePosition::for($cheap)['position']);
    }

    public function test_motorhomes_use_the_similarity_engine_aggregate(): void
    {
        $mh = $this->car(['vehicle_type' => 'motorhome', 'price_gross' => 45000]);
        $this->aggregate($mh, ['median_price' => 40000, 'p25_price' => 37000, 'p75_price' => 43000, 'method' => 'motorhome_similarity_v1', 'confidence' => 'medium']);

        $out = app(CarMarketIntelligenceService::class)->analyze($mh);

        $this->assertSame('above_market', $out['market_position']);   // +12,5% com confiança medium
        $this->assertSame(40000.0, $out['market_median_price']);
        $this->assertSame(37000.0, $out['market_p25_price']);
        $this->assertSame(39600.0, $out['recommended_price']);         // mediana × 0,99
    }

    public function test_market_intelligence_keeps_the_same_output_format(): void
    {
        $expected = ['competitors_count', 'market_median_price', 'market_p25_price', 'market_p75_price',
            'car_price_vs_median_pct', 'market_position', 'pricing_signal', 'recommended_price'];

        $car = $this->car(['price_gross' => 19000]);
        $this->aggregate($car, ['median_price' => 20000]);
        $withData = app(CarMarketIntelligenceService::class)->analyze($car);
        $this->assertSame($expected, array_keys($withData));
        $this->assertSame('aligned_market', $withData['market_position']); // -5% → fair → aligned
        $this->assertSame('neutral', $withData['pricing_signal']);

        $noAgg = app(CarMarketIntelligenceService::class)->analyze($this->car());
        $this->assertSame($expected, array_keys($noAgg));
        $this->assertSame('insufficient_data', $noAgg['market_position']);
    }

    public function test_aggregate_model_uses_the_same_rules(): void
    {
        $car = $this->car();
        // Cópia guardada com promo ACIMA do bruto: a regra única ignora-a.
        $agg = $this->aggregate($car, ['median_price' => 20000, 'car_price_gross' => 21000, 'promo_price_gross' => 25000]);

        $this->assertSame(21000.0, $agg->effectivePrice());
        $this->assertSame(5.0, $agg->priceDifference());
        $this->assertSame('slightly_high', $agg->priceSignal());
    }

    public function test_promotion_price_filter_uses_current_car_price(): void
    {
        // O aggregate guardou 30 000 (seria "overpriced"), mas a viatura está hoje a
        // 19 500 → "fair". O filtro tem de seguir o preço atual.
        $car = $this->car(['price_gross' => 19500]);
        $this->aggregate($car, ['median_price' => 20000, 'car_price_gross' => 30000]);

        $repo = app(\App\Repositories\StockPromotionRepository::class);
        $method = new \ReflectionMethod($repo, 'applyPriceSignalFilter');
        $method->setAccessible(true);

        $ids = function (array $signals) use ($repo, $method) {
            $q = Car::query()->where('cars.company_id', $this->company->id);
            $method->invoke($repo, $q, $signals);

            return $q->pluck('cars.id')->all();
        };

        $this->assertSame([$car->id], $ids(['fair']));
        $this->assertSame([], $ids(['overpriced']));
    }
}
