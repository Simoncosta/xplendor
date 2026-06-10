<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarMarketSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MS2.a — ingestão multi-fonte via /market/snapshots.
 *
 * Endpoint protegido por scraper token (Bearer). Form Request apertado para
 * source ∈ {'standvirtual', 'custojusto'} (enum fechado).
 *
 * dedup_hash é placeholder nesta fase — cálculo virá no MS2.d (na ingestão).
 * Aqui só verifica que a coluna existe e aceita null + valor explícito.
 */
class MarketSnapshotIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-scraper-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.scraper.token' => self::TOKEN]);
    }

    private function baseSnapshot(array $overrides = []): array
    {
        return array_merge([
            'external_id'    => 'sv-' . uniqid('', true),
            'source'         => 'standvirtual',
            'vehicle_type'   => 'motorhome',
            'title'          => 'Carado T 449',
            'url'            => 'https://www.standvirtual.com/autocaravanas/anuncio/carado-t-449',
            'brand'          => 'Carado',
            'model'          => 'T 449',
            'year'           => 2021,
            'price'          => 68500.00,
            'price_currency' => 'EUR',
        ], $overrides);
    }

    private function ingest(array $payload)
    {
        return $this->postJson('/api/market/snapshots', $payload, [
            'Authorization' => 'Bearer ' . self::TOKEN,
        ]);
    }

    public function test_ingestion_accepts_standvirtual_source(): void
    {
        $response = $this->ingest(['snapshots' => [$this->baseSnapshot()]]);

        $response->assertOk();
        $this->assertDatabaseCount('car_market_snapshots', 1);
        $this->assertDatabaseHas('car_market_snapshots', ['source' => 'standvirtual']);
    }

    public function test_ingestion_accepts_custojusto_source(): void
    {
        $snapshot = $this->baseSnapshot([
            'external_id' => '45028266',
            'source'      => 'custojusto',
            'url'         => 'https://www.custojusto.pt/portugal/veiculos/autocaravanas-reboques/perfilada/carado-t-449-45028266',
        ]);

        $response = $this->ingest(['snapshots' => [$snapshot]]);

        $response->assertOk();
        $this->assertDatabaseHas('car_market_snapshots', [
            'external_id' => '45028266',
            'source'      => 'custojusto',
        ]);
    }

    public function test_ingestion_rejects_unknown_source_with_422(): void
    {
        // olx, autoSAPO e qualquer outro slug ficam fora até serem adicionados
        // expressamente em StoreMarketSnapshotRequest::VALID_SOURCES.
        $response = $this->ingest([
            'snapshots' => [$this->baseSnapshot(['source' => 'olx'])],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['snapshots.0.source']);
        $this->assertDatabaseCount('car_market_snapshots', 0);
    }

    public function test_ingestion_rejects_missing_source_with_422(): void
    {
        // source é required — sem default. O scraper actual já o envia
        // obrigatoriamente (auditado 2026-06-10), logo não há retro-compat.
        $snapshot = $this->baseSnapshot();
        unset($snapshot['source']);

        $response = $this->ingest(['snapshots' => [$snapshot]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['snapshots.0.source']);
    }

    public function test_dedup_hash_persisted_with_sha1_format(): void
    {
        // MS2.d — dedup_hash é calculado na ingestão (sha1 = 40 chars hex).
        // Era nullable no MS2.a (placeholder); agora qualquer ingestão preenche.
        $response = $this->ingest(['snapshots' => [$this->baseSnapshot()]]);
        $response->assertOk();

        /** @var CarMarketSnapshot|null $snapshot */
        $snapshot = CarMarketSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->dedup_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $snapshot->dedup_hash);
    }

    public function test_endpoint_requires_scraper_bearer_token(): void
    {
        $response = $this->postJson('/api/market/snapshots', [
            'snapshots' => [$this->baseSnapshot()],
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('car_market_snapshots', 0);
    }
}
