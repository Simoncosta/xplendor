<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com ingredientes sem preço. */
final class ComponenteSemCustoRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_componente_sem_custo';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function title(): string
    {
        return 'Fichas técnicas com ingredientes sem preço';
    }

    public function description(): string
    {
        return 'Fichas que usam ingredientes sem compras nem custo no PingWin.';
    }

    protected function types(): array
    {
        return ['no_cost', 'cycle', 'depth'];
    }

    /** Os que têm um parecido comprado ficam na regra "Fichas que usam um artigo que já não se compra". */
    protected function keep(array $warning, array $all): bool
    {
        foreach ($all as $w) {
            if ($w['type'] === 'similar' && ($w['component_id'] ?? null) === ($warning['component_id'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function texts(array $c): array
    {
        $semi = str_contains((string) ($c['warnings'][0]['text'] ?? ''), 'semi-produto');

        return [
            sprintf('%s sem preço de compra', $c['comp']),
            $semi
                ? sprintf('A ficha de %s (%s) usa %s, um preparado da cozinha com ingredientes sem preço.', $c['dish'], $c['version'], $c['compLabel'])
                : sprintf('A ficha de %s (%s) usa %s, que não tem compras nem custo no PingWin.', $c['dish'], $c['version'], $c['compLabel']),
            'Sem preço, o custo da ficha fica incompleto e o food cost parece mais baixo do que é.',
            $semi
                ? sprintf('Abra a ficha de %s e veja que ingredientes não têm preço. Troque-os pelo artigo que compra, ou registe a primeira compra. Se o preparado já não se faz, tire-o da ficha.', $c['comp'])
                : 'Confirme se o ingrediente está certo. Se for o artigo errado, troque-o na ficha. Se ainda não houve compras, ignore com esse motivo até chegar a primeira fatura.',
        ];
    }
}
