<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Regra 4 · Unidade da linha sem conversão (média): compras de um artigo numa unidade de outra
 * família que a do artigo (ex.: «Unidade» num artigo em Quilograma), sem fator confirmado em
 * product_unit_conversions. Um achado por artigo e unidade. "Corrigir" abre o "Confirmar fator"
 * da Fatia 1b para essa unidade (só na XPLENDOR; o PingWin não muda).
 */
final class UnidadeSemConversaoRule extends RestaurantRule
{
    public function code(): string
    {
        return 'restauracao.unidade_sem_conversao';
    }

    public function entityType(): string
    {
        return 'artigo_unidade';
    }

    public function title(): string
    {
        return 'Compras numa unidade diferente da do stock';
    }

    public function description(): string
    {
        return 'Artigos comprados numa unidade que não se converte na unidade de stock, sem a embalagem confirmada.';
    }

    public function screens(): array
    {
        return ['faturas', 'artigos', 'fichas'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $cost = $data->cost();
        $units = $cost->units();

        foreach ($data->purchases() as $pid => $lines) {
            $pid = (string) $pid;
            $base = $cost->componentBaseRoot($pid);
            if ($base === null) {
                continue;
            }
            $baseLabel = (string) ($units[$base]->description ?? $base);
            $groups = [];
            foreach ($lines as $l) {
                if ($l['unit_id'] === '' || $l['qnt'] <= 0) {
                    continue;
                }
                $groups[$l['unit_id']][] = $l;
            }
            foreach ($groups as $unitId => $rows) {
                $root = $cost->unitRoot((string) $unitId);
                if ($root === null || $root === $base) {
                    continue; // unidade desconhecida, ou da mesma família (converte pelo fator do PingWin)
                }
                if (array_filter($rows, fn ($r) => $r['qty_source'] === 'confirmado')) {
                    continue; // já tem fator confirmado
                }
                $unitLabel = (string) $rows[0]['unit_label'];
                $rootLabel = (string) ($units[$root]->description ?? $root);
                $item = $data->item($pid);
                $desc = (string) ($item?->description ?? $rows[0]['description']);
                $total = (int) round(array_sum(array_column($rows, 'total')) * 100);
                usort($rows, fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
                $refs = [['artigo', $pid]];
                foreach ($rows as $r) {
                    $refs[] = ['documento', $r['docheader_id']];
                    if ($r['supplier_id'] !== '') {
                        $refs[] = ['fornecedor', $r['supplier_id']];
                    }
                }

                yield new AuditFindingData(
                    entityType: 'artigo_unidade', entityId: "{$pid}|{$unitId}", entityLabel: "{$data->label($pid, $desc)} em «{$unitLabel}»",
                    title: Fmt::title($desc, sprintf('comprado em «%s», contado em %s', $unitLabel, mb_strtolower($baseLabel))),
                    why: sprintf('Sem saber %s tem cada «%s», a XPLENDOR não calcula o preço por %s. O custo das fichas que usam o artigo fica errado ou em branco.',
                        Fmt::howMany($baseLabel), $unitLabel, mb_strtolower($baseLabel)),
                    suggestion: sprintf('Veja numa fatura %s tem cada «%s». Indique esse número em «Corrigir» (fica só na XPLENDOR; o PingWin não muda). Se o artigo já não se compra assim, ignore com esse motivo.',
                        Fmt::howMany($baseLabel), $unitLabel),
                    evidence: [
                        'facts' => [
                            ['label' => 'Unidade de stock do artigo', 'value' => $baseLabel],
                            ['label' => 'Unidade da compra', 'value' => "«{$unitLabel}» (conta em {$rootLabel})"],
                            ['label' => 'Compras nesta unidade (12 meses)', 'value' => count($rows)],
                            ['label' => 'Compras', 'value' => $total / 100, 'format' => 'eur'],
                            ['label' => 'Última compra', 'value' => Fmt::date($rows[0]['date'])],
                        ],
                        'table' => [
                            'title' => 'As compras nesta unidade',
                            'columns' => [
                                ['key' => 'date', 'label' => 'Data', 'format' => 'date'], ['key' => 'document', 'label' => 'Documento'],
                                ['key' => 'supplier', 'label' => 'Fornecedor'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'],
                                ['key' => 'total_cents', 'label' => 'Total', 'align' => 'end', 'format' => 'cents'],
                            ],
                            'rows' => array_map(fn ($r) => [
                                'date' => $r['date'], 'document' => Fmt::doc($r['document']), 'supplier' => $r['supplier'],
                                'qty' => Fmt::qty($r['qnt']) . " «{$r['unit_label']}»", 'total_cents' => (int) round($r['total'] * 100),
                            ], array_slice($rows, 0, 12)),
                        ],
                    ],
                    signature: "{$unitId}|{$base}",
                    weightCents: $total ?: null,
                    fixLabel: 'Confirmar a embalagem',
                    fixAction: ['type' => 'confirmar_fator', 'product_id' => $pid, 'unit_key' => (string) $unitId, 'description' => $desc],
                    refs: array_values(array_unique($refs, SORT_REGULAR)),
                    seen: sprintf('Nos últimos 12 meses houve %d %s de %s em «%s». O stock deste artigo conta-se em %s. Ninguém confirmou ainda %s tem cada «%s».',
                        count($rows), count($rows) === 1 ? 'compra' : 'compras', $desc, $unitLabel, mb_strtolower($baseLabel), Fmt::howMany($baseLabel), $unitLabel),
                    stake: sprintf('Não há um valor direto em jogo. A relevância são estas compras: %s nos últimos 12 meses.', Fmt::cents($total)),
                );
            }
        }
    }
}
