<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinPosDocument;
use Carbon\CarbonImmutable;

/**
 * C3 · Anulações fora do padrão: por funcionário, loja e semana, a parte das vendas que foi anulada
 * contra a mediana dos colegas (mesma loja e semana) e contra o histórico do próprio (as 4 semanas
 * antes). Mínimos: 10 documentos de venda na semana e 2 × a mediana dos colegas. Mostra os padrões
 * (anulação depois da consulta de mesa com o mesmo total, anulação perto do fecho). Texto neutro:
 * "Padrão fora do habitual a rever". Só o administrador e o dono veem (tem nomes de funcionários).
 * € em jogo = o valor anulado acima da mediana dos colegas. Os descontos e as ofertas não estão na
 * lista do PingWin (pedem abrir cada documento): ficam por ler, ditos no achado.
 */
final class ForaDoPadraoRule extends MoneyRule
{
    private const WEEKS = 4;

    public function code(): string
    {
        return 'restauracao.dinheiro_fora_do_padrao';
    }

    public function entityType(): string
    {
        return 'funcionario';
    }

    public function title(): string
    {
        return 'Padrão fora do habitual a rever';
    }

    public function description(): string
    {
        return 'Anulações de um funcionário numa semana bem acima das dos colegas da mesma loja e do seu próprio histórico.';
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function screens(): array
    {
        return [];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        // Desligada por defeito: só corre depois de o dono a ligar (Configurações › Restauração e Auditoria).
        if (! \App\Settings\Settings::company($companyId, 'audit.voids_by_employee')) {
            return;
        }
        $today = CarbonImmutable::parse($this->data($scope)->today());
        // Só semanas fechadas e já lidas por inteiro (até ao domingo passado e até ao último dia do
        // espelho), as WEEKS mais recentes e as WEEKS antes (o histórico).
        $synced = PingwinPosDocument::where('company_id', $companyId)->max('doc_date');
        if (! $synced) {
            return;
        }
        $syncedDay = CarbonImmutable::parse(substr((string) $synced, 0, 10));
        $lastSunday = $today->startOfWeek()->subDay();
        $lastSynced = $syncedDay->isSunday() ? $syncedDay : $syncedDay->startOfWeek()->subDay();
        $lastSunday = $lastSynced->lt($lastSunday) ? $lastSynced : $lastSunday;
        $from = $lastSunday->subWeeks(self::WEEKS * 2)->addDay();
        $docs = PingwinPosDocument::where('company_id', $companyId)->whereIn('docconfig_id', array_merge([PingwinPosDocument::VOID, PingwinPosDocument::TABLE_CHECK], PingwinPosDocument::SALES))
            ->whereBetween('doc_date', [$from->toDateString(), $lastSunday->toDateString()])->where('docstatus_id', '!=', '8003')
            ->orderBy('doc_date')->orderBy('doc_time')
            ->get(['docheader_id', 'docconfig_id', 'document', 'store_pingwin_id', 'store_name', 'doc_date', 'doc_time', 'total_cents', 'employee_pingwin_id', 'employee_name']);
        if ($docs->where('docconfig_id', PingwinPosDocument::VOID)->isEmpty()) {
            return;
        }
        $stats = self::weekly($docs->all());
        foreach (self::outliers($stats, $lastSunday->subWeeks(self::WEEKS)->addDay()->toDateString()) as $o) {
            yield $this->finding($o);
        }
    }

    /**
     * Por loja, semana (segunda-feira) e funcionário: as vendas (n, €), as anulações (n, €) e os
     * padrões das anulações. @return array<string, array<string, array<string, array>>>
     */
    public static function weekly(array $docs): array
    {
        $days = [];
        foreach ($docs as $d) {
            $days[$d->store_pingwin_id . '|' . substr((string) $d->doc_date, 0, 10)][] = $d;
        }
        $out = [];
        foreach ($days as $list) {
            $sales = array_filter($list, fn ($d) => in_array($d->docconfig_id, PingwinPosDocument::SALES, true));
            // O fecho do dia: a última venda (as horas depois da meia-noite contam no mesmo dia).
            $close = $sales ? max(array_map(fn ($d) => self::minutes((string) $d->doc_time), $sales)) : null;
            foreach ($list as $d) {
                $week = CarbonImmutable::parse(substr((string) $d->doc_date, 0, 10))->startOfWeek()->toDateString();
                $e = (string) ($d->employee_pingwin_id ?: 'sem');
                $row = &$out[(string) $d->store_pingwin_id][$week][$e];
                $row ??= ['employee_id' => $e, 'employee' => (string) ($d->employee_name ?: 'Sem funcionário'), 'store_id' => (string) $d->store_pingwin_id,
                    'store' => (string) $d->store_name, 'week' => $week, 'sales' => 0, 'sales_cents' => 0, 'voids' => 0, 'void_cents' => 0, 'after_check' => 0, 'near_close' => 0, 'rows' => []];
                if (in_array($d->docconfig_id, PingwinPosDocument::SALES, true)) {
                    $row['sales']++;
                    $row['sales_cents'] += (int) $d->total_cents;
                } elseif ($d->docconfig_id === PingwinPosDocument::VOID) {
                    $t = self::minutes((string) $d->doc_time);
                    $check = self::checkBefore($list, $d, $t);
                    $nearClose = $close !== null && $t >= $close - 30;
                    $row['voids']++;
                    $row['void_cents'] += (int) $d->total_cents;
                    $row['after_check'] += $check ? 1 : 0;
                    $row['near_close'] += $nearClose ? 1 : 0;
                    $row['rows'][] = ['date' => substr((string) $d->doc_date, 0, 10), 'time' => substr((string) $d->doc_time, 0, 5), 'document' => Fmt::doc($d->document),
                        'total' => ((int) $d->total_cents) / 100, 'pattern' => implode('; ', array_filter([$check ? 'Depois da consulta de mesa (mesmo total, ' . substr((string) $check->doc_time, 0, 5) . ')' : null, $nearClose ? 'Perto do fecho' : null])) ?: '—'];
                }
                unset($row);
            }
        }

        return $out;
    }

    /** Uma consulta de mesa com o mesmo total nas 3 horas antes da anulação, na mesma loja e dia. */
    private static function checkBefore(array $day, $void, int $t): ?object
    {
        $best = null;
        foreach ($day as $d) {
            if ($d->docconfig_id !== PingwinPosDocument::TABLE_CHECK || (int) $d->total_cents !== (int) $void->total_cents) {
                continue;
            }
            $m = self::minutes((string) $d->doc_time);
            if ($m <= $t && $t - $m <= 180 && (! $best || $m > self::minutes((string) $best->doc_time))) {
                $best = $d;
            }
        }

        return $best;
    }

    /** Minutos desde a abertura do dia de negócio: antes das 06:00 conta como o fim do dia anterior. */
    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0'));

        return ($h < 6 ? $h + 24 : $h) * 60 + $m;
    }

