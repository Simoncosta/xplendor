<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarMarketSnapshot;
use App\Services\CarMarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MS2.d — dedup cross-fonte na ingestão de snapshots.
 *
 * Hash: sha1( normalize(title) | year | price_bucket_100 )
 *   - title: lowercase, sem acentos, [^a-z0-9]+ → "_", trim
 *   - year:  exacto (null → "NA")
 *   - price: bucket de 100€ por round (não floor)
 *
 * Ordem cross-fonte determinística: standvirtual primeiro → vence em colisão.
 * Standvirtual tem campos mais ricos (fuel/gearbox/power_hp) e é a fonte
 * canónica do mercado; daí prioridade.
 */
class SnapshotDedupTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): CarMarketSnapshotService
    {
        return $this->app->make(CarMarketSnapshotService::class);
    }

    private function makeSnapshot(array $overrides): array
    {
        return array_merge([
            'external_id'    => 'sv-' . uniqid('', true),
            'source'         => 'standvirtual',
            'vehicle_type'   => 'motorhome',
            'title'          => 'McLouis Ness 80',
            'url'            => 'https://www.standvirtual.com/anuncio/x',
            'brand'          => 'Mclouis',
            'model'          => 'Ness',
            'year'           => 2016,
            'price'          => 67900,
            'price_currency' => 'EUR',
            'scraped_at'     => now()->toDateTimeString(),
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // computeDedupHash — hash determinístico, simétrico cross-fonte
    // ─────────────────────────────────────────────────────────────────────────

    public function test_hash_collides_for_same_listing_across_sources(): void
    {
        // CASO DE ACEITAÇÃO EMPÍRICO: McLouis Ness 80, 2016, €67.900
        // existe simultaneamente no Standvirtual (listID 8097531924) e
        // no CustoJusto (listID 44929540). Anúncio do mesmo profissional
        // cross-postado nas duas plataformas.
        $hashSv = $this->makeService()->computeDedupHash('McLouis Ness 80', 2016, 67900);
        $hashCj = $this->makeService()->computeDedupHash('McLouis Ness 80', 2016, 67900);

        $this->assertSame($hashSv, $hashCj,
            'Cross-posting Standvirtual↔CustoJusto tem de gerar o mesmo hash');
    }

    public function test_hash_collides_for_accent_variants(): void
    {
        // Bürstner (com trema) vs Burstner (sem) — vendedores escrevem das
        // duas formas; o mesmo anúncio pode aparecer com qualquer.
        $a = $this->makeService()->computeDedupHash('Bürstner Ixeo Time it 700', 2016, 64500);
        $b = $this->makeService()->computeDedupHash('Burstner Ixeo Time it 700', 2016, 64500);

        $this->assertSame($a, $b, 'Acentos têm de ser normalizados (NFKD + ASCII)');
    }

    public function test_hash_collides_for_case_variants(): void
    {
        // McLouis / MCLOUIS / mclouis — capitalização aleatória entre fontes.
        $a = $this->makeService()->computeDedupHash('McLouis Ness 80', 2016, 67900);
        $b = $this->makeService()->computeDedupHash('MCLOUIS NESS 80', 2016, 67900);
        $c = $this->makeService()->computeDedupHash('mclouis ness 80', 2016, 67900);

        $this->assertSame($a, $b, 'Hash tem de ser case-insensitive (lowercase)');
        $this->assertSame($a, $c);
    }

    public function test_hash_buckets_price_with_round(): void
    {
        // Bucket de 100€ por round() — 67999 e 68001 caem ambos no bucket 68000.
        // Tolerância de ~50€ em cima e em baixo do bucket — capta vendedor
        // que atualizou ligeiramente o preço.
        $a = $this->makeService()->computeDedupHash('Carado T 449', 2021, 67999);
        $b = $this->makeService()->computeDedupHash('Carado T 449', 2021, 68001);
        $this->assertSame($a, $b, 'Preços em ±50€ do bucket têm de colidir');

        // Mas preços em buckets distintos não colidem.
        // round(67900/100) = 679 → 67900; round(67950/100) = 680 → 68000.
        $c = $this->makeService()->computeDedupHash('Carado T 449', 2021, 67900);
        $d = $this->makeService()->computeDedupHash('Carado T 449', 2021, 67950);
        $this->assertNotSame($c, $d, 'Preços em buckets distintos NÃO devem colidir');
    }

    public function test_hash_differs_when_year_differs(): void
    {
        // Anúncios com ano diferente são viaturas diferentes — não colidir.
        $a = $this->makeService()->computeDedupHash('McLouis Ness 80', 2016, 67900);
        $b = $this->makeService()->computeDedupHash('McLouis Ness 80', 2017, 67900);
        $this->assertNotSame($a, $b);
    }

    public function test_hash_uses_NA_token_for_null_year(): void
    {
        // Year null vs year 0: NÃO devem colidir (year 0 é dado anómalo,
        // null é "ausente"). O token "NA" para null garante isso.
        $a = $this->makeService()->computeDedupHash('McLouis Ness 80', null, 67900);
        $b = $this->makeService()->computeDedupHash('McLouis Ness 80', 0,    67900);
        $this->assertNotSame($a, $b);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // persistSnapshots — dedup determinístico + standvirtual ganha
    // ─────────────────────────────────────────────────────────────────────────

    public function test_persist_keeps_standvirtual_when_cross_posting_collides(): void
    {
        // Mesmo anúncio em SV (id 8097531924) e CJ (id 44929540).
        // Após dedup: 1 fica, e o que fica tem source='standvirtual'.
        $sv = $this->makeSnapshot([
            'external_id' => '8097531924',
            'source'      => 'standvirtual',
            'url'         => 'https://www.standvirtual.com/anuncio/mclouis-ness-80',
            'title'       => 'McLouis Ness 80',
            'year'        => 2016,
            'price'       => 67900,
            'fuel'        => 'Diesel',   // SV tem campos ricos
            'gearbox'     => 'Manual',
            'power_hp'    => 130,
        ]);
        $cj = $this->makeSnapshot([
            'external_id' => '44929540',
            'source'      => 'custojusto',
            'url'         => 'https://www.custojusto.pt/coimbra/.../mclouis-ness-80-44929540',
            'title'       => 'McLouis Ness 80',
            'year'        => 2016,
            'price'       => 67900,
            'fuel'        => null,        // CJ não extrai estes
            'gearbox'     => null,
            'power_hp'    => null,
        ]);

        // Passa CJ ANTES de SV deliberadamente: prova que o usort
        // re-ordena standvirtual primeiro independentemente do input.
        $this->makeService()->persistSnapshots([$cj, $sv]);

        $this->assertDatabaseCount('car_market_snapshots', 1);
        $surviving = CarMarketSnapshot::query()->first();
        $this->assertSame('standvirtual', $surviving->source);
        $this->assertSame('8097531924', $surviving->external_id);
        $this->assertSame('Diesel', $surviving->fuel,
            'O SV ganha — fica com os campos ricos preservados');
    }

    public function test_persist_dedups_within_same_source(): void
    {
        // Mesmo SV duas vezes (e.g. duas execuções consecutivas, ou repetição
        // dentro de um payload — defensivo).
        $a = $this->makeSnapshot([
            'external_id' => '8097531924',
            'title'       => 'McLouis Ness 80',
            'year'        => 2016,
            'price'       => 67900,
        ]);
        $b = $this->makeSnapshot([
            'external_id' => '8097531999',  // listID diferente — duplicação no scrape
            'title'       => 'McLouis Ness 80',  // mesmo título
            'year'        => 2016,
            'price'       => 67900,
        ]);

        $this->makeService()->persistSnapshots([$a, $b]);

        $this->assertDatabaseCount('car_market_snapshots', 1);
    }

    public function test_persist_keeps_non_colliding_listings(): void
    {
        // Anúncios genuinamente diferentes (preço diferente fora do bucket
        // OU título diferente OU ano diferente) NÃO devem deduplicar.
        $a = $this->makeSnapshot([
            'external_id' => 'sv-1',
            'title'       => 'McLouis Ness 80',
            'year'        => 2016,
            'price'       => 67900,
        ]);
        $b = $this->makeSnapshot([
            'external_id' => 'sv-2',
            'title'       => 'McLouis Ness 75',  // modelo diferente no título
            'year'        => 2016,
            'price'       => 67900,
        ]);
        $c = $this->makeSnapshot([
            'external_id' => 'sv-3',
            'title'       => 'McLouis Ness 80',
            'year'        => 2017,                // ano diferente
            'price'       => 67900,
        ]);

        $this->makeService()->persistSnapshots([$a, $b, $c]);

        $this->assertDatabaseCount('car_market_snapshots', 3);
    }

    public function test_persist_logs_duplicates_count_per_source(): void
    {
        // Visibilidade: o log de dedup é a fonte de verdade para diagnóstico
        // de "porque é que só X snapshots ficaram". Os contadores ANTES/DEPOIS
        // separam falha de scraping (sem snapshots à entrada) de dedup
        // (snapshots filtrados na ingestão).
        Log::spy();

        $sv = $this->makeSnapshot([
            'external_id' => '8097531924', 'source' => 'standvirtual',
            'title' => 'McLouis Ness 80', 'year' => 2016, 'price' => 67900,
        ]);
        $cj = $this->makeSnapshot([
            'external_id' => '44929540', 'source' => 'custojusto',
            'title' => 'McLouis Ness 80', 'year' => 2016, 'price' => 67900,
        ]);
        $other = $this->makeSnapshot([
            'external_id' => 'sv-2', 'source' => 'standvirtual',
            'title' => 'Adria Matrix 670', 'year' => 2020, 'price' => 65000,
        ]);

        $this->makeService()->persistSnapshots([$sv, $cj, $other]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $msg, array $ctx): bool {
                if ($msg !== '[market-snapshots] dedup completed') {
                    return false;
                }
                return $ctx['before_dedup']        === ['standvirtual' => 2, 'custojusto' => 1]
                    && $ctx['after_dedup']         === 2
                    && $ctx['duplicates_skipped']  === ['custojusto' => 1];
            })->once();
    }

    public function test_dedup_hash_persisted_in_database(): void
    {
        // Verifica que o hash não fica só em memória — vai para a coluna
        // dedup_hash (indexada, prepara queries futuras).
        $snapshot = $this->makeSnapshot([
            'external_id' => '8097531924',
            'title'       => 'McLouis Ness 80',
            'year'        => 2016,
            'price'       => 67900,
        ]);
        $this->makeService()->persistSnapshots([$snapshot]);

        $expected = $this->makeService()->computeDedupHash('McLouis Ness 80', 2016, 67900);
        $row = CarMarketSnapshot::query()->first();
        $this->assertSame($expected, $row->dedup_hash);
    }
}
