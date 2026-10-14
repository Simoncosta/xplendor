<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use App\Services\Radar\RadarText;

/**
 * Radar: os comunicados do Banco Central Europeu (RSS misto: comunicados, discursos e
 * entrevistas). Só entram as decisões de política monetária: o título começa por "Monetary
 * policy decisions" ou a ligação é a de um comunicado de decisão (".../press/pr/date/AAAA/html/
 * ecb.mp..."). Os outros contam como fora do âmbito e não se guardam.
 */
class EcbPressSource extends AbstractRadarSource
{
    public const DECISION_TITLE = 'monetary policy decisions';

    public const DECISION_LINK = '#/press/pr/date/\d{4}/html/ecb\.mp#';

    public function parse(string $body): RadarParseResult
    {
        $items = [];
        $out = 0;
        foreach ($this->xml($body)->channel->item as $item) {
            $title = RadarText::cleanTitle((string) $item->title);
            $url = self::normalizeUrl(trim((string) $item->link));
            if ($title === '' || ! self::isDecision($title, $url)) {
                $out++;
                continue;
            }
            $items[] = new RadarItemDto(
                title: $title,
                url: $url ?: null,
                sourceName: $this->sourceName(),
                publishedAt: $this->date((string) $item->pubDate),
            );
        }

        return new RadarParseResult($items, $out);
    }

    public static function isDecision(string $title, string $url): bool
    {
        return str_starts_with(mb_strtolower(trim($title)), self::DECISION_TITLE) || preg_match(self::DECISION_LINK, $url) === 1;
    }

    /** "https://www.ecb.europa.eu//press/..." → "https://www.ecb.europa.eu/press/...". */
    public static function normalizeUrl(string $url): string
    {
        return preg_replace('#^(https?://[^/]+)/{2,}#i', '$1/', $url) ?? $url;
    }
}
