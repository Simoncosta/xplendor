<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarMarketAggregate;
use App\Models\CarMarketSnapshot;
use App\Models\CarModel;
use App\Models\Company;
use App\Services\MarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FASE 1 — integração do motor de similaridade de autocaravanas:
 * elegibilidade na BD (layout/ano/preço/recência), guard de entrada,
 * persistência (method/p25/p75/outliers/funnel) e o contrato "n=1-2 mostra
 * na mesma". A matemática pura vive em tests/Unit/MotorhomeMarketEngineTest.
 */
class MotorhomeSimilarityAggregateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Car $motorhome;
    private MarketSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc' => '500000002', 'fiscal_name' => 'Motorhome Co',
            'plan_id' => $planId, 'subscription_status' => 'active',
        ]);

        $brand = CarBrand::create(['name' => 'Benimar', 'slug' => 'benimar', 'vehicle_type' => 'motorhome']);
        $model = CarModel::create(['name' => 'Tessoro', 'car_brand_id' => $brand->id]);
        $category = CarCategory::create(['name' => 'Perfilada', 'slug' => 'perfilada', 'vehicle_type' => 'motorhome']);

        $this->motorhome = Car::factory()->create([
            'company_id'         => $this->company->id,
            'car_brand_id'       => $brand->id,
            'car_model_id'       => $model->id,
            'car_category_id'    => $category->id,
            'vehicle_type'       => 'motorhome',
            'registration_year'  => 2020,
            'price_gross'        => 42000,
            'engine_capacity_cc' => 2287,
        ]);

        $this->service = app(MarketSnapshotService::class);
    }

    private function seedSnapshot(array $overrides = []): CarMarketSnapshot
    {
        static $i = 0;
        $i++;

        return CarMarketSnapshot::create(array_merge([
            'external_id'  => 'mh-' . $i,
            'source'       => 'standvirtual',
            'vehicle_type' => 'motorhome',
            'brand'        => 'Benimar',
            'model'        => 'Tessoro',
            'year'         => 2020,
            'title'        => 'Benimar Tessoro ' . $i,
            'url'          => 'https://example.test/' . $i,
            'layout'       => 'perfiladas',
            'price'        => 40000,
            'displacement' => 2287,
            'beds'         => 4,
            'dedup_hash'   => 'hash-' . $i,
            'scraped_at'   => now(),
        ], $overrides));
    }

    private function makeAggregate(): CarMarketAggregate
    {
        return CarMarketAggregate::create([
            'car_id' => $this->motorhome->id, 'vehicle_type' => 'motorhome',
            'status' => 'pending', 'confidence' => 'none', 'comparables_count' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_pipeline_persiste_metodo_banda_e_top_por_score(): void
    {
        $this->seedSnapshot(['price' => 36000, 'year' => 2020]);
        $this->seedSnapshot(['price' => 38000, 'year' => 2019]);
        $this->seedSnapshot(['price' => 40000, 'year' => 2020, 'displacement' => null, 'beds' => null]);
        $this->seedSnapshot(['price' => 41000, 'year' => 2021]);
        $this->seedSnapshot(['price' => 43000, 'year' => 2018]);

        $aggregate = $this->makeAggregate();
        $this->service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);
        $aggregate->refresh();

        $this->assertSame('success', $aggregate->status);
        $this->assertSame('motorhome_similarity_v1', $aggregate->method);
        $this->assertSame(5, $aggregate->comparables_count);
        $this->assertSame(0, $aggregate->outliers_removed);
        $this->assertSame(40000.0, (float) $aggregate->median_price);
        $this->assertNotNull($aggregate->p25_price);
        $this->assertNotNull($aggregate->p75_price);
        $this->assertFalse((bool) $aggregate->fallback_used);

        // Top por SCORE: o snapshot 2020/2287cc chega primeiro com os campos novos.
        $top = $aggregate->top_comparables;
        $this->assertSame(2020, $top[0]['year']);
        $this->assertArrayHasKey('similarity_score', $top[0]);
        $this->assertArrayHasKey('length', $top[0]);
        $this->assertArrayHasKey('scraped_at', $top[0]);

        // Funil gravado.
        $this->assertSame('perfiladas', $aggregate->funnel['layout']);
        $this->assertSame(5, $aggregate->funnel['eligible']);
    }

    public function test_elegibilidade_exclui_layout_ano_preco_e_recencia(): void
    {
        $this->seedSnapshot(['price' => 40000]);                                       // elegível
        $this->seedSnapshot(['layout' => 'integral']);                                 // layout ≠
        $this->seedSnapshot(['layout' => null]);                                       // sem layout
        $this->seedSnapshot(['year' => 2017]);                                         // fora da janela ±2
        $this->seedSnapshot(['price' => 1]);                                           // placeholder 1€
        $this->seedSnapshot(['price' => 950000]);                                      // acima do teto
        $this->seedSnapshot(['scraped_at' => now()->subDays(90)]);                     // velho (>60d)

        $aggregate = $this->makeAggregate();
        $this->service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);
        $aggregate->refresh();

        $this->assertSame(1, $aggregate->comparables_count);
        $this->assertSame('low', $aggregate->confidence);
        // Funil conta as perdas por etapa: 5 da tipologia certa (inclui o
        // sem-layout? não — layout NULL não conta), janela, recência, preço.
        $this->assertSame(5, $aggregate->funnel['layout_total']);     // perfiladas: 4 + o de 2017
        $this->assertSame(4, $aggregate->funnel['in_year_window']);
        $this->assertSame(3, $aggregate->funnel['fresh']);
        $this->assertSame(1, $aggregate->funnel['eligible']);
    }

    public function test_n_1_2_devolve_preco_indicativo_low_nunca_vazio(): void
    {
        $this->seedSnapshot(['price' => 38000]);
        $this->seedSnapshot(['price' => 42000]);

        $aggregate = $this->makeAggregate();
        $this->service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);
        $aggregate->refresh();

        $this->assertSame('success', $aggregate->status);
        $this->assertSame('low', $aggregate->confidence);
        $this->assertSame(40000.0, (float) $aggregate->median_price);
        $this->assertNull($aggregate->p25_price);
        $this->assertNull($aggregate->p75_price);
        $this->assertCount(2, $aggregate->top_comparables);
    }

    public function test_pool_vazio_devolve_none_com_funil(): void
    {
        $aggregate = $this->makeAggregate();
        $this->service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);
        $aggregate->refresh();

        $this->assertSame('none', $aggregate->status);
        $this->assertSame('none', $aggregate->confidence);
        $this->assertSame('motorhome_similarity_v1', $aggregate->method);
        $this->assertNull($aggregate->median_price);
        $this->assertSame(0, $aggregate->funnel['layout_total']);
        $this->assertSame(2018, $aggregate->funnel['year_from']);
        $this->assertSame(2022, $aggregate->funnel['year_to']);
    }

    public function test_funil_regista_degrau_pos_dedupe_e_invariante(): void
    {
        // Cross-posting SV+CJ do MESMO veículo (dedup_hash partilhado) + 1
        // independente: eligible conta o pool bruto (3), after_dedupe explica
        // a perda (2) e fecha o invariante com comparables_count.
        $this->seedSnapshot(['price' => 40000, 'dedup_hash' => 'dup-1', 'source' => 'standvirtual']);
        $this->seedSnapshot(['price' => 39500, 'dedup_hash' => 'dup-1', 'source' => 'custojusto']);
        $this->seedSnapshot(['price' => 41000]);

        $aggregate = $this->makeAggregate();
        $this->service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);
        $aggregate->refresh();

        $this->assertSame(3, $aggregate->funnel['eligible']);
        $this->assertSame(2, $aggregate->funnel['after_dedupe']);
        $this->assertSame(
            $aggregate->comparables_count,
            $aggregate->funnel['after_dedupe'] - $aggregate->outliers_removed
        );
        // O sobrevivente do par é o SV (prioridade de fonte na dedupe).
        $prices = array_column($aggregate->top_comparables, 'price');
        $this->assertContains(40000.0, array_map('floatval', $prices));
        $this->assertNotContains(39500.0, array_map('floatval', $prices));
    }

    public function test_guard_motorhome_sem_categoria_regista_failed_sem_scrape(): void
    {
        Queue::fake();

        $semCategoria = Car::factory()->create([
            'company_id'        => $this->company->id,
            'car_brand_id'      => $this->motorhome->car_brand_id,
            'car_model_id'      => $this->motorhome->car_model_id,
            'car_category_id'   => null,
            'vehicle_type'      => 'motorhome',
            'registration_year' => 2020,
            'price_gross'       => 42000,
        ]);

        $aggregate = $this->service->snapshotForCar($semCategoria);

        $this->assertSame('failed', $aggregate->status);
        Queue::assertNotPushed(ScrapeMarketSnapshotJob::class);
    }

    public function test_snapshot_for_car_motorhome_com_categoria_dispara_e_search_url_por_tipologia(): void
    {
        Queue::fake();

        $aggregate = $this->service->snapshotForCar($this->motorhome);

        $this->assertSame('pending', $aggregate->status);
        Queue::assertPushed(ScrapeMarketSnapshotJob::class);

        // O link espelha o motor: tipologia + ano ±2, SEM marca.
        $this->assertStringContainsString('filter_enum_body_type%5D=perfiladas', $aggregate->search_url);
        $this->assertStringContainsString('year%3Afrom%5D=2018', $aggregate->search_url);
        $this->assertStringContainsString('year%3Ato%5D=2022', $aggregate->search_url);
        $this->assertStringNotContainsString('benimar', strtolower($aggregate->search_url));
    }

    public function test_capucine_slug_interno_agora_mapeia(): void
    {
        // 4 viaturas reais ficavam failed porque o mapa só cobria 'capucino'.
        $this->assertSame('capucine', MarketSnapshotService::bodyTypeFor('capucine'));
        $this->assertSame('capucine', MarketSnapshotService::bodyTypeFor('capucino'));
        $this->assertNull(MarketSnapshotService::bodyTypeFor('inexistente'));
    }

    public function test_target_beds_deriva_de_capacities_ou_desconhecido(): void
    {
        // Sem vehicle_attributes → desconhecido.
        $this->assertNull($this->service->targetBedsFor($this->motorhome));

        // Camas legacy sem capacity → desconhecido (não subcontar).
        $this->motorhome->vehicleAttribute()->create([
            'attributes' => ['beds' => [['type' => 'cama_convertivel'], ['type' => 'cama_transversal']]],
        ]);
        $this->assertNull($this->service->targetBedsFor($this->motorhome->fresh()));

        // Capacities completas → soma.
        $this->motorhome->vehicleAttribute()->update([
            'attributes' => ['beds' => [
                ['type' => 'cama_casal', 'capacity' => 2],
                ['type' => 'basculante', 'capacity' => 2],
            ]],
        ]);
        $this->assertSame(4, $this->service->targetBedsFor($this->motorhome->fresh()));
    }
}
