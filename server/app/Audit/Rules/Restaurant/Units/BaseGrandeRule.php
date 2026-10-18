<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\Units\UnitProposal;

/**
 * U2 · Unidade base demasiado grande para as fichas: as fichas usam menos de 5 % da unidade base
 * por porção (0,007 Unidade de café, 0,0055 Unidade de ovos), numa base "Unidade". Sugere a base que
 * o uso pede (kg, L, ou a peça). € a confirmar: o custo deste artigo gasto nas fichas em 90 dias.
 */
final class BaseGrandeRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_base_grande';
    }

    public function title(): string
    {
        return 'Unidade base grande demais para as fichas';
    }

    public function description(): string
    {
        return 'As fichas usam frações muito pequenas da unidade base (menos de 5 % por porção).';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach ($this->articles($scope) as $pid => $e) {
            $usage = UnitProposal::usage($e);
            if (! $usage['small'] || $e['has_recipe'] || $e['base_family'] !== 'un' || ! in_array('un', $usage['families'], true)) {
                continue;
            }
            $proposal = $this->proposal($scope, $e);
            $price = $this->evidence($scope)->cost()->purchaseOf($pid)['avg'] ?? $e['price_per_base'];
            $eur = self::recipeConsumptionEur($e, $price);
            $examples = array_slice(array_map(fn ($r) => sprintf('%s em «%s»', Fmt::qty($r['qnt'], 4) . ' ' . ($r['unit'] ?: 'Unidade'), $r['label']), $e['recipes']), 0, 3);
            $suggest = $proposal['identified'] ? 'a base ' . mb_strtolower((string) $proposal['base_label']) : 'uma base mais pequena';
            yield $this->finding($e,
                problem: sprintf('as fichas usam %s da unidade', Fmt::qty($usage['median'], 4)),
                seen: sprintf('%s entra em %d %s com frações muito pequenas da unidade base: %s.', $e['description'], count($e['recipes']),
                    count($e['recipes']) === 1 ? 'ficha' : 'fichas', implode('; ', $examples)),
                why: 'Com frações destas, um arredondamento ou um engano de casa decimal muda muito o custo da porção, e ninguém consegue conferir a ficha de cabeça.',
                todo: sprintf('Abra o Guião: a proposta é %s. Veja «O que muda» nas fichas antes de confirmar.', $suggest),
                stake: $eur !== null ? sprintf('A confirmar: %s gastos nas fichas em 90 dias (vendas × quantidade × custo).', Fmt::eur($eur))
                    : 'Sem custo conhecido: a relevância são as fichas e as compras deste artigo.',
                evidence: ['facts' => self::facts($e, [['label' => 'Fração por porção (mediana)', 'value' => Fmt::qty($usage['median'], 4)],
                    ['label' => 'Fichas', 'value' => count($e['recipes'])]])],
                pendingCents: $eur ? (int) round($eur * 100) : null, severity: 'media', signature: (string) $usage['median'],
            );
        }
    }
}
