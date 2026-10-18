<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\Units\UnitProposal;

/**
 * U5 · Preço por unidade base fora do intervalo da família: abaixo de ¼ ou acima de 4 vezes a mediana
 * dos artigos da mesma família e base (pelo menos 3). O fator está provavelmente errado. € = a
 * diferença entre o que se pagou (90 dias) e a quantidade base × a mediana.
 */
final class PrecoFamiliaRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_preco_familia';
    }

    public function title(): string
    {
        return 'Preço por unidade base fora da família';
    }

    public function description(): string
    {
        return 'O preço por unidade base está abaixo de ¼ ou acima de 4 vezes o dos artigos da mesma família.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach ($this->articles($scope) as $pid => $e) {
            $ratio = $e['price_ratio'];
            if ($ratio === null || ($ratio >= 0.25 && $ratio <= 4) || $e['family_peers'] < 5 || $e['spend_90d_eur'] <= 0) {
                continue;
            }
            $qb = 0.0;
            foreach ($e['purchases'] as $p) {
                $qb += $p['base_factor'] ? $p['recent_qnt'] * $p['base_factor'] : 0;
            }
            // No máximo o que se pagou em 90 dias (o valor em jogo nunca passa do que se comprou).
            $eur = round(min($e['spend_90d_eur'], abs($e['spend_90d_eur'] - $qb * $e['family_median'])), 2);
            $unit = mb_strtolower(UnitProposal::baseLabel($e['base_family']));
            yield $this->finding($e,
                problem: sprintf('%s por %s, %s que a família', Fmt::unitPrice($e['price_per_base']), $unit, Fmt::times($ratio)),
                seen: sprintf('%s custa %s por %s. Os %d artigos de %s com a mesma base custam, a meio, %s por %s.', $e['description'], Fmt::unitPrice($e['price_per_base']),
                    $unit, $e['family_peers'], $e['family'], Fmt::unitPrice($e['family_median']), $unit),
                why: 'Uma diferença desta ordem quase nunca é o preço: é o fator da embalagem errado (uma caixa contada como uma unidade, ou o contrário). O custo das fichas sai errado na mesma proporção.',
                todo: 'Abra o Guião e confira quanto leva cada unidade de compra.',
                stake: sprintf('A confirmar: %s, a diferença entre o pago em 90 dias e a quantidade base ao preço mediano da família (no máximo, o que se pagou).', Fmt::eur($eur)),
                evidence: ['facts' => self::facts($e, [['label' => 'Preço por unidade base', 'value' => $e['price_per_base'], 'format' => 'eur4'],
                    ['label' => 'Mediana da família', 'value' => $e['family_median'], 'format' => 'eur4']]), 'table' => self::purchasesTable($e['purchases'])],
                pendingCents: (int) round($eur * 100) ?: null, severity: self::severityFor($eur), signature: (string) round($ratio, 1),
            );
        }
    }
}
