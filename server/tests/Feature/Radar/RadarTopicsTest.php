<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Models\RadarItem;
use App\Models\RadarTopic;
use App\Services\Radar\RadarCollector;
use App\Services\Radar\RadarTopics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Radar (R1): as tendências. Dois jornais com o mesmo assunto em 6 horas formam uma; um só
 * não; o Google Trends forma uma por consulta, com o volume; a janela de cada tipo (DESENHO 6.4).
 */
class RadarTopicsTest extends TestCase
{
    use FakesRadarFeeds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['radar.enabled' => true]); // RADAR_ENABLED (desligado por omissão)
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 18:20:00'));
    }

    private function item(string $source, string $title, string $publishedAt, array $over = []): RadarItem
    {
        $item = RadarItem::create(array_merge([
            'source_key' => $source, 'external_key' => sha1($source . $title), 'title' => $title, 'url' => 'https://exemplo.pt/' . md5($title),
            'source_name' => (string) config("radar.sources.{$source}.source_name"), 'published_at' => CarbonImmutable::parse($publishedAt),
            'fetched_at' => now(), 'filter_verdict' => 'passed', 'expires_at' => now()->addDays(30),
        ], $over));
        app(RadarTopics::class)->assign($item);

        return $item->fresh();
    }

    public function test_two_newspapers_on_the_same_subject_within_six_hours_form_a_topic(): void
    {
        $eco = $this->item('jornal_eco', 'Margem de refinação da Galp mais que triplica e atinge recorde trimestral', '2026-10-12 16:09:19');
        $this->assertNull($eco->topic_id, 'um título sozinho não é tendência');

        $obs = $this->item('jornal_observador', 'Margem de refinação da Galp duplicou num trimestre', '2026-10-12 16:47:04');

        $topic = RadarTopic::firstOrFail();
        $this->assertSame([$topic->id, $topic->id], [$eco->fresh()->topic_id, $obs->topic_id]);
        $this->assertSame('news', $topic->kind);
        $this->assertSame('Margem de refinação da Galp mais que triplica e atinge recorde trimestral', $topic->title, 'o primeiro título');
        $this->assertSame('news:margem refinacao', $topic->normalized_key);
        $this->assertSame(['ECO', 'Observador'], array_column($topic->sources, 'source_name'));
        $this->assertSame('2026-10-15 16:09:19', $topic->window_until->format('Y-m-d H:i:s'), '72 horas depois do primeiro título');
        $this->assertSame('passed', $topic->filter_verdict);

        // Um terceiro jornal junta-se à mesma tendência.
        $pub = $this->item('jornal_publico', 'Galp: margem de refinação em máximos', '2026-10-12 17:30:00');
        $this->assertSame($topic->id, $pub->topic_id);
        $this->assertCount(3, $topic->fresh()->sources);
    }

    public function test_one_newspaper_alone_or_too_far_apart_does_not_form_a_topic(): void
    {
        $this->item('jornal_eco', 'Margem de refinação da Galp mais que triplica', '2026-10-12 08:00:00');
        // O mesmo jornal não conta como segunda fonte.
        $this->item('jornal_eco', 'Margem de refinação da Galp em máximos', '2026-10-12 09:00:00');
        // Outro jornal, mas 7 horas depois.
        $this->item('jornal_observador', 'Margem de refinação da Galp duplicou', '2026-10-12 15:30:00');
        // Outro jornal, a tempo, mas só com uma palavra significativa em comum.
        $this->item('jornal_publico', 'Refinação de Sines abre posto de carregamento', '2026-10-12 09:30:00');

        $this->assertSame(0, RadarTopic::count());
        $this->assertSame(0, RadarItem::whereNotNull('topic_id')->count());
    }

    public function test_a_blocked_item_blocks_the_topic(): void
    {
        $this->item('jornal_eco', 'Costureira alerta para a falta de habitação acessível nas cidades', '2026-10-12 16:03:00');
        $this->item('jornal_observador', 'Costureira alerta para a importância das cidades', '2026-10-12 16:19:00', [
            'filter_verdict' => 'blocked', 'filter_reasons' => ['politica'],
        ]);
        $topic = RadarTopic::firstOrFail();
        $this->assertSame(['blocked', ['politica']], [$topic->filter_verdict, $topic->filter_reasons]);
    }

    public function test_the_nights_newspapers_form_topics_across_sources(): void
    {
        $collector = app(RadarCollector::class);
        foreach (['jornal_eco' => 'eco', 'jornal_publico' => 'publico', 'jornal_observador' => 'observador'] as $key => $file) {
            $collector->collect($key, true, ['fixture' => $this->fixturePath("jornais/{$file}.xml")]);
        }

        $titles = RadarTopic::where('kind', 'news')->pluck('normalized_key')->all();
        $this->assertContains('news:margem refinacao', $titles, 'Galp no ECO e no Observador');
        $this->assertContains('news:quiosques volta', $titles, 'quiosques no ECO e no Público');
        $this->assertContains('news:frigorificos supermercados', $titles, 'frigoríficos no ECO e no Observador');
        $galp = RadarTopic::where('normalized_key', 'news:margem refinacao')->firstOrFail();
        $this->assertSame('passed', $galp->filter_verdict);
        // Os títulos sem par ficam como itens, sem tendência.
        $this->assertGreaterThan(50, RadarItem::whereNull('topic_id')->count());
    }

    public function test_google_trends_forms_one_topic_per_query_with_the_volume(): void
    {
        app(RadarCollector::class)->collect('google_trends', true, ['fixture' => $this->fixturePath('google-trends/trending-rss-pt.xml')]);

        $this->assertSame(10, RadarTopic::where('kind', 'trend')->count());
        $navio = RadarTopic::where('title', 'navio')->firstOrFail();
        $this->assertSame([500, '500+', 'passed'], [$navio->approx_volume, $navio->approx_volume_label, $navio->filter_verdict]);
        $this->assertSame('2026-10-14 18:20:00', $navio->window_until->format('Y-m-d H:i:s'), '48 horas desde que foi vista');
        $this->assertSame('Google Trends', $navio->sources[0]['source_name']);
        $this->assertSame(['Google Trends', 'JM Madeira', 'SAPO', 'dnoticias.pt'], array_column($navio->sources, 'source_name'));
        $russia = RadarTopic::where('title', 'rússia')->firstOrFail();
        $this->assertSame(['blocked', ['conflito'], 1000], [$russia->filter_verdict, $russia->filter_reasons, $russia->approx_volume]);

        // A mesma consulta numa recolha seguinte (outra hora no feed) junta-se à tendência aberta.
        $this->travel(30)->minutes();
        $again = str_replace('Mon, 12 Oct 2026 09:40:00 -0700</pubDate>', 'Mon, 12 Oct 2026 11:10:00 -0700</pubDate>', $this->fixture('google-trends/trending-rss-pt.xml'));
        $file = tempnam(sys_get_temp_dir(), 'radar');
        file_put_contents($file, $again);
        app(RadarCollector::class)->collect('google_trends', true, ['fixture' => $file]);
        unlink($file);
        $this->assertSame(10, RadarTopic::where('kind', 'trend')->count());
        $this->assertSame(2, RadarItem::where('title', 'navio')->count());
        $this->assertSame(1, RadarTopic::where('title', 'navio')->count());
    }

    public function test_the_window_of_each_kind(): void
    {
        // BCE: até às 23:59 do próprio dia, em Lisboa (verão: UTC+1; inverno: UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-29 13:20:00'));
        app(RadarCollector::class)->collect('bce_press', true, ['fixture' => $this->fixturePath('bce/press.xml')]);
        $bce = RadarTopic::where('kind', 'bce')->firstOrFail();
        $this->assertSame('Decisões de política monetária do BCE', $bce->title);
        $this->assertSame('2026-10-29 23:59:59', $bce->window_until->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-10 22:59:59', RadarTopics::endOfLisbonDay(CarbonImmutable::parse('2026-07-10 12:15:00'))->format('Y-m-d H:i:s'));

        // IPMA: até ao fim do aviso; um por área e nível (dois tipos no mesmo distrito juntam-se).
        $this->travelTo(CarbonImmutable::parse('2026-10-13 05:00:00'));
        app(RadarCollector::class)->collect('ipma_avisos', true, ['force' => true, 'fixture' => $this->fixturePath('ipma/warnings_www.json')]);
        $this->assertSame(3, RadarTopic::where('kind', 'weather')->count());
        $lisboa = RadarTopic::where('normalized_key', 'weather:LSB:yellow')->firstOrFail();
        $this->assertSame('Aviso amarelo de Precipitação e Vento no distrito de Lisboa', $lisboa->title);
        $this->assertSame(['Lisboa'], $lisboa->districts);
        $this->assertSame('2026-10-13 21:00:00', $lisboa->window_until->format('Y-m-d H:i:s'), 'o fim do aviso mais longo');
        $porto = RadarTopic::where('normalized_key', 'weather:PTO:orange')->firstOrFail();
        $this->assertSame(['Aviso laranja de Agitação Marítima no distrito do Porto', '2026-10-14 06:00:00'], [$porto->title, $porto->window_until->format('Y-m-d H:i:s')]);

        // Notícias: 72 horas; Trends: 48 horas (acima).
        $this->assertSame(72, RadarTopics::NEWS_HOURS);
        $this->assertSame(48, RadarTopics::TREND_HOURS);
    }
}
