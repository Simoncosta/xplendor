<?php

declare(strict_types=1);

namespace App\Services\Radar\Calendar;

use App\Models\Company;
use App\Models\ContentSector;
use App\Models\EditorialPost;
use App\Services\Compass\CompassWhen;
use App\Services\Radar\RadarText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Radar, camada 2: o calendário por ramo, só com datas de fonte oficial confirmada
 * (documents/bussola-master/DESENHO.md, secção 7). Cada entrada diz a fonte, a ligação, quando foi
 * confirmada e por quem (máquina ou pessoa), e o mês da verificação. Uma data sem fonte confirmada
 * (confirmed_on null) fica "por confirmar": está aqui, com a fonte onde se confirma, mas não aparece
 * a ninguém até alguém a conferir (Bússola 5). Os feriados nacionais já são âncoras da Linha
 * Editorial (fonte única em App\Services\Calendar\PortugalCalendar) e não se repetem aqui.
 *
 * Bússola 5: o feriado municipal que a empresa indicou nas Configurações entra como data da própria
 * empresa (COMPANY_HOLIDAY), salvo quando já há uma data do concelho nesse dia (São João, Santo António).
 */
final class OfficialDates
{
    public const ALL = '*';

    public const CONFIRMED = 'confirmada';

    public const PENDING = 'por_confirmar';

    /** A chave do feriado municipal indicado pela empresa (Configurações › Calendário). */
    public const COMPANY_HOLIDAY = 'local.empresa.feriado_municipal';

    /** @return array<string, array<string, mixed>> */
    public static function entries(): array
    {
        $bce = ['name' => 'Banco Central Europeu, calendário das reuniões', 'url' => 'https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html', 'format' => 'página'];
        $at = ['name' => 'Autoridade Tributária, Agenda Fiscal', 'url' => 'https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx', 'format' => 'página'];
        $ine = ['name' => 'INE, calendário de difusão', 'url' => 'https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_calendarios&xlang=pt', 'format' => 'ics'];

        $entries = [
            'bce.monetary_policy_decision' => [
                'title' => 'Decisão de política monetária do BCE', 'sectors' => ['imobiliario'], 'municipality' => null,
                'note' => 'Anúncio às 13:15 e conferência de imprensa às 13:45 (hora de Lisboa).',
                'dates' => [['2026-10-29'], ['2026-12-17'], ['2027-02-04'], ['2027-03-18'], ['2027-04-29'], ['2027-06-10'], ['2027-07-22'], ['2027-09-09'], ['2027-10-28'], ['2027-12-16']],
                'source' => $bce, 'confirmed_on' => '2026-10-12', 'confirmed_by' => 'machine', 'review_month' => 'junho e dezembro',
            ],
            'fiscal.imi.prestacoes' => [
                'title' => 'IMI: prazo de pagamento (até ao fim do mês)', 'sectors' => ['imobiliario'], 'municipality' => null,
                'note' => 'Até 100 euros, só maio; de 100 a 500 euros, maio e novembro; acima de 500 euros, maio, agosto e novembro (Código do IMI, artigo 120.º).',
                'yearly' => [['05-01', '05-31'], ['08-01', '08-31'], ['11-01', '11-30']],
                'source' => $at, 'confirmed_on' => '2026-10-12', 'confirmed_by' => 'machine', 'review_month' => 'janeiro',
            ],
            'fiscal.irs.entrega' => [
                'title' => 'IRS: período de entrega da declaração', 'sectors' => ['automovel', 'imobiliario'], 'municipality' => null,
                'note' => 'De 1 de abril a 30 de junho (Código do IRS, artigo 60.º).',
                'yearly' => [['04-01', '06-30']],
                'source' => $at, 'confirmed_on' => '2026-10-12', 'confirmed_by' => 'machine', 'review_month' => 'março',
            ],
            'local.porto.sao_joao' => [
                'title' => 'São João do Porto (noite de 23 e feriado municipal a 24)', 'sectors' => [self::ALL], 'municipality' => 'porto',
                'note' => null,
                'yearly' => [['06-23', '06-24']],
                'source' => ['name' => 'Município do Porto', 'url' => 'https://www.porto.pt', 'format' => 'página'], 'confirmed_on' => '2026-10-12', 'confirmed_by' => 'machine', 'review_month' => 'maio',
            ],
            // Bússola 5: pedido nas Notas para a restauração e o stand. Por confirmar na fonte.
            'local.lisboa.santo_antonio' => [
                'title' => 'Santo António de Lisboa (noite de 12 e feriado municipal a 13)', 'sectors' => [self::ALL], 'municipality' => 'lisboa',
                'note' => null,
                'yearly' => [['06-12', '06-13']],
                'source' => ['name' => 'Câmara Municipal de Lisboa', 'url' => 'https://www.lisboa.pt', 'format' => 'página'], 'confirmed_on' => null, 'confirmed_by' => null, 'review_month' => 'maio',
            ],
            'ine.precos_habitacao_local' => [
                'title' => 'INE: Estatísticas de Preços da Habitação ao nível local', 'sectors' => ['imobiliario'], 'municipality' => null,
                'note' => '2.º trimestre de 2026. Publicação às 11:00.',
                'dates' => [['2026-10-23']],
                'source' => $ine, 'confirmed_on' => '2026-10-14', 'confirmed_by' => 'machine', 'review_month' => 'todos os meses',
            ],
            'ine.juro_credito_habitacao' => [
                'title' => 'INE: Taxas de Juro Implícitas no Crédito à Habitação', 'sectors' => ['imobiliario'], 'municipality' => null,
                'note' => 'Publicação mensal (setembro e outubro de 2026).',
                'dates' => [['2026-10-21'], ['2026-11-19']],
                'source' => $ine, 'confirmed_on' => '2026-10-14', 'confirmed_by' => 'machine', 'review_month' => 'todos os meses',
            ],
            'ine.avaliacao_bancaria' => [
                'title' => 'INE: Inquérito à Avaliação Bancária na Habitação', 'sectors' => ['imobiliario'], 'municipality' => null,
                'note' => 'Publicação mensal (setembro e outubro de 2026).',
                'dates' => [['2026-10-27'], ['2026-11-25']],
                'source' => $ine, 'confirmed_on' => '2026-10-14', 'confirmed_by' => 'machine', 'review_month' => 'todos os meses',
            ],
        ];

        // Bússola 6: as datas lidas no calendário do INE juntam-se às do código; a leitura mais recente
        // passa a ser a data de confirmação.
        foreach (IneReleaseCalendar::fetchedByKey() as $key => $f) {
            if (! isset($entries[$key])) {
                continue;
            }
            $dates = array_merge((array) ($entries[$key]['dates'] ?? []), $f['dates']);
            $entries[$key]['dates'] = array_values(array_map('unserialize', array_unique(array_map('serialize', $dates))));
            usort($entries[$key]['dates'], fn ($a, $b) => $a[0] <=> $b[0]);
            if ($f['fetched_on'] > (string) $entries[$key]['confirmed_on']) {
                $entries[$key]['confirmed_on'] = $f['fetched_on'];
                $entries[$key]['confirmed_by'] = 'machine';
            }
        }

        return $entries;
    }

