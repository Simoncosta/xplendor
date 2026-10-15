<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com artigos que já não se compram. */
final class ParecidoCompradoRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_parecido_comprado';
    }

    public function severity(): string
    {
        return 'baixa';
    }

    public function title(): string
    {
        return 'Fichas técnicas com artigos que já não se compram';
    }

    public function description(): string
    {
        return 'Ingredientes sem compras quando se compra um artigo com o mesmo nome.';
    }

    protected function types(): array
    {
        return ['similar'];
    }

    protected function texts(array $c): array
    {
        $text = (string) ($c['warnings'][0]['text'] ?? '');
        $similar = preg_match('/parecido:\s*(.+?)\.?$/u', $text, $m) ? trim($m[1]) : 'um artigo com o mesmo nome';

        return [
            'usa um artigo que já não se compra',
            sprintf('A ficha de %s (%s) usa %s, que não tem compras. Compra-se um artigo parecido: %s.', $c['dish'], $c['version'], $c['compLabel'], $similar),
            'As compras vão para o artigo parecido: a ficha fica sem custo e o stock desconta o artigo errado.',
            'Abra a ficha e troque o ingrediente pelo artigo que compra (botão «Editar ficha»). Se forem o mesmo produto, escolha também o artigo principal no grupo de artigos parecidos.',
        ];
    }
}
