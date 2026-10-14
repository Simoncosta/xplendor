<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\CollectRadarFeedsJob;
use App\Models\RadarItem;
use App\Models\RadarRun;
use App\Models\RadarTopic;
use App\Services\Radar\RadarCollector;
use App\Services\Radar\Sources\RadarHttp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Radar (R1): a recolha à mão e a agendada. Simulação por omissão, só as colunas permitidas,
 * sem repetidos, o teto de 4 pedidos por fonte e por hora, uma fonte em falha não pára as
 * outras, o robots.txt do Google Trends e a fonte desligada do IPMA.
 */
class RadarCollectCommandTest extends TestCase
{
    use FakesRadarFeeds;
    use RefreshDatabase;

    /** As fontes ligadas em config/radar.php (o IPMA está desligado). */
    private const ENABLED = ['google_trends', 'jornal_eco', 'jornal_publico', 'jornal_observador', 'bce_press'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->parse('2026-10-12 18:20:00'));
    }

    public function test_the_simulation_stores_no_items_and_makes_one_request_per_source(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds();

        $this->artisan('radar:collect')->expectsOutputToContain('SIMULAÇÃO')->assertSuccessful();

        $this->assertSame(0, RadarItem::count());
        $this->assertSame(0, RadarTopic::count());
        Http::assertSentCount(count(self::ENABLED));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ipma'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', 'XPLENDOR-Radar/1.0 (leitura de feeds; contacto no site da XPLENDOR)')
            && $r->hasHeader('Accept', RadarHttp::ACCEPT) && ! $r->hasHeader('If-None-Match'));
        // A simulação regista a recolha (conta para o teto por hora), sem itens.
        $this->assertSame(self::ENABLED, RadarRun::orderBy('id')->pluck('source_key')->all());
        $this->assertTrue(RadarRun::all()->every(fn ($r) => $r->dry_run && $r->requests === 1 && $r->status === 'ok'));
        $this->assertSame(22, RadarRun::where('source_key', 'jornal_eco')->value('items_new'), 'mostra o que entraria');
    }

    public function test_execute_stores_only_the_title_link_source_time_and_volume(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds();

        $this->artisan('radar:collect --execute')->assertSuccessful();

        // 10 tendências + 22 (ECO) + 10 (Público) + 51 (Observador) + 1 decisão do BCE (15 fora do âmbito).
        $counts = collect(self::ENABLED)->mapWithKeys(fn ($k) => [$k => RadarItem::where('source_key', $k)->count()])->all();
        $this->assertSame(['google_trends' => 10, 'jornal_eco' => 22, 'jornal_publico' => 10, 'jornal_observador' => 51, 'bce_press' => 1], $counts);
        $this->assertSame(15, RadarRun::where('source_key', 'bce_press')->value('items_out_of_scope'));

        // Nenhuma coluna para o corpo, o resumo, o autor ou as imagens do artigo.
        $this->assertSame([
            'id', 'source_key', 'external_key', 'title', 'url', 'source_name', 'published_at', 'fetched_at', 'approx_volume',
            'approx_volume_label', 'extra', 'filter_verdict', 'filter_reasons', 'filter_flags', 'topic_id', 'expires_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('radar_items'));

        $eco = RadarItem::where('source_key', 'jornal_eco')->where('url', 'https://eco.sapo.pt/2026/10/12/margem-de-refinacao-da-galp-mais-que-triplica-e-atinge-recorde-trimestral/')->firstOrFail();
        $this->assertSame('Margem de refinação da Galp mais que triplica e atinge recorde trimestral', $eco->title);
        $this->assertSame('ECO', $eco->source_name);
        $this->assertSame('2026-10-12 16:09:19', $eco->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($eco->approx_volume);
        $this->assertSame(['Capital Verde', 'Empresas', 'Energia'], $eco->extra['categories']);
        $this->assertSame('2026-11-11', $eco->expires_at->format('Y-m-d'), 'apagado ao fim de 30 dias');

        $trend = RadarItem::where('source_key', 'google_trends')->where('title', 'navio')->firstOrFail();
        $this->assertSame([500, '500+', 'Google Trends'], [$trend->approx_volume, $trend->approx_volume_label, $trend->source_name]);

        // O corpo (content:encoded), o resumo (description) e o autor (dc:creator) nunca entram.
        $dump = json_encode([DB::table('radar_items')->get(), DB::table('radar_topics')->get(), DB::table('radar_runs')->get()], JSON_UNESCAPED_UNICODE);
        foreach (['CORPO-DO-ARTIGO', 'RESUMO-DO-ARTIGO', 'Joana Exemplo', 'gstatic.com'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }
    }

    public function test_collecting_again_does_not_duplicate_and_uses_the_conditional_request(): void
    {
        $this->robotsAlreadyRead();
        $trends = $this->fixture('google-trends/trending-rss-pt.xml');
        $this->fakeFeeds([
            'trends.google.com/trending/rss*' => Http::sequence()->push($trends, 200, ['ETag' => '"trends-1"'])->push($trends, 200, ['ETag' => '"trends-1"'])->push('', 304, ['ETag' => '"trends-1"']),
        ]);

        $collector = app(RadarCollector::class);
        $first = $collector->collect(null, true);
        $this->travel(16)->minutes();
        $second = $collector->collect(null, true);

        $this->assertSame(94, RadarItem::count());
        $this->assertSame([22, 0, 22], [$first['jornal_eco']['novos'], $second['jornal_eco']['novos'], $second['jornal_eco']['repetidos']]);
        $this->assertSame(10, $second['google_trends']['repetidos']);
        // A segunda recolha envia o ETag e o Last-Modified da primeira.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'trending/rss') && $r->hasHeader('If-None-Match', '"trends-1"'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'PublicoRSS') && $r->hasHeader('If-Modified-Since', 'Mon, 12 Oct 2026 17:56:14 GMT'));

        // 304: nada novo, recolha boa.
        $this->travel(16)->minutes();
        $third = $collector->collect('google_trends', true);
        $this->assertSame(['ok', 304, 0, 0], [$third['google_trends']['estado'], $third['google_trends']['http_status'], $third['google_trends']['lidos'], $third['google_trends']['novos']]);
        $this->assertSame(94, RadarItem::count());
    }

    public function test_the_fifth_request_in_the_same_hour_is_refused_and_force_passes(): void
    {
        $this->fakeFeeds();
        for ($i = 0; $i < 4; $i++) {
            $this->artisan('radar:collect jornal_eco')->assertSuccessful();
            $this->travel(5)->minutes();
        }
        $this->artisan('radar:collect jornal_eco')->expectsOutputToContain('recusado')->assertSuccessful();
        Http::assertSentCount(4);
        $this->assertSame(4, RadarRun::where('source_key', 'jornal_eco')->count(), 'a recusa não faz pedido nem conta');

        $this->artisan('radar:collect jornal_eco --force')->assertSuccessful();
        Http::assertSentCount(5);

        // Uma hora depois do primeiro pedido, volta a haver margem.
        $this->travel(45)->minutes();
        $this->assertSame('ok', app(RadarCollector::class)->collect('jornal_eco')['jornal_eco']['estado']);
    }

    public function test_failures_and_304_count_for_the_hourly_ceiling(): void
    {
        $this->fakeFeeds(['eco.sapo.pt/feed/*' => Http::response('Serviço indisponível', 503)]);
        $collector = app(RadarCollector::class);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('erro', $collector->collect('jornal_eco', true)['jornal_eco']['estado']);
        }
        $this->assertSame('recusado', $collector->collect('jornal_eco', true)['jornal_eco']['estado']);
        Http::assertSentCount(4);
    }

    public function test_a_failing_source_does_not_stop_the_others(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds(['eco.sapo.pt/feed/*' => Http::response('Serviço indisponível', 503)]);

        $results = app(RadarCollector::class)->collect(null, true);

        $this->assertSame(['erro', 'A fonte respondeu HTTP 503.'], [$results['jornal_eco']['estado'], $results['jornal_eco']['erro']]);
        $run = RadarRun::where('source_key', 'jornal_eco')->firstOrFail();
        $this->assertSame(['erro', 503, 1], [$run->status, $run->http_status, $run->requests]);
        $this->assertSame(0, RadarItem::where('source_key', 'jornal_eco')->count());
        $this->assertSame(10, RadarItem::where('source_key', 'jornal_publico')->count());
        $this->assertSame(51, RadarItem::where('source_key', 'jornal_observador')->count());
        $this->assertSame(10, RadarItem::where('source_key', 'google_trends')->count());
    }

    public function test_a_timeout_and_an_oversized_or_broken_response_are_errors(): void
    {
        $this->fakeFeeds([
            'eco.sapo.pt/feed/*' => Http::response(str_repeat('a', 2 * 1024 * 1024 + 1), 200),
            'feeds.feedburner.com/PublicoRSS*' => Http::response('<html>não é um feed</html>', 200),
            'observador.pt/feed/*' => Http::failedConnection('timeout'),
        ]);
        $collector = app(RadarCollector::class);

        $this->assertSame('A resposta tem mais de 2 MB.', $collector->collect('jornal_eco', true)['jornal_eco']['erro']);
        $this->assertSame('Formato inesperado: não é um feed RSS.', $collector->collect('jornal_publico', true)['jornal_publico']['erro']);
        $this->assertSame('Sem resposta da fonte (ligação recusada ou tempo esgotado).', $collector->collect('jornal_observador', true)['jornal_observador']['erro']);
        $this->assertSame(0, RadarItem::count());
        $this->assertSame(3, RadarRun::where('status', 'erro')->count());
    }

    public function test_the_trends_robots_txt_is_read_once_a_day_and_a_disallow_stops_the_source(): void
    {
        Cache::forget('radar:robots:google_trends');
        $this->fakeFeeds();
        $result = app(RadarCollector::class)->collect('google_trends', true)['google_trends'];
        $this->assertSame(['ok', 2, 10], [$result['estado'], $result['pedidos'], $result['novos']], 'o robots.txt e o feed');
        $this->assertSame('allowed', Cache::get('radar:robots:google_trends'));
        Http::assertSentCount(2);

        $this->travel(16)->minutes();
        $this->assertSame(1, app(RadarCollector::class)->collect('google_trends', true)['google_trends']['pedidos'], 'o robots.txt fica em cache 24 horas');
    }

    public function test_when_robots_txt_disallows_trending_the_feed_is_not_read(): void
    {
        Cache::forget('radar:robots:google_trends');
        $this->fakeFeeds(['trends.google.com/robots.txt' => Http::response($this->fixture('google-trends/robots-proibido.txt'), 200)]);

        $result = app(RadarCollector::class)->collect('google_trends', true)['google_trends'];

        $this->assertSame(['erro', 'robots.txt passou a proibir /trending'], [$result['estado'], $result['erro']]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'trending/rss'));
        $this->assertSame('robots.txt passou a proibir /trending', RadarRun::where('source_key', 'google_trends')->value('error'));
        $this->assertSame(0, RadarItem::count());

        // Com a proibição em cache (24 horas), a fonte nem chega a ser pedida.
        $this->travel(16)->minutes();
        $this->assertSame([0, 'robots.txt passou a proibir /trending'], array_values(array_intersect_key(
            app(RadarCollector::class)->collect('google_trends', true)['google_trends'], array_flip(['pedidos', 'erro']))));
        Http::assertSentCount(1);
    }

    public function test_the_disabled_ipma_source_is_only_read_when_named_with_force(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds();

        $this->assertSame('desligada', app(RadarCollector::class)->collect('ipma_avisos', true)['ipma_avisos']['estado']);
        $this->assertSame('desligada', app(RadarCollector::class)->collect(null, true)['ipma_avisos']['estado']);
        $this->assertSame(0, RadarItem::where('source_key', 'ipma_avisos')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ipma'));

        $this->artisan('radar:collect ipma_avisos --force --execute')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.ipma.pt'));
        $this->assertSame(4, RadarItem::where('source_key', 'ipma_avisos')->count());
    }

    public function test_a_recorded_sample_is_read_without_network_and_does_not_count(): void
    {
        $this->fakeFeeds();

        $this->artisan('radar:collect google_trends --execute --fixture=tests/Fixtures/radar/google-trends/trending-rss-pt.xml')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(10, RadarItem::where('source_key', 'google_trends')->count());
        $run = RadarRun::firstOrFail();
        $this->assertSame([true, 0], [$run->from_fixture, $run->requests]);
        $this->assertSame(0, app(RadarCollector::class)->requestsLastHour('google_trends'));

        $this->artisan('radar:collect --fixture=tests/Fixtures/radar/google-trends/trending-rss-pt.xml')->assertFailed();
        $this->artisan('radar:collect fonte_inventada')->assertFailed();
    }

    public function test_the_job_is_unique_and_the_task_is_external_every_fifteen_minutes(): void
    {
        $job = new CollectRadarFeedsJob();
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame([1, 120, 600], [$job->tries, $job->timeout, $job->uniqueFor]);
        $this->assertContains('radar-collect-feeds', config('scheduler.external_job_names'));

        $events = collect(app(Schedule::class)->events())->keyBy('description');
        $this->assertSame('*/15 * * * *', $events['radar-collect-feeds']->expression);
        $this->assertSame(['20 3 * * *', 'Europe/Lisbon'], [$events['radar-purge-items']->expression, $events['radar-purge-items']->timezone]);
        $this->assertNotContains('radar-purge-items', config('scheduler.external_job_names'), 'o apagamento é interno');
    }

    public function test_the_job_collects_and_respects_each_source_interval(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds();

        $first = app()->call([new CollectRadarFeedsJob(), 'handle']);
        $this->assertSame('ok', $first['jornal_eco']['estado']);
        $this->assertSame(94, RadarItem::count());

        $this->travel(5)->minutes();
        $again = app()->call([new CollectRadarFeedsJob(), 'handle']);
        $this->assertSame('adiada', $again['jornal_eco']['estado'], 'ainda não passaram 15 minutos');
        Http::assertSentCount(count(self::ENABLED));
    }
}
