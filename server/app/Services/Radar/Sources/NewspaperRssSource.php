<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use App\Services\Radar\Filter\PersonNameDetector;
use App\Services\Radar\RadarText;

/**
 * Radar: o feed RSS de um jornal (ECO, Público, Observador). Lê só o título, a ligação, a hora
 * e as categorias. O resto do item (content:encoded com o artigo inteiro, description, media,
 * dc:creator) nunca é lido nem guardado.
 */
class NewspaperRssSource extends AbstractRadarSource
{
    /** Categorias curtas guardadas por item (servem à relevância no R2). */
    public const MAX_CATEGORIES = 8;

    public const MAX_CATEGORY_LENGTH = 40;

    public function parse(string $body): RadarParseResult
    {
        $items = [];
        foreach ($this->xml($body)->channel->item as $item) {
            $title = RadarText::cleanTitle((string) $item->title);
            if ($title === '') {
                continue;
            }
            $categories = [];
            foreach ($item->category as $category) {
                $categories[] = RadarText::cleanTitle((string) $category);
            }
            $categories = array_values(array_unique(array_filter($categories)));
            $short = self::shortCategories($categories);
            $items[] = new RadarItemDto(
                title: $title,
                url: trim((string) $item->link) ?: null,
                sourceName: $this->sourceName(),
                publishedAt: $this->date((string) $item->pubDate),
                extra: $short === [] ? null : ['categories' => $short],
                filterCategories: $categories,
            );
        }

        return new RadarParseResult($items);
    }

    /** Só os nomes curtos das categorias, sem as que são nomes de pessoas ("Cristiano Ronaldo"). */
    public static function shortCategories(array $categories): array
    {
        $out = array_filter($categories, fn (string $c) => mb_strlen($c) <= self::MAX_CATEGORY_LENGTH && ! PersonNameDetector::isNameLabel($c));

        return array_slice(array_values($out), 0, self::MAX_CATEGORIES);
    }
}
