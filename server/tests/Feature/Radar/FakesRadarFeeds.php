<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Radar (testes): as fontes respondem com as amostras gravadas e anonimizadas da noite
 * (tests/Fixtures/radar). Nada vai à rede: cada teste chama Http::preventStrayRequests().
 */
trait FakesRadarFeeds
{
    protected function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/radar/' . $path));
    }

    protected function fixturePath(string $path): string
    {
        return base_path('tests/Fixtures/radar/' . $path);
    }

    /** O robots.txt do Trends já lido hoje (em cache), para cada recolha fazer um só pedido por fonte. */
    protected function robotsAlreadyRead(): void
    {
        Cache::put('radar:robots:google_trends', 'allowed', 86400);
    }

    /** @param array<string, mixed> $override respostas que substituem as das amostras (pelo padrão do URL) */
    protected function fakeFeeds(array $override = []): void
    {
        Http::fake(array_merge([
            'trends.google.com/robots.txt' => Http::response($this->fixture('google-trends/robots.txt'), 200),
            'trends.google.com/trending/rss*' => Http::response($this->fixture('google-trends/trending-rss-pt.xml'), 200, ['Content-Type' => 'application/rss+xml', 'ETag' => '"trends-1"']),
            'eco.sapo.pt/feed/*' => Http::response($this->fixture('jornais/eco.xml'), 200, ['Content-Type' => 'application/rss+xml', 'ETag' => '"eco-1"']),
            'feeds.feedburner.com/PublicoRSS*' => Http::response($this->fixture('jornais/publico.xml'), 200, ['Content-Type' => 'text/xml', 'Last-Modified' => 'Mon, 12 Oct 2026 17:56:14 GMT']),
            'observador.pt/feed/*' => Http::response($this->fixture('jornais/observador.xml'), 200, ['Content-Type' => 'text/xml']),
            'www.ecb.europa.eu/rss/press.html*' => Http::response($this->fixture('bce/press.xml'), 200, ['Content-Type' => 'application/rss+xml']),
            'api.ipma.pt/open-data/forecast/warnings/warnings_www.json*' => Http::response($this->fixture('ipma/warnings_www.json'), 200, ['Content-Type' => 'application/json']),
        ], $override));
    }
}
