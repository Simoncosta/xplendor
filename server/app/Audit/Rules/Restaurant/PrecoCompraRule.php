<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Regra 2 · Preço de compra fora do normal (alta): uma linha de compra (180 dias) com o preço por
 * unidade base acima de 4× ou abaixo de ¼ da mediana do artigo, quando a razão NÃO se explica pelo
 * tamanho de uma embalagem (essas vão para "Provável unidade trocada"). Impacto: |total da linha −
 * quantidade × mediana|. "Corrigir" abre o documento.
 */
final class PrecoCompraRule extends RestaurantRule
{
    public function code(): string
    {
        return 'restauracao.preco_compra';
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
        return 'Compras muito acima ou abaixo do preço habitual';
    }

    public function description(): string
    {
        return 'Linhas de compra mais de 4 vezes acima ou abaixo do preço habitual do artigo, que uma embalagem não explica.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach (PriceOutliers::for($scope) as $o) {
            if ($o['pack'] !== null) {
                continue;
            }
            $l = $o['line'];

            yield new AuditFindingData(
                entityType: 'linha_compra', entityId: (string) $l['id'], entityLabel: "{$l['document']}: {$o['description']}",
                title: Fmt::title($o['description'], $o['ratio'] >= 1 ? 'comprado muito acima do preço habitual' : 'comprado muito abaixo do preço habitual'),
                why: 'O custo das fichas técnicas e o valor do stock usam este preço. Um preço errado distorce o food cost dos pratos que levam este artigo.',
                suggestion: 'Abra o documento e compare a quantidade, a unidade e o preço com a fatura em papel. Se estiver errado, corrija o documento no PingWin. Se o preço estiver certo (uma promoção, por exemplo), ignore com esse motivo.',
                evidence: ['facts' => PriceOutliers::facts($o), 'table' => PriceOutliers::othersTable($o)],
                signature: "{$l['price']}|{$l['qnt']}|{$l['total']}",
                impactCents: $o['estimate'],
                fixUrl: self::documentUrl($l['docheader_id']), fixLabel: 'Abrir o documento',
                refs: PriceOutliers::refs($o),
                seen: PriceOutliers::seen($o),
                stake: sprintf('%s: a diferença entre o total da linha (%s) e o que estas %s «%s» custariam ao preço habitual (%s).',
                    Fmt::cents($o['estimate']), Fmt::eur($l['total']), Fmt::qty($l['qnt']), $l['unit_label'], Fmt::eur(PriceOutliers::atUsualPrice($o))),
            );
        }
    }
}
