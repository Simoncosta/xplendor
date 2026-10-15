<?php

declare(strict_types=1);

namespace App\Audit\Rules\Tracking;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\TrackingDailyCount;
use App\Services\Tracking\TrackingHealthService;
use Carbon\CarbonImmutable;

/**
 * Parte B1 · Eventos do site que não chegam à XPLENDOR (GA4 × XPLENDOR), por tipo, nos últimos 28
 * dias (as contagens diárias guardadas):
 *  · o GA4 tem eventos e a XPLENDOR tem 0 durante 7 dias seguidos (alta; ex.: o WhatsApp da Quebom);
 *  · o GA4 tem 5 ou mais por dia e a XPLENDOR fica abaixo de 70% ou acima de 130% durante 3 dias
 *    seguidos (média).
 */
final class Ga4DivergenciaRule extends TrackingRule
{
    public const ZERO_DAYS = 7;
    public const DIVERGE_DAYS = 3;
    public const MIN_GA4 = 5;
    public const LOW = 0.7;
    public const HIGH = 1.3;

    public function code(): string
    {
        return 'tracking.ga4_divergencia';
    }

    public function entityType(): string
    {
        return 'evento_tracking';
    }

    public function title(): string
    {
        return 'Eventos do site que não batem com o Google Analytics';
    }

    public function description(): string
    {
        return 'Eventos em que a XPLENDOR conta muito menos, ou muito mais, do que o Google Analytics do site.';
    }

    public function appliesTo(int $companyId): bool
    {
        return TrackingHealthService::propertyId($companyId) !== null;
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $since = CarbonImmutable::now('Europe/Lisbon')->subDays(TrackingHealthService::DAYS + 1)->toDateString();
        $series = [];
        foreach (TrackingDailyCount::where('company_id', $companyId)->where('day', '>=', $since)->orderBy('day')->get() as $c) {
            $series[$c->event_type][$c->day->toDateString()][$c->source] = $c->count;
        }
        $maps = app(TrackingHealthService::class)->maps($companyId);

        foreach ($series as $type => $days) {
            ksort($days);
            $rows = [];
            foreach ($days as $day => $c) {
                if (! array_key_exists('ga4', $c)) {
                    continue; // sem dados do GA4 nesse dia
                }
                $rows[] = ['day' => $day, 'ga4' => (int) $c['ga4'], 'xplendor' => (int) ($c['xplendor'] ?? 0)];
            }
            if (! $rows) {
                continue;
            }
            $zero = $this->longestRun($rows, fn ($r) => $r['ga4'] > 0 && $r['xplendor'] === 0);
            $diverge = $this->longestRun($rows, fn ($r) => $r['ga4'] >= self::MIN_GA4 && ($r['xplendor'] < $r['ga4'] * self::LOW || $r['xplendor'] > $r['ga4'] * self::HIGH));
            $kind = match (true) {
                count($zero) >= self::ZERO_DAYS => 'zero',
                count($diverge) >= self::DIVERGE_DAYS => 'diverge',
                default => null,
            };
            if (! $kind) {
                continue;
            }
            $run = $kind === 'zero' ? $zero : $diverge;
            $ga4 = array_sum(array_column($run, 'ga4'));
            $xp = array_sum(array_column($run, 'xplendor'));
            $events = implode(', ', array_map(fn ($m) => "«{$m['ga4_event']}»", array_values(array_filter($maps, fn ($m) => $m['xplendor_type'] === $type))));
            $from = Fmt::date($run[0]['day']);
            $to = Fmt::date(end($run)['day']);
            $label = self::typeLabel($type);
            $seen = $kind === 'zero'
                ? sprintf('Durante %d dias seguidos (%s a %s), o Google Analytics do site contou %s %s e a XPLENDOR contou 0.', count($run), $from, $to, Fmt::qty($ga4, 0), Fmt::lower($label))
                : sprintf('Durante %d dias seguidos (%s a %s), a XPLENDOR contou %s %s e o Google Analytics contou %s. A XPLENDOR ficou em %s do Google Analytics; o normal é entre 70%% e 130%%.',
                    count($run), $from, $to, Fmt::qty($xp, 0), Fmt::lower($label), Fmt::qty($ga4, 0), Fmt::pct($ga4 > 0 ? $xp / $ga4 * 100 : null));

            yield new AuditFindingData(
                entityType: 'evento_tracking', entityId: $type, entityLabel: $label,
                title: Fmt::title($label, $kind === 'zero' ? 'o Google Analytics conta, a XPLENDOR não' : 'a XPLENDOR e o Google Analytics não batem'),
                why: $kind === 'zero'
                    ? 'Esses contactos não entram na XPLENDOR: o funil, os relatórios e os leads ficam incompletos.'
                    : 'Parte dos contactos não chega à XPLENDOR, ou é contada a mais: o funil e os relatórios ficam errados.',
                suggestion: 'Peça a quem gere o site para confirmar que o script da XPLENDOR envia este evento. Veja também se há pedidos recusados deste tipo na Auditoria.',
                evidence: [
                    'facts' => [
                        ['label' => 'Evento', 'value' => $label],
                        ['label' => 'Nome no Google Analytics', 'value' => $events ?: $type],
                        ['label' => 'Dias seguidos', 'value' => count($run) . " ({$from} a {$to})"],
                    ],
                    'table' => [
                        'title' => 'Os últimos 14 dias',
                        'columns' => [
                            ['key' => 'day', 'label' => 'Dia', 'format' => 'date'], ['key' => 'ga4', 'label' => 'Google Analytics', 'align' => 'end'],
                            ['key' => 'xplendor', 'label' => 'XPLENDOR', 'align' => 'end'], ['key' => 'ratio', 'label' => 'XPLENDOR face ao Google Analytics', 'align' => 'end'],
                        ],
                        'rows' => array_map(fn ($r) => $r + ['ratio' => $r['ga4'] > 0 ? Fmt::pct($r['xplendor'] / $r['ga4'] * 100) : '—'], array_slice(array_reverse($rows), 0, 14)),
                    ],
                ],
                evidenceKey: $kind,
                signature: $kind,
                severity: $kind === 'zero' ? 'alta' : 'media',
                fixUrl: self::integrationsUrl($companyId), fixLabel: 'Abrir as integrações',
                refs: [['evento_tracking', $type]],
                seen: $seen,
                stake: $kind === 'zero'
                    ? sprintf('Não há um valor em euros calculável. O que está em jogo são %s %s que não chegaram à XPLENDOR.', Fmt::qty($ga4, 0), Fmt::lower($label))
                    : sprintf('Não há um valor em euros calculável. A diferença nesses dias é de %s %s.', Fmt::qty(abs($ga4 - $xp), 0), Fmt::lower($label)),
            );
        }
    }

    /** O troço mais recente (de pelo menos um dia) em que a condição se cumpre em dias seguidos. */
    private function longestRun(array $rows, callable $cond): array
    {
        $best = [];
        $cur = [];
        foreach ($rows as $r) {
            if ($cond($r)) {
                $cur[] = $r;
                if (count($cur) >= count($best)) {
                    $best = $cur;
                }
            } else {
                $cur = [];
            }
        }

        return $best;
    }
}
