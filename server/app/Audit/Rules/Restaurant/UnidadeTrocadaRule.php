<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Regra 2 · Provável unidade trocada (alta): uma linha de compra cujo preço por unidade base está
 * fora do normal numa razão perto do tamanho de uma embalagem (6, 12, 20, 24, 28, 30, 50, ou uma
 * embalagem que o artigo usa; ±10%). Ex.: PEDRAS TR, 96 «Unidade» a 13,34 € (o preço da Grade 24).
 * O impacto fica "a confirmar" (não entra na soma do Pareto) até se saber qual é a unidade certa,
 * salvo quando a fatura lida pelo OCR para esse documento não bate com o total no PingWin: aí a
 * diferença é dinheiro em jogo. Sugestão: "Confirmar fator".
 */
final class UnidadeTrocadaRule extends RestaurantRule
{
    public function code(): string
    {
        return 'restauracao.unidade_trocada';
    }

    public function entityType(): string
    {
        return 'linha_compra';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function title(): string
    {
        return 'Compras com a unidade provavelmente trocada';
    }

    public function description(): string
    {
        return 'Linhas de compra em que o preço foge do habitual tantas vezes quantas as unidades de uma embalagem (6, 12, 24...).';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach (PriceOutliers::for($scope) as $o) {
            if ($o['pack'] === null) {
                continue;
            }
            $l = $o['line'];
            $ocr = $o['ocr'] && $o['ocr']['diff'] > 5 ? $o['ocr'] : null;
            $stake = $ocr
                ? sprintf('%s: a diferença entre o total desta fatura no PingWin (%s) e o total lido pelo OCR na fatura em papel (%s).',
                    Fmt::cents($ocr['diff']), Fmt::cents($ocr['pingwin_total']), Fmt::cents($ocr['ocr_total']))
                : sprintf('A confirmar: só se sabe depois de ver a fatura em papel. Se o preço certo for o habitual, a linha está %s %s do que devia.',
                    Fmt::cents($o['estimate']), $o['ratio'] >= 1 ? 'acima' : 'abaixo');
            $facts = PriceOutliers::facts($o);
            $facts[] = ['label' => 'Unidades por embalagem', 'value' => Fmt::qty($o['pack']['factor']) . ($o['pack']['unit'] && ! str_starts_with($o['pack']['unit'], 'embalagem de') ? " («{$o['pack']['unit']}»)" : '')];
            $facts[] = $ocr
                ? ['label' => 'Diferença face à fatura em papel (OCR)', 'value' => $ocr['diff'] / 100, 'format' => 'eur']
                : ['label' => 'Em jogo, a confirmar', 'value' => $o['estimate'] / 100, 'format' => 'eur'];

            yield new AuditFindingData(
                entityType: 'linha_compra', entityId: (string) $l['id'], entityLabel: "{$l['document']}: {$o['description']}",
                title: Fmt::title($o['description'], 'unidade da compra provavelmente trocada'),
                why: sprintf('Se a unidade estiver trocada, o stock fica com %s vezes mais ou menos do que entrou, e o custo das fichas fica errado.', Fmt::qty($o['pack']['factor'])),
                suggestion: sprintf('Veja a fatura em papel e confirme a unidade e o preço desta linha. Se a linha estiver errada, corrija a %s no PingWin. Se estiver certa, indique em «Corrigir» quantas unidades tem a embalagem.',
                    Fmt::doc($l['document'])),
                evidence: ['facts' => $facts, 'table' => PriceOutliers::othersTable($o)],
                signature: "{$l['price']}|{$l['qnt']}|{$l['total']}" . ($ocr ? "|ocr:{$ocr['diff']}" : ''),
                impactCents: $ocr ? $ocr['diff'] : null,
                fixLabel: 'Confirmar a embalagem',
                fixAction: ['type' => 'confirmar_fator', 'product_id' => $o['pid'], 'unit_key' => (string) ($l['unit_key'] ?? $l['unit_id']), 'description' => $o['description']],
                refs: PriceOutliers::refs($o),
                impactPendingCents: $ocr ? null : $o['estimate'],
                seen: PriceOutliers::seen($o),
                stake: $stake,
            );
        }
    }
}
