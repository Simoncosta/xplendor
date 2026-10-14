<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

/** Radar: a resposta de uma fonte (o corpo só vive em memória até à leitura). */
final class RadarHttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
    ) {}

    public function notModified(): bool
    {
        return $this->status === 304;
    }
}
