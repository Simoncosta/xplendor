<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarSaleAttribution;
use App\Models\Company;
use App\Services\CampaignToSaleAttributionService;
use App\Support\SaleAttributionUniqueness;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — Qualidade de dados nas atribuições de vendas.
 *
 * Cobre: uma atribuição por venda (chave na viatura, com a ligação à venda
 * registada, não na hora); venda sem campanha com tipo próprio ('none'), separado
 * do recurso ao mapeamento ('fallback'); reclassificação das existentes; limpeza
 * dos duplicados com simulação primeiro e só depois --write; e a chave única.
 */
class SaleAttributionQualityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 10:00:00'));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
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

    // ── helpers ───────────────────────────────────────────────────────────────

    private function soldCar(string $carSoldAt, ?string $registeredSaleAt = null): Car
    {
        $car = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId, 'status' => 'sold']);
        DB::table('cars')->where('id', $car->id)->update(['sold_at' => $carSoldAt]);
        if ($registeredSaleAt !== null) {
            DB::table('car_sales')->insert(['car_id' => $car->id, 'company_id' => $this->company->id, 'sale_price' => 15000,
                'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'walk_in', 'sold_at' => $registeredSaleAt,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $car->fresh();
    }

    /** Volta ao esquema antigo (chave na hora da venda), para recriar duplicados como os de produção. */
    private function legacyKey(): void
    {
        Schema::table('car_sale_attributions', function (Blueprint $t) {
            $t->dropUnique(SaleAttributionUniqueness::NEW_INDEX);
        });
    }

    private function attribution(Car $car, string $soldAt, string $match = 'none', int $confidence = 0, ?string $campaign = null): int
    {
        return DB::table('car_sale_attributions')->insertGetId([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'sold_at' => $soldAt,
            'attributed_platform' => $campaign ? 'meta' : null, 'attributed_campaign_id' => $campaign,
            'match_type' => $match, 'confidence_score' => $confidence, 'attribution_model' => 'last_touch_recent_window',
            'attribution_window_days' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 1. Uma atribuição por venda ──────────────────────────────────────────

    public function test_marking_the_sale_twice_keeps_one_attribution_linked_to_the_registered_sale(): void
    {
        $car = $this->soldCar('2026-09-11 13:22:14', '2026-09-11 13:22:14');
        $svc = app(CampaignToSaleAttributionService::class);

        $svc->recordSaleAttribution($car, ['sold_at' => '2026-09-11 12:53:13']);
        $svc->recordSaleAttribution($car, ['sold_at' => '2026-09-11 13:22:14']);   // nova hora, mesma venda

        $a = CarSaleAttribution::sole();
        $this->assertSame(DB::table('car_sales')->where('car_id', $car->id)->value('id'), $a->car_sale_id);
        $this->assertSame('2026-09-11 13:22:14', $a->sold_at->format('Y-m-d H:i:s'));
    }

    public function test_the_database_refuses_a_second_attribution_for_the_same_car(): void
    {
        $car = $this->soldCar('2026-09-11 13:22:14');
        $this->attribution($car, '2026-09-11 13:22:14');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->attribution($car, '2026-09-11 12:00:00');
    }

    // ── 2. Venda sem campanha: tipo próprio ──────────────────────────────────

    public function test_sale_without_campaign_is_none_not_fallback(): void
    {
        $car = $this->soldCar('2026-10-01 10:00:00');

        $r = app(CampaignToSaleAttributionService::class)->attributeSale($car, ['sold_at' => now()]);

        $this->assertSame('none', $r['match_type']);
        $this->assertSame(0, $r['confidence_score']);
        $this->assertNull($r['campaign_id']);
    }

    public function test_existing_unattributed_rows_are_relabelled_and_mapping_fallbacks_stay(): void
    {
        $noCampaign = $this->soldCar('2026-09-01 10:00:00');
        $mapped = $this->soldCar('2026-09-02 10:00:00');
        $a = $this->attribution($noCampaign, '2026-09-01 10:00:00', 'fallback', 0);
        $b = $this->attribution($mapped, '2026-09-02 10:00:00', 'fallback', 35, 'C9');   // recurso ao mapeamento: fica

        $this->assertSame(1, SaleAttributionUniqueness::relabelUnattributed());
        $this->assertSame('none', DB::table('car_sale_attributions')->where('id', $a)->value('match_type'));
        $this->assertSame('fallback', DB::table('car_sale_attributions')->where('id', $b)->value('match_type'));
    }

    // ── 3. Limpeza dos duplicados: simulação primeiro ────────────────────────

    public function test_dedupe_simulates_first_writes_only_with_flag_and_then_creates_the_unique_key(): void
    {
        Storage::fake('local');
        $this->legacyKey();

        // Como a viatura 95 em dev: venda registada às 13:22:14; duas atribuições.
        $car95 = $this->soldCar('2026-06-09 14:15:52', '2026-09-11 13:22:14');
        $junho = $this->attribution($car95, '2026-06-09 14:15:52');
        $setembro = $this->attribution($car95, '2026-09-11 12:53:13');
        // Sem venda registada: fica a mais próxima da data de venda da viatura.
        $car61 = $this->soldCar('2026-06-03 20:27:14');
        $perto = $this->attribution($car61, '2026-06-03 20:27:14');
        $longe = $this->attribution($car61, '2026-06-03 20:27:23');
        $unica = $this->soldCar('2026-05-01 10:00:00');
        $this->attribution($unica, '2026-05-01 10:00:00');

        $this->assertSame('duplicates', SaleAttributionUniqueness::ensure());

        $this->artisan('sales:dedupe-attributions')
            ->expectsOutputToContain('SIMULAÇÃO (nada foi gravado): 2 viaturas com atribuições duplicadas · 2 atribuições sairiam.')
            ->assertSuccessful();
        $this->assertSame(5, DB::table('car_sale_attributions')->count());
        $csv = Storage::disk('local')->get(Storage::disk('local')->files('reports')[0]);
        $this->assertStringContainsString((string) $setembro, $csv);

        $this->artisan('sales:dedupe-attributions', ['--write' => true])
            ->expectsOutputToContain('GRAVADO: 2 viaturas com atribuições duplicadas · 2 atribuições apagadas. Chave única por viatura criada.')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('car_sale_attributions')->count());
        $kept95 = DB::table('car_sale_attributions')->where('car_id', $car95->id)->sole();
        $this->assertSame($setembro, $kept95->id);   // a mais próxima da venda registada
        $this->assertSame('2026-09-11 13:22:14', substr((string) $kept95->sold_at, 0, 19));   // fica com a data da venda registada
        $this->assertSame(DB::table('car_sales')->where('car_id', $car95->id)->value('id'), $kept95->car_sale_id);
        $this->assertFalse(DB::table('car_sale_attributions')->where('id', $junho)->exists());
        $this->assertSame($perto, DB::table('car_sale_attributions')->where('car_id', $car61->id)->value('id'));
        $this->assertFalse(DB::table('car_sale_attributions')->where('id', $longe)->exists());
        $this->assertTrue(SaleAttributionUniqueness::hasNewIndex());

        // Segunda corrida: nada a fazer.
        $this->artisan('sales:dedupe-attributions', ['--write' => true])
            ->expectsOutputToContain('GRAVADO: 0 viaturas com atribuições duplicadas · 0 atribuições apagadas. A chave única por viatura já existia.')
            ->assertSuccessful();
    }
}
