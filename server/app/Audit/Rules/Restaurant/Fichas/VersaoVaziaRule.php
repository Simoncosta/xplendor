<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas sem ingredientes. */
final class VersaoVaziaRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_versao_vazia';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function title(): string
    {
        return 'Fichas técnicas sem ingredientes';
    }

    public function description(): string
    {
        return 'Versões das fichas, em vigor ou futuras, sem nenhum ingrediente.';
    }

    protected function types(): array
    {
        return ['empty_version'];
    }

    protected function texts(array $c): array
    {
        return [
            'ficha técnica sem ingredientes',
            sprintf('A ficha de %s (%s) não tem nenhum ingrediente.', $c['dish'], $c['version']),
            'Sem ingredientes, o prato não tem custo e o PingWin não desconta stock nas vendas.',
            'Abra a ficha e acrescente os ingredientes (botão «Editar ficha»). Se a versão foi criada por engano, apague-a no menu da ficha.',
        ];
    }
}
