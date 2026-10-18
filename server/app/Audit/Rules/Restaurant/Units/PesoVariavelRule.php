<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * U3 · Comprado ao kg e usado em frações de Unidade nas fichas (o frango a ⅓): o peso de cada peça
 * varia, por isso a ficha em Unidade não diz quanto se gasta. Sugere a base kg com a unidade de
 * contagem "Peça ≈ X kg". € a confirmar: o custo gasto nas fichas em 90 dias.
 */
final class PesoVariavelRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_peso_variavel';
    }

    public function title(): string
    {
        return 'Comprado ao kg, usado à peça nas fichas';
    }

    public function description(): string
    {
        return 'Artigos comprados ao quilo que as fichas usam em frações de Unidade (peso variável).';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach ($this->articles($scope) as $pid => $e) {
            $families = array_values(array_unique(array_filter(array_map(fn ($p) => $p['unit_family'], $e['purchases']))));
            $pieces = array_filter($e['recipes'], fn ($r) => $r['unit_family'] === 'un' && $r['qnt'] > 0 && abs($r['qnt'] - round($r['qnt'])) > 1e-6);
            if ($families !== ['kg'] || ! $pieces || $e['has_recipe']) {
                continue;
            }
            $price = $e['price_per_base'];
            $eur = self::recipeConsumptionEur($e, $price);
            yield $this->finding($e,
                problem: 'comprado ao kg, usado à peça',
                seen: sprintf('%s compra-se ao quilo, mas %d %s usam-no em frações de Unidade (%s).', $e['description'], count($pieces),
                    count($pieces) === 1 ? 'ficha' : 'fichas', implode('; ', array_map(fn ($r) => Fmt::qty($r['qnt'], 3) . ' em «' . $r['label'] . '»', array_slice(array_values($pieces), 0, 3)))),
                why: 'Uma peça não pesa sempre o mesmo. Com a ficha em Unidade, o custo da porção depende de um peso que ninguém registou, e a contagem não bate com as compras.',
                todo: 'Abra o Guião: a proposta é a base kg, com a contagem em «Peça ≈ X kg». Indique o peso médio de uma peça.',
                stake: $eur !== null ? sprintf('A confirmar: %s gastos nas fichas em 90 dias.', Fmt::eur($eur)) : 'Sem custo conhecido: a relevância são as compras ao kg.',
                evidence: ['facts' => self::facts($e), 'table' => self::purchasesTable($e['purchases'])],
                pendingCents: $eur ? (int) round($eur * 100) : null, severity: 'media',
            );
        }
    }
}