    /**
     * Os funcionários fora do padrão nas semanas recentes: ≥ pattern_min_docs vendas na semana, a
     * parte anulada ≥ pattern_times_median × a mediana dos colegas (com o mínimo de 3 anulações e
     * 20 € quando a mediana é zero) e acima do próprio histórico.
     */
    public static function outliers(array $stats, string $recentFrom): array
    {
        $minDocs = (int) self::cfg('pattern_min_docs', 10);
        $times = (float) self::cfg('pattern_times_median', 2);
        $rate = fn (array $r) => $r['sales_cents'] > 0 ? $r['void_cents'] / $r['sales_cents'] : ($r['void_cents'] > 0 ? 1.0 : 0.0);
        $out = [];
        foreach ($stats as $weeks) {
            foreach ($weeks as $week => $rows) {
                if ($week < $recentFrom) {
                    continue;
                }
                foreach ($rows as $e => $r) {
                    if ($r['sales'] < $minDocs || $r['voids'] === 0) {
                        continue;
                    }
                    $peers = array_values(array_map($rate, array_filter($rows, fn ($p, $k) => $k !== $e && $p['sales'] >= $minDocs, ARRAY_FILTER_USE_BOTH)));
                    if (! $peers) {
                        continue;
                    }
                    $median = self::median($peers);
                    $mine = $rate($r);
                    $above = $median > 0 ? $mine >= $times * $median : ($r['voids'] >= 3 && $r['void_cents'] >= 2000);
                    if (! $above) {
                        continue;
                    }
                    // O histórico do próprio: as semanas antes, na mesma loja.
                    $history = [];
                    foreach ($weeks as $w => $rs) {
                        if ($w < $week && isset($rs[$e]) && $rs[$e]['sales'] >= $minDocs) {
                            $history[] = $rate($rs[$e]);
                        }
                    }
                    $own = $history ? self::median($history) : null;
                    if ($own !== null && $mine <= $own * 1.5) {
                        continue; // é o habitual dele nesta loja: não é fora do padrão
                    }
                    $excess = (int) round($r['void_cents'] - $median * $r['sales_cents']);
                    if ($excess <= 0) {
                        continue;
                    }
                    $out[] = $r + ['rate' => $mine, 'peers_median' => $median, 'peers' => count($peers), 'own' => $own, 'own_weeks' => count($history), 'excess_cents' => $excess];
                }
            }
        }

        return $out;
    }

    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    private function finding(array $o): AuditFindingData
    {
        $week = CarbonImmutable::parse($o['week']);
        $weekText = sprintf('semana de %s a %s', $week->format('d/m'), $week->addDays(6)->format('d/m'));
        $pct = fn (float $r) => Fmt::pct($r * 100, 1);
        $patterns = array_values(array_filter([
            $o['after_check'] ? sprintf('%d depois da consulta de mesa com o mesmo total', $o['after_check']) : null,
            $o['near_close'] ? sprintf('%d perto do fecho', $o['near_close']) : null,
        ]));

        return new AuditFindingData(
            entityType: 'funcionario', entityId: "{$o['store_id']}|{$o['employee_id']}", entityLabel: "{$o['employee']} · {$o['store']}",
            title: Fmt::title("{$o['employee']} ({$o['store']})", "anulações fora do habitual, {$weekText}", 90),
            why: 'As anulações fazem parte do serviço (enganos, trocas, mesas que mudam). Um valor muito acima do dos colegas, na mesma loja e semana, merece uma conversa: muitas vezes é formação ou um procedimento por acertar.',
            suggestion: 'Reveja as anulações da semana com o responsável da loja e com o funcionário. Se houver uma razão (uma mesa grande trocada, um engano na caixa), ignore com o motivo.',
            evidence: [
                'facts' => array_values(array_filter([
                    ['label' => 'Loja e semana', 'value' => "{$o['store']}, {$weekText}"],
                    ['label' => 'Vendas do funcionário', 'value' => sprintf('%d documentos', $o['sales']), 'tip' => Fmt::cents($o['sales_cents'])],
                    ['label' => 'Anulações', 'value' => sprintf('%d, %s', $o['voids'], Fmt::cents($o['void_cents']))],
                    ['label' => 'Parte anulada', 'value' => $pct($o['rate'])],
                    ['label' => 'Mediana dos colegas', 'value' => $pct($o['peers_median']), 'tip' => sprintf('%d %s na mesma loja e semana, com 10 ou mais vendas.', $o['peers'], $o['peers'] === 1 ? 'colega' : 'colegas')],
                    ['label' => 'Histórico do próprio', 'value' => $o['own'] === null ? 'Sem histórico nesta loja' : $pct($o['own']), 'tip' => $o['own'] === null ? null : sprintf('A mediana de %d %s antes.', $o['own_weeks'], $o['own_weeks'] === 1 ? 'semana' : 'semanas')],
                    $patterns ? ['label' => 'Padrões', 'value' => ucfirst(implode('; ', $patterns))] : null,
                ])),
                'table' => [
                    'title' => 'As anulações da semana',
                    'columns' => [['key' => 'date', 'label' => 'Data', 'format' => 'date'], ['key' => 'time', 'label' => 'Hora'], ['key' => 'document', 'label' => 'Documento'],
                        ['key' => 'total', 'label' => 'Total', 'align' => 'end', 'format' => 'eur'], ['key' => 'pattern', 'label' => 'Padrão']],
                    'rows' => $o['rows'],
                ],
                'note' => 'Os descontos e as ofertas por funcionário ainda não se leem (pedem abrir cada documento no PingWin).',
            ],
            evidenceKey: $o['week'], signature: sprintf('%d|%d', $o['voids'], $o['void_cents']),
            impactCents: $o['excess_cents'], severity: self::severityFor($o['excess_cents'] / 100),
            refs: [['funcionario', "{$o['store_id']}|{$o['employee_id']}"]],
            seen: sprintf('Na %s, %s anulou %d %s (%s), %s das suas vendas; os colegas da mesma loja anularam, na mediana, %s.%s',
                $weekText, $o['employee'], $o['voids'], $o['voids'] === 1 ? 'documento' : 'documentos', Fmt::cents($o['void_cents']), $pct($o['rate']),
                $pct($o['peers_median']), $patterns ? ' ' . ucfirst(implode('; ', $patterns)) . '.' : ''),
            stake: sprintf('%s: o valor anulado acima da mediana dos colegas (%s − %s × %s de vendas).', Fmt::cents($o['excess_cents']), Fmt::cents($o['void_cents']),
                $pct($o['peers_median']), Fmt::cents($o['sales_cents'])),
        );
    }
}
