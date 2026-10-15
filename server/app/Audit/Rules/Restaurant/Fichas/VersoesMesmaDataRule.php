<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com duas versões no mesmo dia. */
final class VersoesMesmaDataRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_mesma_data';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function title(): string
    {
        return 'Fichas técnicas com duas versões no mesmo dia';
    }

    public function description(): string
    {
        return 'Fichas com duas ou mais versões que começam no mesmo dia.';
    }

    protected function types(): array
    {
        return ['same_day'];
    }

    protected function texts(array $c): array
    {
        $text = (string) ($c['warnings'][0]['text'] ?? '');
        $n = preg_match('/Há (\d+) versões/', $text, $m) ? (int) $m[1] : 2;
        $empty = str_contains($text, 'não tem linhas') ? ', que não tem ingredientes' : '';

        return [
            sprintf('%d versões da ficha no mesmo dia', $n),
            sprintf('Há %d versões da ficha de %s que começam a %s. A XPLENDOR conta a criada depois%s.', $n, $c['dish'], Fmt::date($c['v']['valid_from'] ?? null), $empty),
            'Com duas versões no mesmo dia, não fica claro qual o PingWin usa nas vendas.',
            'Abra a ficha e apague a versão que sobra (menu da ficha), ou mude-lhe a data.',
        ];
    }
}
