<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use Illuminate\Validation\ValidationException;

/**
 * Fonte única dos cálculos de um orçamento. Sem IVA (os preços são sem IVA e o IVA
 * nunca é calculado). Totais MENSAL e VALOR ÚNICO sempre separados: nunca há um
 * total que some os dois.
 *
 *  · linha: subtotal = quantidade × preço; desconto em % (0 a 100) ou em € (até ao
 *    subtotal); total da linha nunca abaixo de 0; arredondado a 2 casas.
 *  · desconto global (de pacote): em % aplica-se aos dois totais; em € tem de dizer a
 *    que total se aplica (monthly | one_off), esse total tem de ter linhas e o
 *    desconto não pode passar o subtotal desse total.
 */
class QuoteCalculator
{
    /**
     * @param  array<int, array{quantity: mixed, unit_price: mixed, billing_type: string, discount_type?: ?string, discount_value?: mixed}>  $lines
     * @param  array{type?: ?string, value?: mixed, target?: ?string}  $global
     * @return array{lines: array<int, array>, buckets: array<string, array{subtotal: float, discount: float, total: float, count: int}>}
     */
    public static function compute(array $lines, array $global = []): array
    {
        $buckets = [
            'monthly' => ['subtotal' => 0.0, 'discount' => 0.0, 'total' => 0.0, 'count' => 0],
            'one_off' => ['subtotal' => 0.0, 'discount' => 0.0, 'total' => 0.0, 'count' => 0],
        ];
        $errors = [];

        foreach ($lines as $i => $line) {
            $subtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
            $discount = self::discount($line['discount_type'] ?? null, $line['discount_value'] ?? null, $subtotal, "lines.$i.discount_value", $errors);
            $lineTotal = max(0.0, round($subtotal - $discount, 2));
            $lines[$i]['line_subtotal'] = $subtotal;
            $lines[$i]['line_discount'] = $discount;
            $lines[$i]['line_total'] = $lineTotal;

            $bucket = $line['billing_type'] === 'monthly' ? 'monthly' : 'one_off';
            $buckets[$bucket]['subtotal'] = round($buckets[$bucket]['subtotal'] + $lineTotal, 2);
            $buckets[$bucket]['count']++;
        }

        $type = $global['type'] ?? null;
        $value = $global['value'] ?? null;
        if ($type !== null && $value !== null && (float) $value > 0) {
            if ($type === 'percent') {
                if ((float) $value > 100) {
                    $errors['global_discount_value'] = 'O desconto global em percentagem não pode passar 100%.';
                } else {
                    foreach ($buckets as $key => $b) {
                        $buckets[$key]['discount'] = round($b['subtotal'] * (float) $value / 100, 2);
                    }
                }
            } elseif ($type === 'amount') {
                $target = $global['target'] ?? null;
                if (! in_array($target, ['monthly', 'one_off'], true)) {
                    $errors['global_discount_target'] = 'Indique a que total se aplica o desconto em euros: mensal ou valor único.';
                } elseif ($buckets[$target]['count'] === 0) {
                    $errors['global_discount_target'] = 'O desconto em euros aplica-se a um total sem linhas.';
                } elseif ((float) $value > $buckets[$target]['subtotal']) {
                    $errors['global_discount_value'] = 'O desconto global não pode ser maior do que o total a que se aplica.';
                } else {
                    $buckets[$target]['discount'] = round((float) $value, 2);
                }
            } else {
                $errors['global_discount_type'] = 'Tipo de desconto inválido.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($buckets as $key => $b) {
            $buckets[$key]['total'] = max(0.0, round($b['subtotal'] - $b['discount'], 2));
        }

        return ['lines' => $lines, 'buckets' => $buckets];
    }

    private static function discount(?string $type, mixed $value, float $subtotal, string $field, array &$errors): float
    {
        if ($type === null || $value === null || (float) $value <= 0) {
            return 0.0;
        }
        if ($type === 'percent') {
            if ((float) $value > 100) {
                $errors[$field] = 'O desconto em percentagem não pode passar 100%.';

                return 0.0;
            }

            return round($subtotal * (float) $value / 100, 2);
        }
        if ($type === 'amount') {
            if ((float) $value > $subtotal) {
                $errors[$field] = 'O desconto da linha não pode ser maior do que o valor da linha.';

                return 0.0;
            }

            return round((float) $value, 2);
        }
        $errors[$field] = 'Tipo de desconto inválido.';

        return 0.0;
    }
}