    /** Os nomes dos meses de review_month ("junho e dezembro", "todos os meses"). */
    private const MONTHS = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];

    /** As entradas a rever neste mês (review_month) e as por confirmar (sempre). @return array<string, array<string, mixed>> */
    public static function dueForReview(int $month): array
    {
        return array_filter(self::entries(), function (array $e) use ($month) {
            if (self::status($e) === self::PENDING) {
                return true;
            }
            $text = mb_strtolower((string) ($e['review_month'] ?? ''));
            if (str_contains($text, 'todos os meses')) {
                return true;
            }
            foreach (self::MONTHS as $name => $n) {
                if ($n === $month && preg_match('/\b' . $name . '\b/u', $text)) {
                    return true;
                }
            }

            return false;
        });
    }

    public static function status(array $e): string
    {
        return empty($e['confirmed_on']) ? self::PENDING : self::CONFIRMED;
    }

    /** As entradas por confirmar (para a lista do relatório e a revisão). @return array<string, array<string, mixed>> */
    public static function pending(): array
    {
        return array_filter(self::entries(), fn ($e) => self::status($e) === self::PENDING);
    }

    /** O feriado municipal que a empresa indicou ("dd/mm"), como entrada do calendário; null sem ele. */
    public static function companyHoliday(int $companyId): ?array
    {
        $md = (string) \App\Settings\Settings::company($companyId, 'calendar.municipal_holiday');
        if (! preg_match('/^(\d{2})\/(\d{2})$/', $md, $m) || ! checkdate((int) $m[2], (int) $m[1], 2024)) {
            return null;
        }

        return [
            'title' => 'Feriado municipal do concelho', 'sectors' => [self::ALL], 'municipality' => null,
            'note' => 'O dia que a empresa indicou em Configurações › Calendário.',
            'yearly' => [["{$m[2]}-{$m[1]}"]],
            'source' => ['name' => 'Configurações da empresa (Feriado municipal)', 'url' => null, 'format' => 'definição'],
            'confirmed_on' => null, 'confirmed_by' => 'empresa', 'review_month' => null,
        ];
    }

    /** As ocorrências de uma entrada que tocam no intervalo [from, to]. @return list<array{starts_on: string, ends_on: string}> */
    public static function occurrences(array $e, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach ((array) ($e['dates'] ?? []) as $d) {
            $out[] = ['starts_on' => $d[0], 'ends_on' => $d[1] ?? $d[0]];
        }
        foreach (range((int) $from->year, (int) $to->year) as $y) {
            foreach ((array) ($e['yearly'] ?? []) as $d) {
                $out[] = ['starts_on' => "{$y}-{$d[0]}", 'ends_on' => "{$y}-" . ($d[1] ?? $d[0])];
            }
        }

        return array_values(array_filter($out, fn ($o) => $o['ends_on'] >= $from->toDateString() && $o['starts_on'] <= $to->toDateString()));
    }

    /**
     * As datas dos próximos dias para um ramo (e os seus antepassados) e um concelho. As por confirmar
     * ficam de fora. $companyHoliday: a entrada do feriado municipal da empresa (companyHoliday()).
     */
    public static function upcoming(array $sectorSlugs, ?string $municipality, CarbonImmutable $now, int $days = 30, ?array $companyHoliday = null): array
    {
        $today = $now->setTimezone('Europe/Lisbon')->startOfDay();
        $mun = $municipality !== null ? RadarText::normalize($municipality) : null;
        $out = [];
        foreach (self::entries() as $key => $e) {
            $sectorOk = in_array(self::ALL, $e['sectors'], true) || array_intersect($e['sectors'], $sectorSlugs) !== [];
            $munOk = $e['municipality'] === null || $e['municipality'] === $mun;
            if (! $sectorOk || ! $munOk || self::status($e) === self::PENDING) {
                continue;
            }
            foreach (self::occurrences($e, $today, $today->addDays($days)) as $o) {
                $out[] = self::item($key, $e, $o, $today);
            }
        }
        if ($companyHoliday !== null) {
            foreach (self::occurrences($companyHoliday, $today, $today->addDays($days)) as $o) {
                // Já há uma data do concelho nesse dia (São João no Porto): não se repete.
                $covered = array_filter($out, fn ($i) => str_starts_with($i['key'], 'local.') && $i['starts_on'] <= $o['starts_on'] && $i['ends_on'] >= $o['starts_on']);
                if ($covered === []) {
                    $out[] = self::item(self::COMPANY_HOLIDAY, $companyHoliday, $o, $today);
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['starts_on'], $a['key']] <=> [$b['starts_on'], $b['key']]);

        return $out;
    }

    private static function item(string $key, array $e, array $o, CarbonImmutable $today): array
    {
        $start = CarbonImmutable::parse($o['starts_on'], 'Europe/Lisbon');

        return ['key' => $key, 'occurrence' => $o['starts_on'], 'title' => $e['title'], 'starts_on' => $o['starts_on'], 'ends_on' => $o['ends_on'],
            'note' => $e['note'], 'source' => $e['source'], 'confirmed_on' => $e['confirmed_on'], 'confirmed_by' => $e['confirmed_by'],
            'when' => CompassWhen::present($start->lt($today) ? $today : $start, $today)];
    }

    /** As datas para uma empresa (ramo, concelho e feriado municipal), com "reacted" quando já há ideia. */
    public static function upcomingFor(int $companyId, CarbonImmutable $now, int $days = 30): array
    {
        $company = Company::find($companyId);
        $sector = $company?->content_sector_id ? ContentSector::find($company->content_sector_id) : null;
        $slugs = $sector ? array_map(fn ($n) => $n->slug, $sector->pathFromRoot()) : [];
        $municipality = $company?->municipality_id ? (string) DB::table('municipalities')->where('id', $company->municipality_id)->value('name') : null;
        $refs = EditorialPost::where('company_id', $companyId)->where('origin', 'calendario')->pluck('origin_ref')->flip();

        return array_map(fn ($e) => $e + ['reacted' => $refs->has("{$e['key']}@{$e['occurrence']}")],
            self::upcoming($slugs, $municipality, $now, $days, self::companyHoliday($companyId)));
    }

    /** Uma ocorrência pela chave e pela data de início (para o "Reagir"). As por confirmar não contam. */
    public static function find(string $key, string $occurrence, ?int $companyId = null): ?array
    {
        $e = $key === self::COMPANY_HOLIDAY
            ? ($companyId !== null ? self::companyHoliday($companyId) : null)
            : self::entries()[$key] ?? null;
        if ($e === null || ($key !== self::COMPANY_HOLIDAY && self::status($e) === self::PENDING)) {
            return null;
        }
        $day = CarbonImmutable::parse($occurrence);
        foreach (self::occurrences($e, $day->startOfYear(), $day->endOfYear()) as $o) {
            if ($o['starts_on'] === $occurrence) {
                return ['key' => $key, 'title' => $e['title'], 'note' => $e['note'], 'source' => $e['source'], 'confirmed_on' => $e['confirmed_on']] + $o;
            }
        }

        return null;
    }

    /** O BCE decide hoje? (para a jogada do imobiliário) */
    public static function isBceDecisionDay(CarbonImmutable $day): bool
    {
        return in_array([$day->toDateString()], self::entries()['bce.monetary_policy_decision']['dates'], true);
    }
}
