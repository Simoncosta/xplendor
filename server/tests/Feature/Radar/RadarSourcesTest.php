<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Services\Radar\Sources\EcbPressSource;
use App\Services\Radar\Sources\GoogleTrendsSource;
use App\Services\Radar\Sources\IpmaWarningsSource;
use App\Services\Radar\Sources\NewspaperRssSource;
use App\Services\Radar\Sources\RadarFetchException;
use App\Services\Radar\Sources\RadarSources;
use App\Services\Radar\Sources\RobotsGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Radar (R1): o leitor de cada fonte, com as amostras gravadas e anonimizadas da noite. Só sai
 * o que se pode guardar: título, ligação, fonte, hora, volume e o extra permitido.
 */
class RadarSourcesTest extends TestCase
{
    use FakesRadarFeeds;

    protected function setUp(): void
    {
        parent::setUp();
        config(['radar.enabled' => true]); // RADAR_ENABLED (desligado por omissão)
        Http::preventStrayRequests();
    }

    public function test_the_configured_sources_and_their_readers(): void
    {
        $all = app(RadarSources::class)->all();
        $this->assertSame(['google_trends', 'jornal_eco', 'jornal_publico', 'jornal_observador', 'bce_press', 'ipma_avisos'], array_keys($all));
        $this->assertSame(['google_trends', 'jornal_eco', 'jornal_publico', 'jornal_observador', 'bce_press'], array_keys(app(RadarSources::class)->enabled()));
        $this->assertInstanceOf(GoogleTrendsSource::class, $all['google_trends']);
        $this->assertInstanceOf(NewspaperRssSource::class, $all['jornal_observador']);
        $this->assertInstanceOf(EcbPressSource::class, $all['bce_press']);
        $this->assertInstanceOf(IpmaWarningsSource::class, $all['ipma_avisos']);
        $this->assertSame('Fonte: Banco Central Europeu (informação disponível gratuitamente em ecb.europa.eu)', $all['bce_press']->attribution());
        $this->assertSame('Fonte: IPMA, Instituto Português do Mar e da Atmosfera (www.ipma.pt)', $all['ipma_avisos']->attribution());
        $this->assertSame(4, config('radar.max_requests_per_hour'));
        $this->assertSame(30, config('radar.retention_days'));
    }

