<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com ingredientes inativos. */
final class ComponenteInativoRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_componente_inativo';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function title(): string
    {
        return 'Fichas técnicas com ingredientes inativos';
    }

    public function description(): string
    {
        return 'Fichas em vigor que usam artigos inativos ou anulados no PingWin.';
    }

    protected function types(): array
    {
        return ['inactive'];
    }

    protected function texts(array $c): array
    {
        return [
            sprintf('usa %s, que está inativo', $c['comp']),
            sprintf('A ficha de %s (%s) usa %s, que está inativo no PingWin.', $c['dish'], $c['version'], $c['compLabel']),
            'Um artigo inativo já não se compra: o custo da ficha usa um preço antigo e o stock desconta um artigo que não entra.',
            sprintf('Abra a ficha e troque %s pelo artigo que compra hoje (botão «Editar ficha»). Se o artigo ainda se usa, reative-o no PingWin.', $c['comp']),
        ];
    }
}
