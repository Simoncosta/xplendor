<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use Carbon\CarbonImmutable;

/**
 * Radar: os avisos meteorológicos do IPMA (JSON: 25 áreas × 8 tipos). Só os avisos amarelos,
 * laranja e vermelhos; o verde não é aviso. Desligada em config/radar.php até à confirmação
 * escrita do IPMA (termos de 2017), mas o leitor tem testes com a amostra gravada.
 */
class IpmaWarningsSource extends AbstractRadarSource
{
    public const LEVELS = ['yellow' => 'amarelo', 'orange' => 'laranja', 'red' => 'vermelho'];

    /** As áreas de aviso do IPMA (idAreaAviso). */
    public const AREAS = [
        'AVR' => 'Aveiro', 'BJA' => 'Beja', 'BRG' => 'Braga', 'BGC' => 'Bragança', 'CBO' => 'Castelo Branco',
        'CBR' => 'Coimbra', 'EVR' => 'Évora', 'FAR' => 'Faro', 'GDA' => 'Guarda', 'LRA' => 'Leiria', 'LSB' => 'Lisboa',
        'PTG' => 'Portalegre', 'PTO' => 'Porto', 'STM' => 'Santarém', 'STB' => 'Setúbal', 'VCT' => 'Viana do Castelo',
        'VRL' => 'Vila Real', 'VIS' => 'Viseu', 'MCS' => 'Madeira (costa sul)', 'MCN' => 'Madeira (costa norte)',
        'MRM' => 'Madeira (regiões montanhosas)', 'MPS' => 'Porto Santo', 'AOR' => 'Açores (grupo oriental)',
        'ACE' => 'Açores (grupo central)', 'AOC' => 'Açores (grupo ocidental)',
    ];

    public const PAGE_URL = 'https://www.ipma.pt/pt/otempo/prev-sam/';

    /** "no distrito de Lisboa", mas "do Porto", "da Guarda" e, nas ilhas, "na Madeira", "nos Açores". */
    public static function where(string $area): string
    {
        $name = self::AREAS[$area] ?? $area;

        return match (true) {
            $area === 'PTO' => 'no distrito do Porto',
            $area === 'GDA' => 'no distrito da Guarda',
            $area === 'MPS' => 'no Porto Santo',
            str_starts_with($area, 'M') && isset(self::AREAS[$area]) => 'na ' . $name,
            in_array($area, ['AOR', 'ACE', 'AOC'], true) => 'nos ' . $name,
            default => 'no distrito de ' . $name,
        };
    }

    public function parse(string $body): RadarParseResult
    {
        try {
            $rows = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RadarFetchException('Formato inesperado: o JSON do IPMA não se leu.');
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RadarFetchException('Formato inesperado: o IPMA não devolveu uma lista de avisos.');
        }
        $items = [];
        foreach ($rows as $row) {
            $level = is_array($row) ? (string) ($row['awarenessLevelID'] ?? '') : '';
            if (! isset(self::LEVELS[$level])) {
                continue;
            }
            $area = (string) ($row['idAreaAviso'] ?? '');
            $type = trim((string) ($row['awarenessTypeName'] ?? ''));
            $start = (string) ($row['startTime'] ?? '');
            $district = self::AREAS[$area] ?? $area;
            $startAt = $this->moment($start);
            $endAt = $this->moment((string) ($row['endTime'] ?? ''));
            $items[] = new RadarItemDto(
                title: sprintf('Aviso %s de %s %s', self::LEVELS[$level], $type, self::where($area)),
                url: (string) ($this->config['page_url'] ?? self::PAGE_URL),
                sourceName: $this->sourceName(),
                publishedAt: $startAt,
                extra: [
                    'area' => $area, 'district' => $district, 'level' => $level, 'type' => $type,
                    'start' => $startAt?->toIso8601ZuluString(), 'end' => $endAt?->toIso8601ZuluString(),
                    'text' => mb_substr(trim((string) ($row['text'] ?? '')), 0, 500) ?: null,
                ],
                externalKey: sha1("ipma|{$area}|{$type}|{$level}|{$start}"),
            );
        }

        return new RadarParseResult($items);
    }

    /** As horas do IPMA vêm sem fuso: tratam-se como UTC (a declaração da API), até confirmação. */
    private function moment(string $value): ?CarbonImmutable
    {
        return $this->date($value, 'UTC');
    }
}
