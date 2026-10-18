<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\Rules\Restaurant\RestaurantAuditData;

use App\Audit\Fmt;

/** Regra 8 · Fichas técnicas com embalagem ou rendimento por confirmar. */
final class PorConfirmarRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_por_confirmar';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function title(): string
    {
        return 'Fichas técnicas com embalagem ou rendimento por confirmar';
    }

    public function description(): string
    {
        return 'Ingredientes sem a embalagem confirmada e preparados da cozinha sem o rendimento confirmado.';
    }

    protected function types(): array
    {
        return ['factor_unconfirmed', 'unit_factor', 'yield_unconfirmed', 'batch'];
    }

    /** O rendimento por confirmar numa LINHA (o preparado) fica no achado da ficha do próprio preparado. */
    protected function keep(array $warning, array $all): bool
    {
        return ! ($warning['type'] === 'yield_unconfirmed' && ($warning['component_id'] ?? null) !== null);
    }

    protected function fixUrlFor(string $pid, ?string $componentId, array $warning): string
    {
        return $componentId ? self::recipeUrl($pid, ['fator' => $componentId]) : self::recipeUrl($pid, ['rendimento' => 1]);
    }

    /**
     * Auditoria v2: a embalagem por confirmar abre o Guião nesse artigo; o rendimento por confirmar abre
     * o ConfirmYieldModal já preenchido com a evidência (a quantidade escrita na descrição, como «30LT»).
     */
    protected function fixActionFor(RestaurantAuditData $data, string $pid, ?string $component, array $v, array $warnings): ?array
    {
        if ($component) {
            return ['action' => ['type' => 'guiao', 'product_id' => $component], 'label' => 'Abrir no Guião de unidades'];
        }
        $y = $v['yield'] ?? [];
        $hint = null;
        $reason = null;
        $family = \App\Services\Restaurant\Units\UnitEvidence::fam(\App\Services\Restaurant\RecipeHints::family((string) ($y['unit_label'] ?? '')));
        foreach (array_filter([(string) ($data->item($pid)?->description ?? '')]) as $text) {
            $pack = \App\Services\Restaurant\Units\PackagingParser::parse($text);
            if ($pack && $pack['quantity'] > 0 && ($family === null || $pack['family'] === $family)) {
                $hint = $pack['quantity'];
                $reason = sprintf('A descrição diz «%s» (%s).', $pack['text'], mb_strtolower($pack['label']));
                break;
            }
        }
        if ($hint === null && ! empty($y['quantity'])) {
            $hint = (float) $y['quantity'];
            $reason = 'Os ingredientes do lote somam cerca disto.';
        }

        return ['action' => ['type' => 'confirmar_rendimento', 'product_id' => $pid, 'bom_id' => (string) ($v['bom_id'] ?? ''), 'quantity' => $hint, 'reason' => $reason],
            'label' => 'Confirmar o rendimento'];
    }

    protected function texts(array $c): array
    {
        if ($c['comp'] !== null) {
            $buy = $stock = null;
            foreach ($c['warnings'] as $w) {
                if (preg_match('/Compras em «(.+?)» e ficha em «(.+?)»/u', (string) $w['text'], $m)) {
                    [$buy, $stock] = [$m[1], $m[2]];
                }
            }
            $seen = $buy
                ? sprintf('A ficha de %s (%s) usa %s em %s, mas compra-se em «%s». Ainda ninguém confirmou %s tem cada «%s».',
                    $c['dish'], $c['version'], $c['comp'], mb_strtolower($stock), $buy, Fmt::howMany($stock), $buy)
                : sprintf('A ficha de %s (%s) usa %s, mas ainda ninguém confirmou quantas unidades tem a embalagem em que se compra.', $c['dish'], $c['version'], $c['comp']);

            return [
                sprintf('falta confirmar a embalagem de %s', $c['comp']),
                $seen,
                'Até confirmar, o custo desta linha da ficha é pouco fiável.',
                $buy
                    ? sprintf('Abra a ficha e, na linha de %s, indique %s tem cada «%s». Fica só na XPLENDOR; o PingWin não muda.', $c['comp'], Fmt::howMany($stock), $buy)
                    : sprintf('Abra a ficha e, na linha de %s, indique quantas unidades tem a embalagem. Fica só na XPLENDOR; o PingWin não muda.', $c['comp']),
            ];
        }
        $y = $c['v']['yield'] ?? null;
        $unit = (string) ($y['unit_label'] ?? 'unidade');
        $sums = implode(' e ', array_map(fn ($x) => Fmt::units($x['quantity'], (string) $x['label'], 2), (array) ($y['sums'] ?? [])));
        $seen = ! empty($y['looks_like_batch'])
            ? sprintf('A ficha de %s, um preparado da cozinha, parece ser de um lote. Os ingredientes somam %s%s. O PingWin conta-a como se rendesse 1 %s.',
                $c['dish'], $sums, ! empty($y['quantity']) ? sprintf(' (cerca de %s)', Fmt::units($y['quantity'], $unit, 2)) : '', mb_strtolower($unit))
            : sprintf('Ainda ninguém confirmou quanto rende a ficha de %s, um preparado da cozinha: o custo por %s conta como se rendesse 1 %s.', $c['dish'], mb_strtolower($unit), mb_strtolower($unit));

        return [
            'falta confirmar quanto rende o lote',
            $seen,
            'Até confirmar quanto rende o lote, o custo deste preparado, e o dos pratos que o usam, é pouco fiável.',
            'Abra a ficha do preparado e confirme quanto rende o lote (botão «Confirmar»). Fica só na XPLENDOR; o PingWin não muda.',
        ];
    }
}
