<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\RadarItem;
use App\Models\RadarTopic;
use App\Services\Radar\Sources\IpmaWarningsSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Radar: a tendência de cada item (DESENHO.md, secções 4.1 e 6.4).
 *  · Google Trends: uma tendência por consulta (a mesma consulta, enquanto a janela está
 *    aberta, junta-se à que já existe); vale 48 horas desde que foi vista pela primeira vez.
 *  · Jornais: dois ou mais jornais diferentes com 2 ou mais palavras significativas em comum
 *    no título, em 6 horas; vale 72 horas desde o primeiro título. Um título sem par não é
 *    tendência (fica como item, "sem tendência" no painel do root).
 *  · IPMA: uma por área e nível; vale até ao fim do aviso.
 *  · BCE: uma por comunicado de decisão; vale até às 23:59 do próprio dia, em Lisboa.
 * A tendência herda o veredicto do filtro: um item bloqueado bloqueia a tendência.
 */
class RadarTopics
{
    public const TREND_HOURS = 48;

    public const NEWS_HOURS = 72;

    public const NEWS_PAIR_HOURS = 6;

    public const NEWS_MIN_SHARED = 2;

    public const MAX_SOURCES = 12;

    public function assign(RadarItem $item): ?RadarTopic
    {
        return match ((string) config("radar.sources.{$item->source_key}.type")) {
            'trends' => $this->trend($item),
            'news' => $this->news($item),
            'bce' => $this->bce($item),
            'ipma' => $this->weather($item),
            default => null,
        };
    }

    private function trend(RadarItem $item): RadarTopic
    {
        $key = 'trend:' . mb_substr(RadarText::normalize($item->title), 0, 180);
        $topic = RadarTopic::where('kind', 'trend')->where('normalized_key', $key)->where('window_until', '>', now())->orderByDesc('id')->first();
        if ($topic === null) {
            $first = CarbonImmutable::parse($item->fetched_at);
            $topic = new RadarTopic([
                'kind' => 'trend', 'title' => $item->title, 'normalized_key' => $key, 'first_seen_at' => $first, 'last_seen_at' => $first,
                'window_until' => $first->addHours(self::TREND_HOURS), 'filter_verdict' => RadarItem::VERDICT_PASSED,
            ]);
        }
        if ($item->approx_volume !== null && $item->approx_volume >= (int) $topic->approx_volume) {
            $topic->approx_volume = $item->approx_volume;
            $topic->approx_volume_label = $item->approx_volume_label;
        }

        return $this->attach($topic, $item);
    }

    private function news(RadarItem $item): ?RadarTopic
    {
        $words = RadarText::significantWords($item->title);
        if (count($words) < self::NEWS_MIN_SHARED) {
            return null;
        }
        $at = CarbonImmutable::parse($item->published_at ?? $item->fetched_at);
        $newsKeys = array_keys(array_filter((array) config('radar.sources', []), fn ($s) => ($s['type'] ?? null) === 'news'));
        $candidates = RadarItem::query()
            ->whereIn('source_key', $newsKeys)
            ->where('source_key', '!=', $item->source_key)
            ->whereKeyNot($item->id)
            ->whereRaw('COALESCE(published_at, fetched_at) BETWEEN ? AND ?', [
                $at->subHours(self::NEWS_PAIR_HOURS)->format('Y-m-d H:i:s'), $at->addHours(self::NEWS_PAIR_HOURS)->format('Y-m-d H:i:s'),
            ])
            ->orderBy('id')
            ->get(['id', 'title', 'topic_id', 'source_key', 'published_at', 'fetched_at']);

        $best = null;
        $bestShared = [];
        foreach ($candidates as $candidate) {
            $shared = array_values(array_intersect($words, RadarText::significantWords($candidate->title)));
            if (count($shared) < self::NEWS_MIN_SHARED) {
                continue;
            }
            $better = $best === null || count($shared) > count($bestShared)
                || (count($shared) === count($bestShared) && $candidate->topic_id !== null && $best->topic_id === null);
            if ($better) {
                $best = $candidate;
                $bestShared = $shared;
            }
        }
        if ($best === null) {
            return null;
        }

        $topic = $best->topic_id ? RadarTopic::where('kind', 'news')->find($best->topic_id) : null;
        if ($topic === null) {
            sort($bestShared);
            $other = RadarItem::findOrFail($best->id);
            $otherAt = CarbonImmutable::parse($other->published_at ?? $other->fetched_at);
            $firstItem = $otherAt->lessThanOrEqualTo($at) ? $other : $item;
            $first = $otherAt->lessThanOrEqualTo($at) ? $otherAt : $at;
            $topic = new RadarTopic([
                'kind' => 'news', 'title' => $firstItem->title, 'normalized_key' => mb_substr('news:' . implode(' ', $bestShared), 0, 191),
                'first_seen_at' => $first, 'last_seen_at' => CarbonImmutable::parse($other->fetched_at),
                'window_until' => $first->addHours(self::NEWS_HOURS), 'filter_verdict' => RadarItem::VERDICT_PASSED,
            ]);
            $topic = $this->attach($topic, $other);
        }
        if ($at->lessThan($topic->first_seen_at)) {
            $topic->first_seen_at = $at;
            $topic->title = $item->title;
            $topic->window_until = $at->addHours(self::NEWS_HOURS);
        }

        return $this->attach($topic, $item);
    }

