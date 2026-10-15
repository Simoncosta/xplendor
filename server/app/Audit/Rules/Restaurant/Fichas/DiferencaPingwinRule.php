<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com o custo muito diferente do PingWin. */
final class DiferencaPingwinRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_diferenca_pingwin';
    }

    public function severity(): string
    {
        return 'baixa';
    }

    public function title(): string
    {
        return 'Fichas técnicas com o custo muito diferente do PingWin';
    }

    public function description(): string
    {
        return 'O custo da ficha calculado pela XPLENDOR difere mais de 20% do custo que o PingWin calcula.';
    }

    protected function types(): array
    {
        return ['pingwin_diff'];
    }

    protected function texts(array $c): array
    {
        $pct = preg_match('/difere ([\d.,]+)%/u', (string) ($c['warnings'][0]['text'] ?? ''), $m) ? $m[1] : 'mais de 20';

        return [
            'custo muito diferente do calculado no PingWin',
            sprintf('O custo da ficha de %s (%s), calculado pela XPLENDOR a partir das compras, difere %s%% do custo que o PingWin calcula.', $c['dish'], $c['version'], $pct),
            'Uma diferença grande costuma vir de uma unidade ou de um preço de compra errado num ingrediente.',
            'Abra a ficha e veja as linhas com mais diferença: confirme as unidades e os preços de compra.',
        ];
    }
}
