<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use Carbon\CarbonImmutable;

/**
 * Radar: um item lido de uma fonte, só com o que se pode guardar (título, ligação, fonte, hora,
 * volume aproximado e o extra permitido). As categorias completas do feed servem ao filtro
 * (filterCategories) e não se guardam; no extra ficam só as curtas, sem nomes de pessoas.
 */
final class RadarItemDto
{
    /** @param array<string, mixed>|null $extra */
    public function __construct(
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $sourceName,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?int $approxVolume = null,
        public readonly ?array $extra = null,
        public readonly ?string $approxVolumeLabel = null,
        public readonly ?string $externalKey = null,
        public readonly array $filterCategories = [],
    ) {}

    /** A chave para não repetir: a da fonte, ou o sha1 da ligação (do título, sem ligação). */
    public function externalKey(): string
    {
        return $this->externalKey ?? sha1($this->url ?: $this->title);
    }

    /** Os títulos das notícias relacionadas (Google Trends): servem ao filtro e à relevância. */
    public function relatedTitles(): array
    {
        return array_values(array_filter(array_map(fn ($n) => is_array($n) ? ($n['title'] ?? null) : null, (array) ($this->extra['news'] ?? []))));
    }
}
