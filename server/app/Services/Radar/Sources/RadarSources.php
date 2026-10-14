<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use InvalidArgumentException;

/** Radar: as fontes de config/radar.php, cada uma com o seu leitor pelo tipo. */
class RadarSources
{
    private const TYPES = [
        'trends' => GoogleTrendsSource::class,
        'news' => NewspaperRssSource::class,
        'bce' => EcbPressSource::class,
        'ipma' => IpmaWarningsSource::class,
    ];

    public function __construct(private readonly RadarHttp $http) {}

    /** @return array<string, RadarSource> todas, também as desligadas */
    public function all(): array
    {
        $out = [];
        foreach ((array) config('radar.sources', []) as $key => $config) {
            $out[$key] = $this->make((string) $key, (array) $config);
        }

        return $out;
    }

    /** @return array<string, RadarSource> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (RadarSource $s) => $s->enabled());
    }

    public function get(string $key): RadarSource
    {
        $config = config("radar.sources.{$key}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Fonte desconhecida: {$key}.");
        }

        return $this->make($key, $config);
    }

    public function has(string $key): bool
    {
        return is_array(config("radar.sources.{$key}"));
    }

    private function make(string $key, array $config): RadarSource
    {
        $class = self::TYPES[$config['type'] ?? ''] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Tipo de fonte desconhecido em {$key}.");
        }

        return new $class($key, $config, $this->http);
    }
}
