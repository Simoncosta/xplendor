<?php

namespace Tests\Feature;

use App\Models\CarMarketSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE 0 (motor de autocaravanas) — ingestão das specs novas no endpoint
 * POST /api/market/snapshots: beds, layout, displacement, length.
 *
 * Garante: (1) persistência ponta-a-ponta das colunas novas; (2) validação
 * (layout é enum fechado; gamas de beds/displacement/length); (3) snapshots
 * SEM as specs continuam a ser aceites (retro-compatibilidade com o payload
 * antigo do scraper); (4) o upsert atualiza as specs em re-envios.
 */
class MarketSnapshotMotorhomeSpecsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-scraper-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.scraper.token' => self::TOKEN]);
    }

    private function post_snapshots(array $snapshot)
    {
        return $this->withToken(self::TOKEN)->postJson('/api/market/snapshots', [
            'snapshots' => [$snapshot],
        ]);
    }

    private function base_snapshot(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'mh-1',
            'source' => 'standvirtual',
            'vehicle_type' => 'motorhome',
            'brand' => 'Benimar',
            'model' => 'Tessoro',
            'year' => 2019,
            'title' => 'Benimar Tessoro 481',
            'url' => 'https://www.standvirtual.com/autocaravanas/anuncio/x.html',
            'price' => 64900,
        ], $overrides);
    }

    public function test_persiste_specs_de_autocaravana(): void
    {
        $this->post_snapshots($this->base_snapshot([
            'beds' => 4,
            'layout' => 'perfiladas',
            'displacement' => 2300,
            'length' => 7.45,
        ]))->assertSuccessful();

        $snapshot = CarMarketSnapshot::query()
            ->where('source', 'standvirtual')->where('external_id', 'mh-1')->firstOrFail();

        $this->assertSame(4, $snapshot->beds);
        $this->assertSame('perfiladas', $snapshot->layout);
        $this->assertSame(2300, $snapshot->displacement);
        $this->assertSame(7.45, (float) $snapshot->length);
    }

    public function test_payload_antigo_sem_specs_continua_aceite(): void
    {
        $this->post_snapshots($this->base_snapshot())->assertSuccessful();

        $snapshot = CarMarketSnapshot::query()
            ->where('external_id', 'mh-1')->firstOrFail();

        $this->assertNull($snapshot->beds);
        $this->assertNull($snapshot->layout);
        $this->assertNull($snapshot->displacement);
        $this->assertNull($snapshot->length);
    }

    public function test_layout_fora_do_vocabulario_e_rejeitado(): void
    {
        $this->post_snapshots($this->base_snapshot(['layout' => 'atrelado-tenda']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['snapshots.0.layout']);
    }

    public function test_gamas_invalidas_sao_rejeitadas(): void
    {
        $this->post_snapshots($this->base_snapshot(['beds' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['snapshots.0.beds']);

        $this->post_snapshots($this->base_snapshot(['displacement' => 200]))
            ->assertStatus(422)->assertJsonValidationErrors(['snapshots.0.displacement']);

        $this->post_snapshots($this->base_snapshot(['length' => 25]))
            ->assertStatus(422)->assertJsonValidationErrors(['snapshots.0.length']);
    }

    public function test_upsert_atualiza_specs_em_reenvio(): void
    {
        $this->post_snapshots($this->base_snapshot(['beds' => 4, 'layout' => 'perfiladas']))
            ->assertSuccessful();

        // Re-scrape do MESMO anúncio (source+external_id) com specs mais ricas
        // (ex.: o fetch de detalhe passou a trazer a descrição completa).
        $this->post_snapshots($this->base_snapshot([
            'beds' => 6,
            'layout' => 'integral',
            'displacement' => 2287,
            'length' => 6.99,
        ]))->assertSuccessful();

        $this->assertSame(1, CarMarketSnapshot::query()->count());
        $snapshot = CarMarketSnapshot::query()->firstOrFail();
        $this->assertSame(6, $snapshot->beds);
        $this->assertSame('integral', $snapshot->layout);
        $this->assertSame(2287, $snapshot->displacement);
        $this->assertSame(6.99, (float) $snapshot->length);
    }
}
