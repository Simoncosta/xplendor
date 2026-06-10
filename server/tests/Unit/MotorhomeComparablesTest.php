<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarModel;
use App\Repositories\CarMarketSnapshotRepository;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotorhomeComparablesTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): MarketSnapshotService
    {
        return new MarketSnapshotService(
            $this->app->make(CarMarketSnapshotRepositoryInterface::class)
        );
    }

    private function seedSnapshot(array $extra): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);
        $repo->upsertSnapshots([array_merge([
            'external_id'    => 'sv-' . uniqid('', true),
            'source'         => 'standvirtual',
            'vehicle_type'   => 'motorhome',
            'title'          => 'Test listing',
            'url'            => 'https://standvirtual.com/anuncio/' . rand(1, 99999),
            'brand'          => 'McLouis',
            'model'          => 'Yearling',
            'year'           => 2019,
            'price'          => 55000.00,
            'price_currency' => 'EUR',
            'fuel'           => 'diesel',
            'gearbox'        => 'manual',
            // MS2.d/e — dedup_hash: default null (legacy); testes que querem
            // colidir passam o mesmo hash explicitamente via $extra.
            'dedup_hash'     => null,
            'scraped_at'     => now()->toDateTimeString(),
            'created_at'     => now()->toDateTimeString(),
            'updated_at'     => now()->toDateTimeString(),
        ], $extra)]);
    }

    private function makeCar(
        string $vehicleType,
        string $brand,
        string $model,
        int $year,
        float $priceGross,
        ?float $promo = null,
    ): Car {
        $car = new Car();
        $car->forceFill([
            'vehicle_type'      => $vehicleType,
            'registration_year' => $year,
            'price_gross'       => $priceGross,
            'promo_price_gross' => $promo,
        ]);

        $brandModel = new CarBrand();
        $brandModel->forceFill(['name' => $brand]);
        $car->setRelation('brand', $brandModel);

        $modelModel = new CarModel();
        $modelModel->forceFill(['name' => $model]);
        $car->setRelation('model', $modelModel);

        return $car;
    }

    // -------------------------------------------------------------------------
    // New repository method: brand + price band + year, ignoring model
    // -------------------------------------------------------------------------

    public function test_byBrandPrice_filters_by_brand_price_and_year_ignoring_model(): void
    {
        // In band (price 43.5k–72.5k, year 2014–2024), different models — should match
        $this->seedSnapshot(['external_id' => 'in-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 50000]);
        $this->seedSnapshot(['external_id' => 'in-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2020, 'price' => 60000]);
        // Out — price too high
        $this->seedSnapshot(['external_id' => 'out-price', 'brand' => 'McLouis', 'model' => 'Tandy', 'year' => 2019, 'price' => 120000]);
        // Out — different brand
        $this->seedSnapshot(['external_id' => 'out-brand', 'brand' => 'Adria', 'model' => 'Matrix', 'year' => 2019, 'price' => 55000]);
        // Out — year outside window
        $this->seedSnapshot(['external_id' => 'out-year', 'brand' => 'McLouis', 'model' => 'Menfys', 'year' => 2000, 'price' => 55000]);

        $repo = $this->app->make(CarMarketSnapshotRepository::class);
        $car  = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);

        // ±25% of 58000 = [43500, 72500]; year ±5 = [2014, 2024]
        $result = $repo->getComparableSnapshotsByBrandPrice($car, 5, 43500.0, 72500.0);

        $ids = $result->pluck('external_id')->all();
        $this->assertContains('in-1', $ids);
        $this->assertContains('in-2', $ids);
        $this->assertNotContains('out-price', $ids);
        $this->assertNotContains('out-brand', $ids);
        $this->assertNotContains('out-year', $ids);
        $this->assertCount(2, $result);
    }

    public function test_byBrandPrice_returns_empty_without_brand_or_year(): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);

        $noBrand = new Car();
        $noBrand->forceFill(['vehicle_type' => 'motorhome', 'registration_year' => 2019]);
        $noBrand->setRelation('brand', null);

        $this->assertTrue($repo->getComparableSnapshotsByBrandPrice($noBrand, 5, 1.0, 9999999.0)->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Cascade: motorhome falls back to brand+price when exact model gives zero
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_falls_back_to_brand_price(): void
    {
        // Same brand, different models (NOT "Menfys Van"), within price band + year
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 52000, 'fuel' => 'diesel']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'fuel' => 'diesel']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);
        $car->forceFill(['fuel_type' => 'Diesel', 'transmission' => 'Manual', 'power_hp' => 130]);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
    }

    // -------------------------------------------------------------------------
    // Regression: cars do NOT use the brand+price fallback (model exact only)
    // -------------------------------------------------------------------------

    private function withCategory(Car $car, string $slug): Car
    {
        $cat = new CarCategory();
        $cat->forceFill(['slug' => $slug, 'name' => ucfirst($slug)]);
        $car->setRelation('category', $cat);
        return $car;
    }

    // -------------------------------------------------------------------------
    // New degree: motorhome + category (body_type) + year
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_uses_category_when_available(): void
    {
        // Snapshots no body_type 'furgao' (Standvirtual slug para campervan).
        $this->seedSnapshot(['external_id' => 'fv-1', 'category' => 'furgao', 'brand' => 'Adria', 'model' => 'Twin', 'year' => 2019, 'price' => 56000]);
        $this->seedSnapshot(['external_id' => 'fv-2', 'category' => 'furgao', 'brand' => 'Pössl', 'model' => 'Roadcar', 'year' => 2020, 'price' => 60000]);
        // Outras categorias — não devem entrar.
        $this->seedSnapshot(['external_id' => 'int-1', 'category' => 'integral', 'brand' => 'McLouis', 'model' => 'Nevis', 'year' => 2019, 'price' => 95000]);

        $car = $this->withCategory(
            $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000),
            'campervan',
        );

        $result = $this->makeService()->getComparables($car);

        $ids = $result['snapshots']->pluck('external_id')->all();
        $this->assertTrue($result['fallback_used']);
        $this->assertContains('fv-1', $ids);
        $this->assertContains('fv-2', $ids);
        $this->assertNotContains('int-1', $ids);
    }

    public function test_getComparables_motorhome_falls_back_to_brand_price_when_category_empty(): void
    {
        // Sem snapshots da mesma categoria, mas a marca tem snapshots dentro
        // da faixa de preço → cai para o degrau brand+price (Attempt 5).
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'category' => 'perfiladas']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'category' => 'perfiladas']);

        $car = $this->withCategory(
            $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000),
            'campervan',
        );
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
        // Veio do brand+price (Attempt 5), não do category (Attempt 4).
        $this->assertContains('mc-1', $result['snapshots']->pluck('external_id')->all());
    }

    public function test_getComparables_car_does_not_use_brand_price_fallback(): void
    {
        // BMW snapshots, different model than the target car, within price band
        $this->seedSnapshot(['external_id' => 'bmw-1', 'vehicle_type' => 'car', 'brand' => 'BMW', 'model' => '320d', 'year' => 2020, 'price' => 30000, 'fuel' => 'diesel']);
        $this->seedSnapshot(['external_id' => 'bmw-2', 'vehicle_type' => 'car', 'brand' => 'BMW', 'model' => '520d', 'year' => 2020, 'price' => 32000, 'fuel' => 'diesel']);

        // Car model "M3" not present in snapshots
        $car = $this->makeCar('car', 'BMW', 'M3', 2020, 31000);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertFalse($result['fallback_used']);
        $this->assertTrue($result['snapshots']->isEmpty());
    }

    // -------------------------------------------------------------------------
    // MS1.c — Guard: degrau 5 (brand+price) saltado quando preço efectivo <= 0
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_skips_brand_price_when_effective_price_is_zero(): void
    {
        // Snapshots que NÃO bateriam nos degraus 1-3 (modelo diferente, sem
        // categoria) — só o degrau 5 (brand+price) os poderia apanhar. Mas
        // com preço efectivo 0, a faixa degenera em [0, 0] e o degrau salta
        // sem chamar o repo (e sem dividir por zero).
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'fuel' => 'diesel']);

        // Preço gross 0 + sem promo → effectivePrice() == 0.0
        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 0.0);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        // Degrau 5 saltado → snapshots vazios + fallback_used == false.
        $this->assertFalse($result['fallback_used']);
        $this->assertTrue($result['snapshots']->isEmpty());
    }

    public function test_getComparables_motorhome_uses_brand_price_when_effective_price_positive(): void
    {
        // Regressão do guard: preço > 0 → degrau 5 corre como hoje.
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'fuel' => 'diesel']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000.0);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
    }

    // -------------------------------------------------------------------------
    // MS1.d — listing url propaga em TODOS os degraus da cascata + selectTop5
    // -------------------------------------------------------------------------
    //
    // Auditoria 2026-06-10 confirmou que a coluna `url` existe no schema
    // (migration original 2026-03-30) e todos os 5 selects do repo a incluem.
    // Estes testes blindam essa garantia contra regressões futuras (ex: alguém
    // a apertar selects nos degraus 4 ou 5 e deixar o link de fora).

    public function test_url_propagates_through_attempt_4_category(): void
    {
        // 3 snapshots na mesma categoria com URLs distintos → degrau 4.
        // MS2.e — extensão: source também propaga (CustoJusto alimenta degrau 4).
        $this->seedSnapshot(['external_id' => 'fv-1', 'source' => 'standvirtual', 'category' => 'furgao', 'brand' => 'Adria', 'model' => 'Twin', 'year' => 2019, 'price' => 56000, 'url' => 'https://standvirtual.com/anuncio/fv-1']);
        $this->seedSnapshot(['external_id' => 'fv-2', 'source' => 'custojusto',   'category' => 'furgao', 'brand' => 'Pössl', 'model' => 'Roadcar', 'year' => 2020, 'price' => 60000, 'url' => 'https://www.custojusto.pt/x/.../fv-2']);

        $car = $this->withCategory(
            $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000),
            'campervan',
        );

        $result = $this->makeService()->getComparables($car);

        foreach ($result['snapshots'] as $snap) {
            $this->assertNotEmpty($snap->url, "snapshot {$snap->external_id} sem URL");
            // MS2.e — source preenchido e é uma das fontes válidas.
            $this->assertContains($snap->source, ['standvirtual', 'custojusto'],
                "snapshot {$snap->external_id} sem source válido");
        }
        // Ambas as fontes representadas (CJ alimenta degrau 4 sem brand).
        $sources = $result['snapshots']->pluck('source')->unique()->toArray();
        $this->assertContains('standvirtual', $sources);
        $this->assertContains('custojusto',   $sources);
    }

    public function test_url_propagates_through_attempt_5_brand_price(): void
    {
        // Snapshots da mesma marca, modelos diferentes, dentro da faixa de preço
        // → degrau 5 (brand+price). MS2.e — source propaga.
        $this->seedSnapshot(['external_id' => 'mc-1', 'source' => 'standvirtual', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'url' => 'https://standvirtual.com/anuncio/mc-1']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'source' => 'custojusto',   'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'url' => 'https://www.custojusto.pt/x/.../mc-2']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        foreach ($result['snapshots'] as $snap) {
            $this->assertNotEmpty($snap->url, "snapshot {$snap->external_id} sem URL");
            $this->assertNotEmpty($snap->source, "snapshot {$snap->external_id} sem source");
        }
    }

    public function test_selectTop5_includes_url_and_source_fields(): void
    {
        // MS2.e — extensão: cada item de top_comparables tem 'url' + 'source'
        // (consumido pelo ComparablesList do frontend para chip da fonte na UI).
        $this->seedSnapshot(['external_id' => 'mc-1', 'source' => 'standvirtual', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'url' => 'https://standvirtual.com/anuncio/mc-1']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'source' => 'custojusto',   'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'url' => 'https://www.custojusto.pt/x/.../mc-2']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $service = $this->makeService();
        $result  = $service->getComparables($car);

        $ref    = new \ReflectionClass($service);
        $method = $ref->getMethod('selectTop5');
        $method->setAccessible(true);
        $median = (float) $result['snapshots']->avg('price');
        $top    = $method->invoke($service, $result['snapshots'], $median);

        $this->assertNotEmpty($top);
        foreach ($top as $item) {
            $this->assertArrayHasKey('url',    $item, 'selectTop5 perdeu o url');
            $this->assertArrayHasKey('source', $item, 'selectTop5 perdeu o source');
            $this->assertContains($item['source'], ['standvirtual', 'custojusto']);
        }
    }

    public function test_selectTop5_tolerates_empty_url_gracefully(): void
    {
        // Snapshots legacy podem ter URL vazio (text NOT NULL mas string vazia).
        // selectTop5 deve incluir o item na mesma com url='' — UI degrada
        // (não mostra ↗), mas não rebenta.
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'url' => '']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'url' => 'https://standvirtual.com/anuncio/mc-2']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $service = $this->makeService();
        $result  = $service->getComparables($car);

        $ref    = new \ReflectionClass($service);
        $method = $ref->getMethod('selectTop5');
        $method->setAccessible(true);
        $median = (float) $result['snapshots']->avg('price');
        $top    = $method->invoke($service, $result['snapshots'], $median);

        $this->assertCount(2, $top);
        // Cada item tem a chave 'url', mesmo que vazia.
        foreach ($top as $item) {
            $this->assertArrayHasKey('url', $item);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // MS2.e — dedup em LEITURA + sources_breakdown + isolamento de falha
    //
    // Testamos directamente `computeAggregateData(Collection)` em vez de
    // passar pela cascata — isola o teste da LÓGICA do dedup-em-leitura
    // (selectTop5, breakdown, dedup por hash) das regras de selecção da
    // cascata (que filtram por marca/modelo/categoria antes).
    // ─────────────────────────────────────────────────────────────────────

    private function makeSnapshotModel(array $extra): \App\Models\CarMarketSnapshot
    {
        $s = new \App\Models\CarMarketSnapshot();
        $s->forceFill(array_merge([
            'id'          => rand(100000, 999999),
            'external_id' => 'x-' . uniqid(),
            'source'      => 'standvirtual',
            'title'       => 'Test listing',
            'url'         => 'https://standvirtual.com/x',
            'brand'       => 'A',
            'model'       => 'M',
            'year'        => 2019,
            'price'       => 56000,
            'category'    => 'furgao',
            'fuel'        => null,
            'gearbox'     => null,
            'region'      => null,
            'price_evaluation' => null,
            'scraped_at'  => now(),
            'dedup_hash'  => null,
        ], $extra));
        return $s;
    }

    public function test_dedup_on_read_collapses_cross_source_with_same_hash(): void
    {
        // Mesmo anúncio (Adria Twin) entrou pelas duas fontes em execuções
        // separadas. Cross-source com mesmo dedup_hash deve colapsar; SV vence.
        $hash = sha1('adria_twin|2019|56000');
        $pool = collect([
            $this->makeSnapshotModel(['external_id' => 'cj-AAA', 'source' => 'custojusto',   'price' => 56000, 'dedup_hash' => $hash, 'brand' => 'Adria', 'model' => 'Twin']),
            $this->makeSnapshotModel(['external_id' => 'sv-BBB', 'source' => 'standvirtual', 'price' => 56000, 'dedup_hash' => $hash, 'brand' => 'Adria', 'model' => 'Twin']),
            $this->makeSnapshotModel(['external_id' => 'sv-CCC', 'source' => 'standvirtual', 'price' => 60000, 'dedup_hash' => sha1('possl_roadcar|2019|60000'), 'brand' => 'Pössl', 'model' => 'Roadcar']),
        ]);

        $data = $this->makeService()->computeAggregateData($pool, false);

        $this->assertSame(2, $data['comparables_count'],
            'Cross-source com mesmo dedup_hash deve colapsar para 1');

        // Standvirtual venceu em colisão; CJ foi dropado.
        $externals = collect($data['top_comparables'])->pluck('external_id')->all();
        $this->assertContains('sv-BBB',    $externals);
        $this->assertNotContains('cj-AAA', $externals, 'CustoJusto deve ser dropado em colisão');
        $this->assertContains('sv-CCC',    $externals);
    }

    public function test_dedup_on_read_leaves_null_hashes_intact(): void
    {
        // Snapshots legacy pré-MS2.d têm dedup_hash NULL — passam intactos.
        // 3 nulls → 3 no pool (key única por id evita colapso espúrio).
        $pool = collect([
            $this->makeSnapshotModel(['id' => 1, 'external_id' => 'leg-1', 'price' => 55000, 'dedup_hash' => null]),
            $this->makeSnapshotModel(['id' => 2, 'external_id' => 'leg-2', 'price' => 56000, 'dedup_hash' => null]),
            $this->makeSnapshotModel(['id' => 3, 'external_id' => 'leg-3', 'price' => 57000, 'dedup_hash' => null]),
        ]);

        $data = $this->makeService()->computeAggregateData($pool, false);

        $this->assertSame(3, $data['comparables_count'],
            'Snapshots sem hash devem passar intactos no dedup em leitura');
    }

    public function test_sources_breakdown_computed_after_read_dedup(): void
    {
        // Pool com SV+CJ + cross-posting que colapsa. Breakdown reflecte
        // o pool POS-dedup; cross-postings contam na fonte vencedora (SV).
        $sharedHash = sha1('adria_matrix|2019|56000');
        $pool = collect([
            $this->makeSnapshotModel(['external_id' => 'cj-shared', 'source' => 'custojusto',   'price' => 56000, 'dedup_hash' => $sharedHash]),
            $this->makeSnapshotModel(['external_id' => 'sv-shared', 'source' => 'standvirtual', 'price' => 56000, 'dedup_hash' => $sharedHash]),
            $this->makeSnapshotModel(['external_id' => 'sv-only',   'source' => 'standvirtual', 'price' => 60000, 'dedup_hash' => sha1('possl|2020|60000')]),
            $this->makeSnapshotModel(['external_id' => 'cj-only',   'source' => 'custojusto',   'price' => 58000, 'dedup_hash' => sha1('burstner|2019|58000')]),
        ]);

        $data = $this->makeService()->computeAggregateData($pool, false);

        // Pool final: 3 (cross-posting colapsou). SV ficou com 2 (cross-posting
        // pertence-lhe + sv-only); CJ ficou com 1 (cj-only).
        $this->assertSame(3, $data['comparables_count']);
        $this->assertSame(
            ['standvirtual' => 2, 'custojusto' => 1],
            $data['sources_breakdown'],
            'Breakdown reflecte pool pós-dedup; cross-posting conta no vencedor SV'
        );
    }

    public function test_aggregate_survives_when_custojusto_empty_isolation(): void
    {
        // Critério de aceitação MS2.e (isolamento de falha): CJ devolve []
        // (bloqueado/timeout/HTTP error) → aggregate sai com SV; breakdown só SV.
        $pool = collect([
            $this->makeSnapshotModel(['external_id' => 'sv-1', 'source' => 'standvirtual', 'price' => 55000, 'dedup_hash' => sha1('a|2019|55000')]),
            $this->makeSnapshotModel(['external_id' => 'sv-2', 'source' => 'standvirtual', 'price' => 60000, 'dedup_hash' => sha1('b|2019|60000')]),
            // Zero snapshots CustoJusto — simula bloqueio.
        ]);

        $data = $this->makeService()->computeAggregateData($pool, false);

        $this->assertSame('success', $data['status']);
        $this->assertSame(2, $data['comparables_count']);
        $this->assertSame(['standvirtual' => 2], $data['sources_breakdown']);
        $this->assertArrayNotHasKey('custojusto', $data['sources_breakdown']);
    }
}
