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

    /**
     * Aceitação total ou parcial de uma versão congelada. O cliente só pode deixar de
     * fora linhas OPCIONAIS; as restantes entram sempre. O desconto de pacote:
     *  · se alguma linha do pacote ficar de fora, deixa de se aplicar (com o motivo);
     *  · em % aplica-se ao que ficou; em € só se o total a que se aplica ainda tiver
     *    linhas e não ficar abaixo do desconto (senão sai, com o motivo).
     * Fonte única: o servidor recalcula sempre; o browser só mostra.
     *
     * @param  array  $snapshot  QuoteSnapshot congelado da versão
     * @param  array<int|string>  $includedOptionalKeys  chaves das linhas opcionais que o cliente manteve
     * @return array{lines: array, accepted_keys: array<int>, excluded_keys: array<int>, buckets: array, discount: array}
     */
    public static function computeSelection(array $snapshot, array $includedOptionalKeys): array
    {
        $lines = self::keyedLines($snapshot);
        $optionalKeys = array_keys(array_filter($lines, fn ($l) => $l['is_optional']));
        $included = array_values(array_unique(array_map('intval', $includedOptionalKeys)));
        if (array_diff($included, $optionalKeys) !== []) {
            throw ValidationException::withMessages(['lines' => ['Só as linhas opcionais podem ser escolhidas.']]);
        }

        $selected = array_filter($lines, fn ($l) => ! $l['is_optional'] || in_array($l['key'], $included, true));
        $excluded = array_diff_key($lines, $selected);
        if ($selected === []) {
            throw ValidationException::withMessages(['lines' => ['Escolha pelo menos um serviço.']]);
        }

        $calc = self::compute(array_values($selected));
        $buckets = $calc['buckets'];

        $g = $snapshot['global_discount'] ?? [];
        $label = ($g['label'] ?? null) ?: 'Desconto de pacote';
        $discount = ['label' => $label, 'type' => $g['type'] ?? null, 'value' => $g['value'] ?? null, 'target' => $g['target'] ?? null,
            'applies' => false, 'reason' => null];
        $type = $g['type'] ?? null;
        $value = (float) ($g['value'] ?? 0);
        if ($type !== null && $value > 0) {
            $missing = array_values(array_filter($excluded, fn ($l) => $l['in_package']));
            if ($missing !== []) {
                $names = array_map(fn ($l) => $l['name'], $missing);
                $discount['reason'] = count($names) === 1
                    ? "{$label}: deixa de se aplicar porque o serviço {$names[0]} não foi incluído."
                    : "{$label}: deixa de se aplicar porque os serviços " . self::joinNames($names) . ' não foram incluídos.';
            } elseif ($type === 'percent') {
                foreach ($buckets as $key => $b) {
                    $buckets[$key]['discount'] = round($b['subtotal'] * min($value, 100) / 100, 2);
                }
                $discount['applies'] = true;
            } elseif ($type === 'amount') {
                $target = in_array($g['target'] ?? null, ['monthly', 'one_off'], true) ? $g['target'] : null;
                $targetLabel = $target === 'monthly' ? 'serviços mensais' : 'serviços de valor único';
                if ($target === null || $buckets[$target]['count'] === 0) {
                    $discount['reason'] = "{$label}: aplica-se aos {$targetLabel}, que não foram incluídos.";
                } elseif ($value > $buckets[$target]['subtotal']) {
                    $discount['reason'] = "{$label}: deixa de se aplicar porque o total dos {$targetLabel} ficou abaixo do valor do desconto.";
                } else {
                    $buckets[$target]['discount'] = round($value, 2);
                    $discount['applies'] = true;
                }
            }
        }
        foreach ($buckets as $key => $b) {
            $buckets[$key]['total'] = max(0.0, round($b['subtotal'] - $b['discount'], 2));
        }

        return [
            'lines' => $calc['lines'],
            'accepted_keys' => array_values(array_map(fn ($l) => $l['key'], $selected)),
            'excluded_keys' => array_values(array_map(fn ($l) => $l['key'], $excluded)),
            'buckets' => $buckets,
            'discount' => $discount,
        ];
    }

    /**
     * Linhas da versão congelada indexadas pela chave. Versões antigas (sem chave nem
     * marcas) ficam com a posição como chave e todas as linhas obrigatórias.
     */
    public static function keyedLines(array $snapshot): array
    {
        $out = [];
        foreach (array_values($snapshot['lines'] ?? []) as $i => $l) {
            $key = (int) ($l['key'] ?? $i);
            $out[$key] = [
                'key' => $key,
                'name' => $l['name'],
                'description' => $l['description'] ?? null,
                'unit' => $l['unit'],
                'billing_type' => $l['billing_type'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'discount_type' => $l['discount_type'] ?? null,
                'discount_value' => $l['discount_value'] ?? null,
                'catalog_item_id' => $l['catalog_item_id'] ?? null,
                'is_optional' => (bool) ($l['is_optional'] ?? false),
                'in_package' => (bool) ($l['in_package'] ?? false),
            ];
        }

        return $out;
    }

    private static function joinNames(array $names): string
    {
        $last = array_pop($names);

        return implode(', ', $names) . ' e ' . $last;
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
