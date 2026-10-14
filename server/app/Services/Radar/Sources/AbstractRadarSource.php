<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use Carbon\CarbonImmutable;
use SimpleXMLElement;

/** Radar: o que as fontes têm em comum (a configuração, o pedido e a leitura segura do XML). */
abstract class AbstractRadarSource implements RadarSource
{
    /** @param array<string, mixed> $config */
    public function __construct(protected readonly string $key, protected readonly array $config, protected readonly RadarHttp $http) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->key);
    }

    public function url(): string
    {
        return (string) $this->config['url'];
    }

    public function type(): string
    {
        return (string) $this->config['type'];
    }

    public function attribution(): string
    {
        return (string) ($this->config['attribution'] ?? '');
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function intervalMinutes(): int
    {
        return max(1, (int) ($this->config['interval_minutes'] ?? 15));
    }

    public function config(): array
    {
        return $this->config;
    }

    public function fetch(array $conditionalHeaders = []): RadarHttpResponse
    {
        return $this->http->get($this->url(), $conditionalHeaders);
    }

    protected function sourceName(): string
    {
        return (string) ($this->config['source_name'] ?? $this->label());
    }

    /** XML sem entidades externas nem acesso à rede; um feed partido é um erro da recolha. */
    protected function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false || ! isset($xml->channel)) {
            throw new RadarFetchException('Formato inesperado: não é um feed RSS.');
        }

        return $xml;
    }

    /** A hora do feed em UTC (pubDate em RFC 822, com qualquer fuso). */
    protected function date(?string $value, string $timezone = 'UTC'): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