    public function test_google_trends(): void
    {
        $items = app(RadarSources::class)->get('google_trends')->parse($this->fixture('google-trends/trending-rss-pt.xml'))->items;
        $this->assertCount(10, $items);
        $russia = $items[0];
        $this->assertSame(['rússia', 'Google Trends', 1000, '1000+'], [$russia->title, $russia->sourceName, $russia->approxVolume, $russia->approxVolumeLabel]);
        // pubDate com o fuso -0700 passa a UTC; a ligação é a da primeira notícia (o link do item é o próprio feed).
        $this->assertSame('2026-10-12 18:00:00', $russia->publishedAt->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('https://www.publico.pt/2026/10/12/mundo/noticia/', $russia->url);
        $this->assertSame(sha1('trends|rússia|Mon, 12 Oct 2026 11:00:00 -0700'), $russia->externalKey());
        $this->assertCount(3, $russia->extra['news']);
        $this->assertSame(['title', 'url', 'source'], array_keys($russia->extra['news'][0]));
        $this->assertSame('Público', $russia->extra['news'][0]['source']);
        $this->assertCount(3, $russia->relatedTitles());
        $this->assertStringNotContainsString('gstatic', json_encode($russia->extra));
        $this->assertSame([100, 2000, 1000000, null], [GoogleTrendsSource::volume('100+'), GoogleTrendsSource::volume('2K+'), GoogleTrendsSource::volume('1M+'), GoogleTrendsSource::volume('')]);
    }

    public function test_newspapers_keep_only_title_link_time_and_short_categories(): void
    {
        $sources = app(RadarSources::class);
        $eco = $sources->get('jornal_eco')->parse($this->fixture('jornais/eco.xml'))->items;
        $this->assertCount(22, $eco);
        $this->assertSame('Municípios europeus temem perder o “comboio” da competitividade com novo orçamento', $eco[0]->title);
        $this->assertSame(['Local Online', 'Política', 'Regiões', 'União Europeia'], $eco[0]->extra['categories']);

        $publico = $sources->get('jornal_publico')->parse($this->fixture('jornais/publico.xml'))->items;
        $this->assertCount(10, $publico);
        // A marcação HTML do título sai; a hora em GMT fica em UTC.
        $this->assertSame('Dopamine Chamber: os Fontaines D.C. sobre os escombros do presente, indecisos na sua nova pele', $publico[0]->title);
        $this->assertSame('2026-10-12 17:51:49', $publico[0]->publishedAt->format('Y-m-d H:i:s'));

        $observador = $sources->get('jornal_observador')->parse($this->fixture('jornais/observador.xml'))->items;
        $this->assertCount(51, $observador);
        $this->assertSame('2026-10-12 18:13:04', $observador[0]->publishedAt->format('Y-m-d H:i:s'), '+0100 em UTC');
        // As categorias que são nomes de pessoas não se guardam (mas o filtro vê-as todas).
        $faltam = collect($observador)->firstWhere('title', 'Faltam reformas estruturais ao país, como diz Quaresma Leal?');
        $this->assertContains('Henrique Quaresma Leal', $faltam->filterCategories);
        $this->assertNotContains('Henrique Quaresma Leal', $faltam->extra['categories']);
        $this->assertNotContains('Afonso Ventosa', $faltam->extra['categories']);
        $this->assertLessThanOrEqual(NewspaperRssSource::MAX_CATEGORIES, count($faltam->extra['categories']));

        $all = json_encode(array_map(fn ($i) => (array) $i, array_merge($eco, $publico, $observador)), JSON_UNESCAPED_UNICODE);
        foreach (['CORPO-DO-ARTIGO', 'RESUMO-DO-ARTIGO', 'Joana Exemplo', 'bordalo.observador.pt'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all);
        }
    }

    public function test_the_ecb_keeps_only_monetary_policy_decisions(): void
    {
        $result = app(RadarSources::class)->get('bce_press')->parse($this->fixture('bce/press.xml'));
        $this->assertCount(1, $result->items);
        $this->assertSame(15, $result->outOfScope);
        $decision = $result->items[0];
        $this->assertSame('Monetary policy decisions', $decision->title, 'sem o espaço inicial');
        $this->assertSame('https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.mp261029~5f1c0a7e2b.en.html', $decision->url, 'sem a barra dupla');
        $this->assertSame('Banco Central Europeu', $decision->sourceName);
        $this->assertSame('2026-10-29 13:15:00', $decision->publishedAt->format('Y-m-d H:i:s'));

        // Pela ligação de um comunicado de decisão, mesmo com outro título; e um comunicado qualquer fica de fora.
        $this->assertTrue(EcbPressSource::isDecision('Press release', 'https://www.ecb.europa.eu/press/pr/date/2027/html/ecb.mp270204~aa.en.html'));
        $this->assertFalse(EcbPressSource::isDecision('ECB amends monetary policy implementation guidelines', 'https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.pr260929~050089e922.en.html'));
    }

    public function test_ipma_warnings(): void
    {
        $items = app(RadarSources::class)->get('ipma_avisos')->parse($this->fixture('ipma/warnings_www.json'))->items;
        $this->assertCount(4, $items, 'só os amarelos, laranja e vermelhos');
        $porto = collect($items)->firstWhere('extra.area', 'PTO');
        $this->assertSame('Aviso laranja de Agitação Marítima no distrito do Porto', $porto->title);
        $this->assertSame('https://www.ipma.pt/pt/otempo/prev-sam/', $porto->url);
        $this->assertSame(['area' => 'PTO', 'district' => 'Porto', 'level' => 'orange', 'type' => 'Agitação Marítima', 'start' => '2026-10-13T00:00:00Z',
            'end' => '2026-10-14T06:00:00Z', 'text' => 'Ondas de noroeste com 5 a 6 metros.'], $porto->extra);
        $this->assertSame(sha1('ipma|PTO|Agitação Marítima|orange|2026-10-13T00:00:00'), $porto->externalKey());
        $this->assertSame('Aviso vermelho de Precipitação no distrito de Faro', collect($items)->firstWhere('extra.area', 'FAR')->title);
        $this->assertSame('Aviso amarelo de Vento na Madeira (costa sul)', 'Aviso amarelo de Vento ' . IpmaWarningsSource::where('MCS'));
        $this->assertSame('nos Açores (grupo central)', IpmaWarningsSource::where('ACE'));

        // A tabela em código cobre todas as áreas da lista oficial do IPMA.
        $areas = collect(json_decode($this->fixture('ipma/distrits-islands.json'), true)['data'])->pluck('idAreaAviso')->unique()->sort()->values()->all();
        $this->assertSame([], array_values(array_diff($areas, array_keys(IpmaWarningsSource::AREAS))));

        $this->expectException(RadarFetchException::class);
        app(RadarSources::class)->get('ipma_avisos')->parse('{"nao": "lista"}');
    }

    public function test_robots_txt_rules(): void
    {
        $this->assertFalse(RobotsGuard::disallows($this->fixture('google-trends/robots.txt'), ['/trending', '/trending/rss']));
        $this->assertTrue(RobotsGuard::disallows($this->fixture('google-trends/robots-proibido.txt'), ['/trending', '/trending/rss']));
        $this->assertTrue(RobotsGuard::disallows("User-agent: *\nDisallow: /", ['/trending/rss']));
        $this->assertFalse(RobotsGuard::disallows("User-agent: Googlebot\nDisallow: /trending\n\nUser-agent: *\nDisallow:", ['/trending/rss']));
    }
}
