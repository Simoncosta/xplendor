<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\StockUnits;

/**
 * Fatia 7c · T2 · Entrada no restaurante com um custo diferente do PCM do Economato (mais de 5%): o
 * custo de cada unidade de stock da Entrada (o preço da linha ÷ o fator da embalagem) comparado com o
 * PCM no Economato. Apanha o erro do PingWin na conversão: numa linha em Grade 24, o preço vem o de UMA
 * unidade (EA BOEA/1 de 15/10). Valor: as unidades × a diferença de custo.
 */
final class EntradaCustoRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_custo_entrada';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Entradas nos restaurantes com o custo errado';
    }

    public function description(): string
    {
        return 'Entradas em que o custo de cada unidade se afasta do preço médio do Economato.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $gap = (float) config('auditoria.transfers_cost_gap_pct', 5);
        $units = new StockUnits($companyId);
        foreach ($d['entries'] as $doc) {
            $rows = [];
            $eur = 0.0;
            foreach ($doc->lines as $l) {
                if ((float) $l->qnt == 0.0) {
                    continue;
                }
                $pcm = self::originPcm($d, (string) $l->product_id);
                $base = self::base($units, $l);
                if ($pcm === null || $base <= 0) {
                    continue;
                }
                $cost = (float) $l->qnt * (float) $l->price / $base;
                $diff = ($cost - $pcm) / $pcm * 100;
                if (abs($diff) <= $gap) {
                    continue;
                }
                $eur += $base * abs($pcm - $cost);
                $rows[] = ['article' => trim(($l->code ? $l->code . ' ' : '') . $l->description), 'qty' => Fmt::qty($l->qnt) . ' ' . $l->unit_desc,
                    'cost' => round($cost, 4), 'pcm' => round($pcm, 4), 'diff' => Fmt::pct($diff, 1), 'value' => round($base * abs($pcm - $cost), 2)];
            }
            if (! $rows) {
                continue;
            }
            $first = $rows[0];

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $doc->pingwin_doc_id, entityLabel: (string) $doc->document,
                title: Fmt::title(Fmt::doc($doc->document), 'entrou com o custo errado'),
                why: 'O custo da Entrada passa a ser o custo do restaurante (PCM do armazém). Se não for o do Economato, as fichas técnicas e a margem do restaurante ficam erradas. Na conversão de uma Guia, o PingWin põe numa linha em embalagem (Grade 24) o preço de uma só unidade.',
                suggestion: 'Corrija o preço da linha no PingWin para o PCM do Economato × o fator da embalagem. Os envios feitos em Restauração › Transferências escrevem sempre este preço.',
                evidence: ['table' => ['title' => 'Linhas com o custo afastado', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'],
                    ['key' => 'cost', 'label' => 'Custo por unidade', 'align' => 'end', 'format' => 'eur4'], ['key' => 'pcm', 'label' => 'PCM no Economato', 'align' => 'end', 'format' => 'eur4'],
                    ['key' => 'diff', 'label' => 'Diferença', 'align' => 'end'], ['key' => 'value', 'label' => 'Valor', 'align' => 'end', 'format' => 'eur'],
                ], 'rows' => $rows]],
                signature: (string) $doc->pingwin_doc_id,
                impactCents: (int) round($eur * 100),
                severity: self::severityFor($eur),
                fixUrl: self::transfersUrl('envios'), fixLabel: 'Abrir as transferências',
                refs: [['armazem', (string) ($doc->lines->first()->warehouse_id ?? '')]],
                seen: sprintf('Na %s, de %s, %s entrou a %s por unidade; o PCM no Economato é %s (%s).', $doc->document, Fmt::date((string) $doc->doc_date),
                    $first['article'], Fmt::unitPrice($first['cost']), Fmt::unitPrice($first['pcm']), $first['diff']) . (count($rows) > 1 ? sprintf(' Mais %d %s.', count($rows) - 1, count($rows) === 2 ? 'linha' : 'linhas') : ''),
                stake: sprintf('%s: as unidades recebidas × a diferença entre o custo da Entrada e o PCM do Economato.', Fmt::eur($eur)),
            );
        }
    }
}
