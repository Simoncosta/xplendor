<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use App\Services\Radar\RadarText;

/**
 * Radar: as tendências de pesquisa do Google Trends em Portugal (RSS 2.0 com o espaço de nomes
 * "ht"). O título é a consulta; o volume vem em escalões ("100+", "500+"); o link do item aponta
 * para o próprio feed, por isso a ligação é a da primeira notícia relacionada. Das notícias
 * guardam-se até 3 (título, ligação e fonte), sem imagens.
 */
class GoogleTrendsSource extends AbstractRadarSource
{
    public const HT_NAMESPACE = 'https://trends.google.com/trending/rss';

    public const MAX_NEWS = 3;

    public function parse(string $body): RadarParseResult
    {
        $xml = $this->xml($body);
        $ht = $xml->getDocNamespaces(true)['ht'] ?? self::HT_NAMESPACE;
        $items = [];
        foreach ($xml->channel->item as $item) {
            $title = RadarText::cleanTitle((string) $item->title);
            if ($title === '') {
                continue;
            }
            $h = $item->children($ht);
            $traffic = trim((string) $h->approx_traffic);
            $news = [];
            foreach ($h->news_item as $n) {
                if (count($news) >= self::MAX_NEWS) {
                    break;
                }
                $news[] = [
                    'title' => RadarText::cleanTitle((string) $n->news_item_title),
                    'url' => trim((string) $n->news_item_url) ?: null,
                    'source' => RadarText::cleanTitle((string) $n->news_item_source),
                ];
            }
            $pubDate = trim((string) $item->pubDate);
            $items[] = new RadarItemDto(
                title: $title,
                url: $news[0]['url'] ?? null,
                sourceName: $this->sourceName(),
                publishedAt: $this->date($pubDate),
                approxVolume: self::volume($traffic),
                extra: ['approx_traffic' => $traffic !== '' ? $traffic : null, 'news' => $news],
                approxVolumeLabel: $traffic !== '' ? mb_substr($traffic, 0, 20) : null,
                externalKey: sha1("trends|{$title}|{$pubDate}"),
            );
        }

        return new RadarParseResult($items);
    }

    /** "500+" → 500; "2K+" → 2000; "1M+" → 1000000; sem número, null. */
    public static function volume(string $traffic): ?int
    {
        if (! preg_match('/([\d.,]+)\s*([kKmM])?/', $traffic, $m)) {
            return null;
        }
        $n = (float) str_replace([',', '.'], '', $m[1]);
        $mult = match (strtolower($m[2] ?? '')) {
            'k' => 1000,
            'm' => 1000000,
            default => 1,
        };

        return (int) min($n * $mult, 4294967295);
    }
}
