<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;

/**
 * U0 · Combinação nova (média): um fornecedor faturou um artigo numa unidade que nunca tinha usado
 * para ele (nos últimos 30 dias), num artigo que já se comprava de outra forma. Pergunta-se logo: a
 * corrida das faturas corre no fim da sincronização das linhas.
 */
final class CombinacaoNovaRule extends UnitRule
{
    public const DAYS = 30;

    public function code(): string
    {
        return 'restauracao.unidade_combinacao_nova';
    }

    public function title(): string
    {
        return 'Unidade nova numa fatura';
    }

    public function description(): string
    {
        return 'Um fornecedor faturou um artigo numa unidade que nunca tinha usado para ele.';
    }

    public function screens(): array
    {
        return ['faturas', 'unidades', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $since = $data->daysAgo(self::DAYS);
        $ev = $this->evidence($scope);
        foreach ($data->purchases() as $pid => $rows) {
            $pid = (string) $pid;
            $first = [];
            foreach ($rows as $r) {
                $k = ($r['nif'] ?: $r['supplier_id']) . '|' . $r['unit_key'];
                $first[$k] ??= $r['date'];
            }
            $oldest = min(array_column($rows, 'date'));
            foreach ($first as $k => $date) {
                if ($date < $since || $date <= $oldest) {
                    continue; // não é nova, ou o artigo é novo de todo
                }
                $lines = array_values(array_filter($rows, fn ($r) => (($r['nif'] ?: $r['supplier_id']) . '|' . $r['unit_key']) === $k));
                if (array_filter($lines, fn ($r) => $r['qty_source'] === 'confirmado')) {
                    continue;
                }
                $e = $ev->for($pid);
                if (! $e || ! ($e['stock_relevant'] ?? true) || ($e['profile']['state'] ?? null) === 'nao_se_aplica') {
                    continue;
                }
                $l = $lines[0];
                $before = array_values(array_unique(array_map(fn ($r) => $r['unit_label'], array_filter($rows, fn ($r) => $r['date'] < $date))));
                $total = (int) round(array_sum(array_column($lines, 'total')) * 100);
                yield $this->finding($e,
                    problem: sprintf('%s faturou em «%s» pela primeira vez', $l['supplier'] ?: 'um fornecedor', $l['unit_label']),
                    seen: sprintf('A %s, %s faturou %s em «%s» (%s), uma unidade que nunca tinha usado para este artigo. Até aí comprava-se em %s.',
                        Fmt::date($date), $l['supplier'] ?: 'o fornecedor', $e['description'], $l['unit_label'], Fmt::doc($l['document']),
                        implode(', ', array_map(fn ($u) => "«{$u}»", $before)) ?: 'outra unidade'),
                    why: 'Sem saber quanto leva esta unidade, a quantidade que entra no stock e o custo por unidade base podem estar errados desde esta fatura.',
                    todo: 'Abra o Guião neste artigo e diga quanto leva cada «' . $l['unit_label'] . '». Fica só na XPLENDOR até decidir aplicar no PingWin.',
                    stake: sprintf('A confirmar: %s nesta unidade desde %s.', Fmt::cents($total), Fmt::date($date)),
                    evidence: ['facts' => self::facts($e, [['label' => 'Unidade nova', 'value' => "«{$l['unit_label']}» de " . ($l['supplier'] ?: 'fornecedor')],
                        ['label' => 'Primeira fatura', 'value' => Fmt::doc($l['document']) . ' a ' . Fmt::date($date)]]), 'table' => self::purchasesTable($e['purchases'])],
                    pendingCents: $total ?: null, severity: 'media', key: $k,
                    refs: array_values(array_unique(array_merge([['artigo', $pid]], array_map(fn ($r) => ['documento', $r['docheader_id']], $lines)), SORT_REGULAR)),
                );
            }
        }
    }
}
