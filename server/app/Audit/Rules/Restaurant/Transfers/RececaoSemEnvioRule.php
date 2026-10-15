<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 7c · T4 · Receção sem envio: linhas de uma Entrada (1104) sem nenhuma Guia do mesmo artigo,
 * entregue, nos 3 dias antes. O restaurante ganhou stock que não saiu de lado nenhum: o stock total da
 * empresa sobe sem compra. Valor: as unidades × o custo da Entrada.
 */
final class RececaoSemEnvioRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_sem_envio';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Receções sem envio do Economato';
    }

    public function description(): string
    {
        return 'Entradas nos restaurantes sem a Guia de envio correspondente.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        foreach (collect($this->pairs($companyId, $d)['orphan_entries'])->groupBy(fn ($x) => $x['doc']->id) as $lines) {
            $doc = $lines->first()['doc'];
            $eur = 0.0;
            $rows = [];
            foreach ($lines as $x) {
                $value = (float) $x['line']->qnt * (float) $x['line']->price;
                $eur += $value;
                $rows[] = ['article' => trim(($x['line']->code ? $x['line']->code . ' ' : '') . $x['line']->description), 'qty' => Fmt::qty($x['line']->qnt) . ' ' . $x['line']->unit_desc,
                    'warehouse' => $this->wh($companyId, $x['line']->warehouse_id), 'value' => round($value, 2)];
            }

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $doc->pingwin_doc_id, entityLabel: (string) $doc->document,
                title: Fmt::title(Fmt::doc($doc->document), 'sem envio do Economato'),
                why: 'Uma Entrada de transferência põe stock no restaurante. Sem a Guia que o tira do Economato, o stock da empresa sobe sem compra e o Economato fica com stock a mais.',
                suggestion: 'Se a mercadoria veio do Economato, faça a Guia correspondente (ou anule esta Entrada e envie em Restauração › Transferências). Se foi uma compra, registe-a como compra ao fornecedor.',
                evidence: ['table' => ['title' => 'Linhas sem envio', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'], ['key' => 'warehouse', 'label' => 'Entrou em'],
                    ['key' => 'value', 'label' => 'Valor da Entrada', 'align' => 'end', 'format' => 'eur'],
                ], 'rows' => $rows]],
                signature: (string) $doc->pingwin_doc_id,
                impactCents: $eur > 0 ? (int) round($eur * 100) : null,
                severity: self::severityFor($eur),
                fixUrl: self::transfersUrl('envios'), fixLabel: 'Abrir as transferências',
                seen: sprintf('A %s, de %s, tem %d %s sem nenhuma Guia do mesmo artigo nos %d dias antes.', $doc->document, Fmt::date((string) $doc->doc_date),
                    $lines->count(), $lines->count() === 1 ? 'linha' : 'linhas', self::PAIR_DAYS),
                stake: sprintf('%s: o valor das linhas da Entrada.', Fmt::eur($eur)),
            );
        }
    }
}