    private function bce(RadarItem $item): RadarTopic
    {
        $at = CarbonImmutable::parse($item->published_at ?? $item->fetched_at);
        $topic = RadarTopic::where('kind', 'bce')->where('normalized_key', 'bce:' . $item->external_key)->first() ?? new RadarTopic([
            'kind' => 'bce', 'title' => 'Decisões de política monetária do BCE', 'normalized_key' => 'bce:' . $item->external_key,
            'first_seen_at' => $at, 'last_seen_at' => $at, 'window_until' => self::endOfLisbonDay($at), 'filter_verdict' => RadarItem::VERDICT_PASSED,
        ]);

        return $this->attach($topic, $item);
    }

    private function weather(RadarItem $item): RadarTopic
    {
        $extra = (array) $item->extra;
        $area = (string) ($extra['area'] ?? '');
        $level = (string) ($extra['level'] ?? '');
        $start = CarbonImmutable::parse($extra['start'] ?? $item->published_at ?? $item->fetched_at);
        $end = isset($extra['end']) ? CarbonImmutable::parse($extra['end']) : $start->endOfDay();
        $key = "weather:{$area}:{$level}";
        $topic = RadarTopic::where('kind', 'weather')->where('normalized_key', $key)->where('window_until', '>=', $start)->orderByDesc('id')->first();
        if ($topic === null) {
            $topic = new RadarTopic([
                'kind' => 'weather', 'title' => $item->title, 'normalized_key' => $key, 'first_seen_at' => $start, 'last_seen_at' => $start,
                'window_until' => $end, 'districts' => array_values(array_filter([$extra['district'] ?? null])),
                'filter_verdict' => RadarItem::VERDICT_PASSED,
            ]);
        }
        if ($end->greaterThan($topic->window_until)) {
            $topic->window_until = $end;
        }
        $topic = $this->attach($topic, $item);

        // Vários tipos no mesmo distrito e nível: "Aviso amarelo de Precipitação e Vento no distrito de Lisboa".
        $types = $topic->items()->get(['extra'])->map(fn ($i) => $i->extra['type'] ?? null)->filter()->unique()->values()->all();
        if (count($types) > 1) {
            $last = array_pop($types);
            $topic->title = sprintf('Aviso %s de %s e %s %s', IpmaWarningsSource::LEVELS[$level] ?? $level, implode(', ', $types), $last, IpmaWarningsSource::where($area));
            $topic->save();
        }

        return $topic;
    }

    /** Junta o item à tendência: as fontes, a última vez vista, o veredicto, os motivos e as marcas. */
    private function attach(RadarTopic $topic, RadarItem $item): RadarTopic
    {
        $sources = (array) ($topic->sources ?? []);
        foreach (self::sourcesOf($item) as $source) {
            $dup = collect($sources)->contains(fn ($s) => ($s['url'] ?? null) === $source['url'] && ($s['source_name'] ?? null) === $source['source_name'] && ($s['title'] ?? null) === $source['title']);
            if (! $dup && count($sources) < self::MAX_SOURCES) {
                $sources[] = $source;
            }
        }
        $topic->sources = $sources;
        $seen = CarbonImmutable::parse($item->fetched_at);
        if ($topic->last_seen_at === null || $seen->greaterThan($topic->last_seen_at)) {
            $topic->last_seen_at = $seen;
        }
        $topic->expires_at = CarbonImmutable::parse($topic->last_seen_at)->addDays((int) config('radar.retention_days', 30));
        if ($item->filter_verdict === RadarItem::VERDICT_BLOCKED) {
            $topic->filter_verdict = RadarItem::VERDICT_BLOCKED;
        }
        $topic->filter_reasons = array_values(array_unique(array_merge((array) $topic->filter_reasons, (array) $item->filter_reasons)));
        $topic->filter_flags = array_values(array_unique(array_merge((array) $topic->filter_flags, (array) $item->filter_flags)));
        $topic->save();

        $item->topic_id = $topic->id;
        $item->save();

        return $topic;
    }

    /**
     * As fontes do item para a tendência (o cartão mostra o nome, a hora e a ligação). Num item
     * do Trends: o próprio Trends e as notícias relacionadas que o feed traz.
     *
     * @return array<int, array{title: ?string, source_name: string, url: ?string, published_at: ?string}>
     */
    public static function sourcesOf(RadarItem $item): array
    {
        $at = $item->published_at ? CarbonImmutable::parse($item->published_at)->toIso8601ZuluString() : null;
        $out = [['title' => $item->title, 'source_name' => $item->source_name, 'url' => $item->url, 'published_at' => $at]];
        foreach ((array) ($item->extra['news'] ?? []) as $news) {
            if (is_array($news) && ! empty($news['url'])) {
                $out[] = ['title' => $news['title'] ?? null, 'source_name' => (string) ($news['source'] ?? ''), 'url' => $news['url'], 'published_at' => null];
            }
        }

        return $out;
    }

    /** 23:59:59 do dia (em Lisboa) do momento dado, em UTC. */
    public static function endOfLisbonDay(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone('Europe/Lisbon')->endOfDay()->utc();
    }
}
