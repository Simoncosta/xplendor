<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * U4 · O mesmo artigo comprado em unidades diferentes por fornecedores diferentes, sem conversão
 * confirmada para alguma delas. € a confirmar: as compras (90 dias) nas unidades sem fator.
 */
final class FornecedoresUnidadesRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_fornecedores';
    }

    public function title(): string
    {
        return 'Fornecedores em unidades diferentes, sem conversão';
    }

    public function description(): string
    {
        return 'O mesmo artigo comprado em unidades diferentes por fornecedores diferentes, sem fator confirmado.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach ($this->articles($scope) as $pid => $e) {
            $suppliers = array_unique(array_map(fn ($p) => $p['nif'] ?: $p['supplier'], $e['purchases']));
            $units = array_unique(array_map(fn ($p) => $p['unit_key'], $e['purchases']));
            $unknown = array_values(array_filter($e['purchases'], fn ($p) => ! in_array($p['base_source'], ['confirmado', 'base'], true)));
            if (count($suppliers) < 2 || count($units) < 2 || ! $unknown) {
                continue;
            }
            $eur = round(array_sum(array_column($unknown, 'recent_eur')), 2);
            yield $this->finding($e,
                problem: sprintf('%d fornecedores, %d unidades, sem conversão', count($suppliers), count($units)),
                seen: sprintf('%s compra-se a %d fornecedores em %d unidades diferentes (%s). %s sem quanto leva confirmado.', $e['description'],
                    count($suppliers), count($units), implode(', ', array_map(fn ($p) => "«{$p['unit_label']}» de {$p['supplier']}", $e['purchases'])),
                    count($unknown) === 1 ? 'Uma delas está' : count($unknown) . ' delas estão'),
                why: 'Sem a conversão de cada unidade, o preço por unidade base mistura caixas com unidades, o stock não soma bem e as fichas ficam com um custo errado.',
                todo: 'Abra o Guião e diga quanto leva cada unidade de cada fornecedor.',
                stake: sprintf('A confirmar: %s comprados em 90 dias nas unidades sem conversão.', Fmt::eur($eur)),
                evidence: ['facts' => self::facts($e), 'table' => self::purchasesTable($e['purchases'])],
                pendingCents: (int) round($eur * 100) ?: null, severity: 'media', signature: implode(',', $units),
            );
        }
    }
}
