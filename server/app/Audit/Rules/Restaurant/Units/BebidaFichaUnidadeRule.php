<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Services\Restaurant\RecipeCostService;

/**
 * U7 · Bebida vendida 1 para 1 cuja ficha usa outra unidade que não a de stock: um artigo vendido
 * com uma ficha de uma só linha, cujo componente é uma peça (garrafa, lata) e a linha usa uma fração
 * (0,042 Litro da grade em vez de 1 garrafa). € a confirmar: o custo do componente nas vendas de 90 dias.
 */
final class BebidaFichaUnidadeRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_bebida_ficha';
    }

    public function title(): string
    {
        return 'Bebida vendida à peça, ficha noutra unidade';
    }

    public function description(): string
    {
        return 'Bebidas vendidas uma a uma cuja ficha usa uma fração de outra unidade em vez de uma peça.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $ev = $this->evidence($scope);
        $ev->all(); // carrega as fichas (e as evidências) antes de as percorrer
        $sales = $data->sales90();
        foreach ($data->cost()->allVersions() as $productId => $versions) {
            $productId = (string) $productId;
            $v = RecipeCostService::versionInForce($versions, $data->today());
            if (! $v || count($v['lines']) !== 1 || ($sales[$productId]['qty'] ?? 0) <= 0) {
                continue;
            }
            $line = $v['lines'][0];
            $e = $ev->for((string) $line['component_id']);
            if (! $e || $e['has_recipe'] || in_array($e['profile']['state'] ?? null, ['confirmado', 'aplicado_no_pingwin', 'nao_se_aplica'], true)) {
                continue;
            }
            $proposal = $this->proposal($scope, $e);
            // Uma peça inteira da embalagem por venda (0,042 da grade de 24 = 1 garrafa), numa fração.
            $pack = $proposal['pack'];
            $pieces = $pack && $pack['count'] > 1 ? $line['qnt_base'] * $pack['count'] : 0;
            if (! in_array($proposal['type'], ['embalado_inteiro', 'contavel'], true) || $pieces < 0.75 || $pieces > 1.5
                || abs($line['qnt'] - round($line['qnt'])) < 1e-6) {
                continue;
            }
            $price = $e['price_per_base'];
            $eur = $price !== null ? round($line['qnt_base'] * ($sales[$productId]['qty'] ?? 0) * $price, 2) : null;
            yield $this->finding($e,
                problem: sprintf('a ficha de «%s» usa %s %s', $data->label($productId), Fmt::qty($line['qnt'], 3), $line['unit'] ?: ''),
                seen: sprintf('«%s» vende-se à peça (%s nos últimos 90 dias). A ficha tem uma só linha: %s %s de %s, em vez de 1 %s.', $data->label($productId),
                    Fmt::qty($sales[$productId]['qty'], 0), Fmt::qty($line['qnt'], 3), $line['unit'] ?: '', $e['description'], mb_strtolower($proposal['base_name'] ?: 'unidade')),
                why: 'Uma fração de outra unidade (a grade, o litro) arredonda mal e muda com o tamanho da embalagem; o stock da bebida nunca bate com as vendas.',
                todo: sprintf('Abra o Guião: a proposta é contar %s à %s e a ficha usar 1.', $e['description'], mb_strtolower($proposal['base_name'] ?: 'unidade')),
                stake: $eur !== null ? sprintf('A confirmar: %s de custo nas vendas de 90 dias.', Fmt::eur($eur)) : 'Sem custo conhecido: a relevância são as vendas.',
                evidence: ['facts' => self::facts($e, [['label' => 'Artigo vendido', 'value' => $data->label($productId)], ['label' => 'Linha da ficha', 'value' => Fmt::qty($line['qnt'], 4) . ' ' . ($line['unit'] ?: '')]])],
                pendingCents: $eur ? (int) round($eur * 100) : null, weightCents: (int) ($sales[$productId]['net'] ?? 0) ?: null, severity: 'baixa', key: $productId,
                refs: [['artigo', $e['product_id']], ['artigo', $productId]],
            );
        }
    }
}
