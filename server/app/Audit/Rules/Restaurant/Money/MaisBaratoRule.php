<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\DuplicadosRule;

/**
 * C1 · Mais barato noutro fornecedor: o mesmo artigo (ou um do mesmo grupo de prováveis duplicados,
 * na mesma unidade base) comprado nos últimos 90 dias a outro fornecedor por menos, por unidade base.
 * € em jogo = a diferença × a quantidade média por mês. Mostra o fornecedor, a data e o documento.
 * Sem as duas unidades confirmadas, o € fica "a confirmar". Só lê: nunca muda o fornecedor.
 */
final class MaisBaratoRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_mais_barato';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Mais barato noutro fornecedor';
    }

    public function description(): string
    {
        return 'O mesmo artigo (ou um igual com outro código) foi comprado a outro fornecedor por menos, por unidade base, nos últimos 90 dias.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = $this->data($scope);
        $since = $data->daysAgo(90);
        $cost = $data->cost();
        $units = $cost->units();
        // Por artigo: as compras de 90 dias, o fornecedor da última e o preço da última compra a cada fornecedor.
        $offers = [];
        $groups = [];
        foreach ($data->purchases() as $pid => $lines) {
            $pid = (string) $pid;
            if (! $this->isProduct($scope, $pid)) {
                continue;
            }
            $recent = array_values(array_filter($lines, fn ($l) => $l['date'] >= $since && $l['qnt'] > 0 && $l['total'] > 0));
            if (! $recent || max(array_column($recent, 'qnt')) <= 1 && count($recent) < 2) {
                continue;
            }
            $root = $cost->componentBaseRoot($pid);
            $bySupplier = [];
            foreach ($recent as $l) {
                [$p, $c] = self::unitPrice($l);
                if ($p !== null) {
                    $k = self::supplierKey($l);
                    $bySupplier[$k] = ['price' => $p, 'confirmed' => $c, 'line' => $l, 'pid' => $pid, 'qty' => ($bySupplier[$k]['qty'] ?? 0) + self::baseQty($l)];
                }
            }
            if (! $bySupplier) {
                continue;
            }
            $offers[$pid] = ['root' => $root, 'suppliers' => $bySupplier];
            $key = DuplicadosRule::groupKey($data->item($pid)?->description ?? $recent[0]['description']);
            if ($key !== '' && (str_contains($key, ' ') || mb_strlen($key) >= 4)) {
                $groups[$key . '|' . $root][] = $pid;
            }
        }
        $peers = [];
        foreach ($groups as $pids) {
            foreach ($pids as $p) {
                $peers[$p] = $pids;
            }
        }
        // Por artigo e por fornecedor mais caro: o € é a diferença × o que se comprou a esse fornecedor.
        foreach ($offers as $pid => $o) {
            $pid = (string) $pid;
            foreach ($o['suppliers'] as $currentKey => $current) {
                $currentKey = (string) $currentKey;
                $best = null;
                foreach ($peers[$pid] ?? [$pid] as $other) {
                    foreach ($offers[$other]['suppliers'] ?? [] as $sk => $s) {
                        // Mais de 3 vezes mais barato já não é outro preço: é a unidade ou o lançamento.
                        if ((string) $sk === $currentKey || $s['price'] >= $current['price'] * (1 - (float) self::cfg('cheaper_min_pct', 3) / 100)
                            || $s['price'] * (float) self::cfg('cheaper_max_ratio', 3) < $current['price']) {
                            continue;
                        }
                        if (! $best || $s['price'] < $best['price']) {
                            $best = $s;
                        }
                    }
                }
                if (! $best) {
                    continue;
                }
                $monthlyQty = $current['qty'] / (90 / 30.4);
                $eur = round(($current['price'] - $best['price']) * $monthlyQty, 2);
                if ($eur < (float) self::cfg('cheaper_min_eur', 5)) {
                    continue;
                }
                $confirmed = $current['confirmed'] && $best['confirmed'];
                $l = $current['line'];
                $b = $best['line'];
                $u = mb_strtolower((string) ($units[$o['root']]->description ?? 'unidade'));
                $desc = (string) ($data->item($pid)?->description ?? $l['description']);
                $sameArticle = $best['pid'] === $pid;
                $otherLabel = $sameArticle ? '' : ' (' . $data->label($best['pid'], $b['description']) . ')';
                $pct = ($current['price'] / $best['price'] - 1) * 100;

                yield new AuditFindingData(
                    entityType: 'artigo', entityId: $pid, entityLabel: $data->label($pid, $desc),
                    title: Fmt::title($desc, sprintf('%s mais barato na %s', Fmt::pct($pct), $b['supplier'] ?: 'outra fornecedora')),
                    why: 'Comprar o mesmo produto mais caro todos os meses é dinheiro que sai sem necessidade. Às vezes há uma razão (qualidade, entrega, crédito); vale a pena saber qual.',
                    suggestion: sprintf('Compare os fornecedores. Se for o mesmo produto, peça a %s o preço da %s ou passe a comprar lá.', $l['supplier'] ?: 'o fornecedor mais caro', $b['supplier'] ?: 'outra fornecedora'),
                    evidence: [
                        'facts' => [
                            ['label' => 'Fornecedor mais caro', 'value' => (string) ($l['supplier'] ?: 'Sem nome')],
                            ['label' => "Último preço (por {$u})", 'value' => round($current['price'], 4), 'format' => 'eur4', 'tip' => sprintf('%s, a %s.', Fmt::doc($l['document']), Fmt::date($l['date']))],
                            ['label' => 'Mais barato em', 'value' => (string) ($b['supplier'] ?: 'Sem nome') . $otherLabel],
                            ['label' => "Preço (por {$u})", 'value' => round($best['price'], 4), 'format' => 'eur4', 'tip' => sprintf('%s, a %s.', Fmt::doc($b['document']), Fmt::date($b['date']))],
                            ['label' => 'Quantidade por mês', 'value' => self::qtyText($monthlyQty, $u), 'tip' => 'O que se comprou a este fornecedor nos últimos 90 dias, por mês.'],
                            ['label' => 'Quantidade', 'value' => $confirmed ? 'Na unidade confirmada' : 'A confirmar: sem conversão confirmada da unidade de compra'],
                        ],
                    ],
                    evidenceKey: $currentKey, signature: sprintf('%s|%s|%.4f', $b['docheader_id'], self::supplierKey($b), $best['price']),
                    impactCents: $confirmed ? (int) round($eur * 100) : null, impactPendingCents: $confirmed ? null : (int) round($eur * 100),
                    severity: self::severityFor($eur),
                    fixUrl: self::articleUrl($pid), fixLabel: 'Comparar fornecedores',
                    fixAction: ['type' => 'comparar_fornecedores', 'product_id' => $pid, 'products' => array_values(array_unique([$pid, $best['pid']]))],
                    refs: array_values(array_unique(array_filter([['artigo', $pid], $sameArticle ? null : ['artigo', $best['pid']], ['documento', $l['docheader_id']], ['documento', $b['docheader_id']],
                        $l['supplier_id'] !== '' ? ['fornecedor', $l['supplier_id']] : null, $b['supplier_id'] !== '' ? ['fornecedor', $b['supplier_id']] : null]), SORT_REGULAR)),
                    seen: sprintf('A última compra de %s à %s foi a %s por %s. A %s, a %s faturou%s a %s por %s (%s).', $desc, $l['supplier'] ?: 'fornecedora', Fmt::unitPrice($current['price']), $u,
                        Fmt::date($b['date']), $b['supplier'] ?: 'outra fornecedora', $sameArticle ? '' : $otherLabel, Fmt::unitPrice($best['price']), $u, Fmt::doc($b['document'])),
                    stake: sprintf('%s%s por mês: a diferença de %s por %s × %s por mês.', $confirmed ? '' : 'A confirmar, ', Fmt::eur($eur),
                        Fmt::unitPrice($current['price'] - $best['price']), $u, self::qtyText($monthlyQty, $u)),
                );
            }
        }
    }
}
