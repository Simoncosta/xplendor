<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\CarMarketSnapshot;
use App\Models\Company;
use App\Repositories\CarMarketSnapshotRepository;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FASE 1 — teste de INTEGRAÇÃO do fluxo COMPLETO do motor de autocaravanas:
 * o caminho que o ScrapeMarketSnapshotJob percorre DEPOIS do scrape
 * (service → repositório → engine → persistência).
 *
 * PORQUÊ ESTE FICHEIRO: o bug "Call to undefined method
 * CarMarketSnapshotRepository::getMotorhomeSimilarityPool()" que apareceu em
 * runtime NÃO era um defeito de código (o método existe em disco e os testes
 * do engine passavam) — foi um worker de fila DESATUALIZADO, com a classe
 * antiga em memória de antes de o método ser adicionado. Os testes do engine
 * exercitam o MotorhomeMarketEngine isolado; o MotorhomeSimilarityAggregateTest
 * exercita o service. Faltava um teste que amarrasse o CONTRATO entre o service
 * e o repositório LIGADO no contentor — de forma a que um método REALMENTE em
 * falta (ou renomeado, ou com assinatura trocada) rebente aqui, em teste, e
 * não só em produção.
 *
 * Nota: o Job faz `docker exec … python main.py` para o scrape; essa parte não
 * é exercitável em teste sem docker-in-docker. Este teste cobre exactamente o
 * ramo pós-scrape do handle() do Job — que é onde o erro rebentava.
 */
class MotorhomeMarketFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Car $motorhome;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc' => '500000003', 'fiscal_name' => 'Flow Co',
            'plan_id' => $planId, 'subscription_status' => 'active',
        ]);

        $brand = CarBrand::create(['name' => 'Challenger', 'slug' => 'challenger', 'vehicle_type' => 'motorhome']);
        $model = CarModel::create(['name' => 'Genesis', 'car_brand_id' => $brand->id]);
        // Categoria REAL do car_id 76 do relato (capucine) — mapeável.
        $category = CarCategory::create(['name' => 'Capucine', 'slug' => 'capucine', 'vehicle_type' => 'motorhome']);

        $this->motorhome = Car::factory()->create([
            'company_id'         => $this->company->id,
            'car_brand_id'       => $brand->id,
            'car_model_id'       => $model->id,
            'car_category_id'    => $category->id,
            'vehicle_type'       => 'motorhome',
            'registration_year'  => 2007,
            'price_gross'        => 28000,
            'engine_capacity_cc' => 2800,
        ]);
    }

    private function seedSnapshot(array $overrides = []): void
    {
        static $i = 0;
        $i++;

        CarMarketSnapshot::create(array_merge([
            'external_id'  => 'flow-' . $i,
            'source'       => 'standvirtual',
            'vehicle_type' => 'motorhome',
            'brand'        => 'Challenger',
            'model'        => 'Genesis',
            'year'         => 2007,
            'title'        => 'Challenger Genesis ' . $i,
            'url'          => 'https://example.test/flow/' . $i,
            'layout'       => 'capucine',
            'price'        => 27000 + ($i * 500),
            'displacement' => 2800,
            'beds'         => 4,
            'dedup_hash'   => 'flow-hash-' . $i,
            'scraped_at'   => now(),
        ], $overrides));
    }

    /**
     * GUARD DE CONTRATO: o repositório LIGADO no contentor tem de expor
     * getMotorhomeSimilarityPool() com a assinatura e o retorno que o service
     * consome. Este teste, sozinho, teria falhado imediatamente se o método
     * estivesse em falta ou renomeado no repositório.
     */
    public function test_repositorio_ligado_expoe_o_pool_de_similaridade(): void
    {
        $repo = app(CarMarketSnapshotRepositoryInterface::class);

        $this->assertInstanceOf(CarMarketSnapshotRepository::class, $repo);
        $this->assertTrue(
            method_exists($repo, 'getMotorhomeSimilarityPool'),
            'O repositório ligado tem de expor getMotorhomeSimilarityPool() — o service chama-o no ramo das autocaravanas.'
        );

        $result = $repo->getMotorhomeSimilarityPool('capucine', 2007);

        $this->assertArrayHasKey('pool', $result);
        $this->assertArrayHasKey('funnel', $result);
        $this->assertArrayHasKey('eligible', $result['funnel']);
    }

    /**
     * FLUXO COMPLETO pós-scrape: exactamente a chamada que o handle() do Job
     * faz para autocaravanas (service resolvido do contentor → repositório
     * ligado → engine → persistência). Com comparáveis em BD, calcula um
     * agregado SEM rebentar.
     */
    public function test_fluxo_completo_calcula_agregado_de_autocaravana(): void
    {
        // Simula a BD após um scrape bem-sucedido (o Python já enviou snapshots).
        $this->seedSnapshot(['price' => 26000, 'year' => 2007]);
        $this->seedSnapshot(['price' => 27500, 'year' => 2006]);
        $this->seedSnapshot(['price' => 28000, 'year' => 2008]);
        $this->seedSnapshot(['price' => 29000, 'year' => 2007]);
        $this->seedSnapshot(['price' => 30000, 'year' => 2009]);

        $aggregate = CarMarketAggregate::create([
            'car_id' => $this->motorhome->id, 'vehicle_type' => 'motorhome',
            'status' => 'pending', 'confidence' => 'none', 'comparables_count' => 0,
        ]);

        // O MESMO objecto que o Job recebe por injeção no handle().
        $service = app(MarketSnapshotService::class);
        $service->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);

        $aggregate->refresh();

        $this->assertSame('success', $aggregate->status);
        $this->assertSame('motorhome_similarity_v1', $aggregate->method);
        $this->assertSame(5, $aggregate->comparables_count);
        $this->assertNotNull($aggregate->median_price);
        $this->assertGreaterThan(0, (float) $aggregate->median_price);
        $this->assertSame('capucine', $aggregate->funnel['layout']);
    }

    /**
     * O fluxo completo tolera POUCOS comparáveis sem rebentar: n=1 → low, com
     * preço indicativo (nunca vazio, nunca erro).
     */
    public function test_fluxo_completo_com_poucos_comparaveis_nao_rebenta(): void
    {
        $this->seedSnapshot(['price' => 27500, 'year' => 2007]);

        $aggregate = CarMarketAggregate::create([
            'car_id' => $this->motorhome->id, 'vehicle_type' => 'motorhome',
            'status' => 'pending', 'confidence' => 'none', 'comparables_count' => 0,
        ]);

        app(MarketSnapshotService::class)
            ->computeAndPersistMotorhomeAggregate($this->motorhome, $aggregate->id);

        $aggregate->refresh();

        $this->assertSame('success', $aggregate->status);
        $this->assertSame('low', $aggregate->confidence);
        $this->assertSame(1, $aggregate->comparables_count);
        $this->assertNotNull($aggregate->median_price);
    }
}
