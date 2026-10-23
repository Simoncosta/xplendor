<?php

declare(strict_types=1);

namespace App\Services\Radar\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Bússola 6: o leitor do calendário de difusão do INE (.ics). Do ficheiro só interessam as
 * publicações que o OfficialDates já segue (MATCHERS); cada data encontrada fica em
 * official_calendar_dates, com o dia da leitura, e o OfficialDates junta-a às datas do código.
 * A leitura real só acontece com o endereço configurado (INE_CALENDAR_URL) e com as chamadas externas
 * ligadas (ExternalCalls); nos testes, uma amostra gravada.
 */
final class IneReleaseCalendar
{
    /** A chave no OfficialDates => o título da publicação no calendário do INE. */
    public const MATCHERS = [
        'ine.precos_habitacao_local' => '/pre[çc]os\s+da\s+habita[çc][ãa]o\s+ao\s+n[íi]vel\s+local/iu',
        'ine.juro_credito_habitacao' => '/taxas\s+de\s+juro\s+impl[íi]citas\s+no\s+cr[ée]dito\s+[àa]\s+habita[çc][ãa]o/iu',
        'ine.avaliacao_bancaria' => '/avalia[çc][ãa]o\s+banc[áa]ria\s+na\s+habita[çc][ãa]o/iu',
    ];

    public static function url(): string
    {
        return trim((string) config('radar.ine_calendar.url', ''));
    }

    /**
     * As publicações seguidas que o ficheiro .ics anuncia.
     *
     * @return list<array{source_key: string, starts_on: string, title: string}>
     */
    public static function parse(string $ics): array
    {
        // As linhas longas continuam na seguinte, começada por um espaço ou um tab (RFC 5545).
        $lines = explode("\n", preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)) ?? '');
        $out = [];
        $event = null;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === 'BEGIN:VEVENT') {
                $event = [];
            } elseif ($line === 'END:VEVENT' && $event !== null) {
                $title = $event['summary'] ?? '';
                foreach (self::MATCHERS as $key => $re) {
                    if (isset($event['date']) && preg_match($re, $title)) {
                        $out[] = ['source_key' => $key, 'starts_on' => $event['date'], 'title' => mb_substr($title, 0, 255)];
                        break;
                    }
                }
                $event = null;
            } elseif ($event !== null && preg_match('/^DTSTART[^:]*:(\d{4})(\d{2})(\d{2})(T(\d{2})(\d{2})(\d{2})(Z?))?/', $line, $m)) {
                $day = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
                // Uma hora em UTC conta no dia de Lisboa.
                $event['date'] = ($m[8] ?? '') === 'Z'
                    ? CarbonImmutable::parse("{$day} {$m[5]}:{$m[6]}:{$m[7]}", 'UTC')->setTimezone('Europe/Lisbon')->toDateString()
                    : $day;
            } elseif ($event !== null && preg_match('/^SUMMARY[^:]*:(.*)$/', $line, $m)) {
                $event['summary'] = str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], trim($m[1]));
            }
        }
        $unique = [];
        foreach ($out as $r) {
            $unique["{$r['source_key']}@{$r['starts_on']}"] = $r;
        }

        return array_values($unique);
    }

    /** Grava (ou atualiza) as datas lidas. @return int quantas */
    public static function save(array $rows, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        if ($rows === []) {
            return 0;
        }
        DB::table('official_calendar_dates')->upsert(
            array_map(fn ($r) => ['source_key' => $r['source_key'], 'starts_on' => $r['starts_on'], 'ends_on' => null, 'title' => $r['title'],
                'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now], $rows),
            ['source_key', 'starts_on'], ['title', 'fetched_at', 'updated_at'],
        );

        return count($rows);
    }

    /** O ficheiro do INE, ou null (sem endereço, sem chamadas externas ou com erro). */
    public static function fetch(): ?string
    {
        $url = self::url();
        if ($url === '' || ! \App\Support\ExternalCalls::allowed()) {
            return null;
        }
        $res = Http::timeout((int) config('radar.timeout', 15))->connectTimeout((int) config('radar.connect_timeout', 5))
            ->withUserAgent((string) config('radar.user_agent'))->get($url);

        return $res->successful() ? $res->body() : null;
    }

    /**
     * As datas lidas por chave, para o OfficialDates: [key => ['dates' => [[dia]], 'fetched_on' => dia]].
     *
     * @return array<string, array{dates: list<array{0: string}>, fetched_on: string}>
     */
    public static function fetchedByKey(): array
    {
        try {
            $rows = DB::table('official_calendar_dates')->whereIn('source_key', array_keys(self::MATCHERS))->orderBy('starts_on')->get(['source_key', 'starts_on', 'fetched_at']);
        } catch (\Illuminate\Database\QueryException) {
            return []; // sem a tabela (antes da migração): só as datas do código
        }
        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->source_key;
            $out[$key]['dates'][] = [substr((string) $r->starts_on, 0, 10)];
            $fetched = substr((string) $r->fetched_at, 0, 10);
            $out[$key]['fetched_on'] = max($out[$key]['fetched_on'] ?? '', $fetched);
        }

        return $out;
    }
}
