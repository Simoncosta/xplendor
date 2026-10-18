<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * C1 · O preço do fornecedor subiu: o preço por unidade base na última compra contra a mediana das
 * compras anteriores do mesmo artigo e fornecedor (90 dias). Achado se subir mais de 8 %
 * (configurável por família). € em jogo = a subida × a quantidade comprada desde então; mostra também
 * a projeção mensal (× a quantidade média por mês). Sem unidade confirmada, o € fica "a confirmar".
 */
final class PrecoSubiuRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_preco_subiu';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'O preço do fornecedor subiu';
    }

    public function description(): string
    {
        return 'A última compra saiu mais cara por unidade base do que a mediana das compras anteriores ao mesmo fornecedor (90 dias).';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores', 'artigos'];
    }

    /** O limiar da família (o primeiro nome de família que aparece no caminho), senão o geral. */
    public static function threshold(string $familyPath): float
    {
        $byFamily = (array) self::cfg('price_rise_pct_by_family', []);
        foreach ($byFamily as $name => $pct) {
            if ($name !== '' && mb_stripos($familyPath, (string) $name) !== false) {
                return (float) $pct;
            }
        }

        return (float) self::cfg('price_rise_pct', 8);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = $this->data($scope);
        $since = $data->daysAgo(90);
        $units = $data->cost()->units();
        // Acima disto já não é uma subida de preço: é a unidade ou o lançamento (regras de unidades e do preço de compra).
        $max = (float) self::cfg('price_rise_max_pct', 200);
        foreach ($data->purchases() as $pid => $lines) {
            $pid = (string) $pid;
            if (! $this->isProduct($scope, $pid)) {
                continue;
            }
            $item = $data->item($pid);
            $family = (string) ($item?->family ?? '');
            $limit = self::threshold($family);
            $bySupplier = [];
            foreach ($lines as $l) {
                if ($l['date'] >= $since && $l['qnt'] > 0 && $l['total'] > 0) {
                    $bySupplier[self::supplierKey($l)][] = $l;
                }
            }
            foreach ($bySupplier as $sk => $rows) {
                $sk = (string) $sk;
                usort($rows, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
                $lastDate = end($rows)['date'];
                $before = array_values(array_filter($rows, fn ($r) => $r['date'] < $lastDate));
                $now = array_values(array_filter($rows, fn ($r) => $r['date'] === $lastDate));
                if (count($before) < 2) {
                    continue;
                }
                $prices = [];
                $confirmed = true;
                foreach ($before as $r) {
                    [$p, $c] = self::unitPrice($r);
                    if ($p !== null) {
                        $prices[] = $p;
                        $confirmed = $confirmed && $c;
                    }
                }
                $qtyNow = 0.0;
                $totalNow = 0.0;
                foreach ($now as $r) {
                    [, $c] = self::unitPrice($r);
                    $confirmed = $confirmed && $c;
                    $qtyNow += self::baseQty($r);
                    $totalNow += $r['total'];
                }
                if (! $prices || $qtyNow <= 0) {
                    continue;
                }
                sort($prices);
                $median = $prices[intdiv(count($prices), 2)];
                $last = $totalNow / $qtyNow;
                $rise = $median > 0 ? ($last / $median - 1) * 100 : 0;
                if ($rise <= $limit || $rise > $max) {
                    continue;
                }
                $eur = round(($last - $median) * $qtyNow, 2);
                // A quantidade média por mês: as compras de 12 meses deste artigo a este fornecedor.
                $all = array_filter($lines, fn ($l) => self::supplierKey($l) === $sk);
                $first = min(array_column($all, 'date'));
                $months = max(1.0, (strtotime($data->today()) - strtotime($first)) / (86400 * 30.4));
                $monthlyQty = array_sum(array_map(fn ($l) => self::baseQty($l), $all)) / $months;
                $monthly = round(($last - $median) * $monthlyQty, 2);
                $l = end($rows);
                $baseUnit = (string) ($units[$data->cost()->componentBaseRoot($pid)]->description ?? 'unidade');
                $u = mb_strtolower($baseUnit);
                $desc = (string) ($item?->description ?? $l['description']);
                $refs = array_values(array_unique(array_merge([['artigo', $pid]], array_map(fn ($r) => ['documento', $r['docheader_id']], $now),
                    $l['supplier_id'] !== '' ? [['fornecedor', $l['supplier_id']]] : []), SORT_REGULAR));

                yield new AuditFindingData(
                    entityType: 'artigo', entityId: $pid, entityLabel: $data->label($pid, $desc),
                    title: Fmt::title($desc, sprintf('subiu %s na %s', Fmt::pct($rise), $l['supplier'] ?: 'fornecedora')),
                    why: 'Uma subida de preço que ninguém viu passa para o custo de todos os pratos que usam o artigo, e o food cost sobe sem aviso.',
                    suggestion: sprintf('Veja o histórico de preços e compare fornecedores. Se a subida não foi combinada, fale com %s; se foi, atualize os preços de venda dos pratos afetados.', $l['supplier'] ?: 'o fornecedor'),
                    evidence: [
                        'facts' => [
                            ['label' => 'Fornecedor', 'value' => (string) ($l['supplier'] ?: 'Sem nome')],
                            ['label' => "Última compra (por {$u})", 'value' => round($last, 4), 'format' => 'eur4', 'tip' => sprintf('%s, a %s.', Fmt::doc($l['document']), Fmt::date($lastDate))],
                            ['label' => "Mediana das compras anteriores (90 dias, por {$u})", 'value' => round($median, 4), 'format' => 'eur4'],
                            ['label' => 'Subida', 'value' => round($rise, 1), 'format' => 'pct', 'tip' => sprintf('O limiar desta família é %s.', Fmt::pct($limit))],
                            ['label' => 'Projeção por mês', 'value' => $monthly, 'format' => 'eur', 'tip' => sprintf('A subida × %s por mês (a média das compras a este fornecedor).', self::qtyText($monthlyQty, $u))],
                            ['label' => 'Quantidade', 'value' => $confirmed ? 'Na unidade confirmada' : 'A confirmar: sem conversão confirmada da unidade de compra'],
                        ],
                        'table' => [
                            'title' => 'As compras a este fornecedor (90 dias)',
                            'columns' => [['key' => 'date', 'label' => 'Data', 'format' => 'date'], ['key' => 'document', 'label' => 'Documento'],
                                ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'], ['key' => 'price', 'label' => "Preço por {$u}", 'align' => 'end', 'format' => 'eur4']],
                            'rows' => array_map(fn ($r) => ['date' => $r['date'], 'document' => Fmt::doc($r['document']), 'qty' => Fmt::qty($r['qnt']) . ' ' . $r['unit_label'],
                                'price' => ($p = self::unitPrice($r)[0]) !== null ? round($p, 4) : null], array_slice(array_reverse($rows), 0, 12)),
                        ],
                    ],
                    evidenceKey: $sk, signature: sprintf('%s|%.4f', $lastDate, $last),
                    impactCents: $confirmed ? (int) round($eur * 100) : null, impactPendingCents: $confirmed ? null : (int) round($eur * 100),
                    weightCents: (int) round($monthly * 100) ?: null, severity: self::severityFor(max($eur, $monthly)),
                    fixUrl: self::articleUrl($pid), fixLabel: 'Ver histórico de preços',
                    fixAction: ['type' => 'historico_precos', 'product_id' => $pid],
                    refs: $refs,
                    seen: sprintf('A %s, %s faturou %s a %s por %s. Nos 90 dias antes, a mediana era %s: subiu %s.', Fmt::date($lastDate), $l['supplier'] ?: 'o fornecedor',
                        $desc, Fmt::unitPrice($last), $u, Fmt::unitPrice($median), Fmt::pct($rise)),
                    stake: sprintf('%s%s: a subida × %s comprados desde então. Se continuar, são %s por mês.', $confirmed ? '' : 'A confirmar, ', Fmt::eur($eur),
                        self::qtyText($qtyNow, $u), Fmt::eur($monthly)),
                );
            }
        }
    }
}
