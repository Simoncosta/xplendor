<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * XPLENDOR — Os descontos de promoção de uma fatura ligados ao artigo (ex.: o bloco "Leve Mais
 * Pague Menos" da Makro). Puro: sem base de dados.
 *
 *  · cada desconto vai para o artigo com o mesmo código de promoção (coluna DD);
 *  · vários artigos com o mesmo código: reparte pelo valor de cada um, o resto dos cêntimos no maior;
 *  · sem par: fica "por atribuir" (a fatura não se lança);
 *  · o IVA do desconto tem de ser o do artigo (pelo código de IVA da fatura; sem código, pela taxa):
 *    se não for, fica o aviso;
 *  · nunca se calcula uma percentagem: o desconto fica em € e o líquido é bruto − desconto.
 *
 * Linhas: [{promo_code, vat_code, vat_rate, gross_total_cents, item}] (pela ordem da fatura).
 * Descontos: [{code, label, amount_cents, vat_code, vat_rate}].
 */
final class InvoiceDiscountAllocator
{
    public const ASSIGNED = 'atribuido';
    public const UNASSIGNED = 'por_atribuir';
    public const DEFAULT_LABEL = 'Desconto';

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $discounts
     * @return array{lines: list<array<string, mixed>>, discounts: list<array<string, mixed>>}
     */
    public static function allocate(array $lines, array $discounts): array
    {
        $lines = array_values($lines);
        foreach ($lines as $i => $l) {
            $lines[$i]['gross_total_cents'] = (int) ($l['gross_total_cents'] ?? 0);
            $lines[$i]['discount_cents'] = 0;
            $lines[$i]['discount_code'] = null;
            $lines[$i]['discount_label'] = null;
        }

        $out = [];
        foreach (array_values($discounts) as $d) {
            $amount = abs((int) ($d['amount_cents'] ?? 0));
            $code = self::key($d['code'] ?? null);
            $label = trim((string) ($d['label'] ?? '')) ?: self::DEFAULT_LABEL;
            $candidates = $code === null ? [] : array_keys(array_filter($lines, fn ($l) => self::key($l['promo_code'] ?? null) === $code));
            $row = ['code' => $d['code'] ?? null, 'label' => $label, 'amount_cents' => $amount, 'vat_code' => $d['vat_code'] ?? null,
                'vat_rate' => $d['vat_rate'] ?? null, 'status' => self::UNASSIGNED, 'allocations' => [], 'warning' => null];
            if ($candidates === [] || $amount === 0) {
                $out[] = $row;

                continue;
            }

            $shares = self::split($amount, array_map(fn ($i) => abs($lines[$i]['gross_total_cents']), $candidates));
            $warnings = [];
            foreach ($candidates as $k => $i) {
                $lines[$i]['discount_cents'] += $shares[$k];
                $lines[$i]['discount_code'] = (string) $d['code'];
                $lines[$i]['discount_label'] = $label;
                $row['allocations'][] = ['line' => $i, 'cents' => $shares[$k]];
                if ($w = self::vatMismatch($d, $lines[$i])) {
                    $warnings[] = $w;
                }
            }
            $row['status'] = self::ASSIGNED;
            $row['warning'] = $warnings ? mb_substr(implode(' ', array_unique($warnings)), 0, 300) : null;
            $out[] = $row;
        }

        foreach ($lines as $i => $l) {
            $lines[$i]['line_total_cents'] = $l['gross_total_cents'] - $l['discount_cents'];
        }

        return ['lines' => $lines, 'discounts' => $out];
    }

    /**
     * Reparte $amount pelos pesos (o valor de cada artigo): a parte inteira de cada um e o resto
     * dos cêntimos no maior (o primeiro, se houver empate). Sem pesos, vai tudo para o primeiro.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function split(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_map(fn ($k) => $k === 0 ? $amount : 0, array_keys($weights));
        }
        $shares = array_map(fn ($w) => intdiv($amount * $w, $total), $weights);
        $largest = array_search(max($weights), $weights, true);
        $shares[$largest] += $amount - array_sum($shares);

        return $shares;
    }

    /** O código como chave: sem espaços, maiúsculas e sem zeros à esquerda ("045238" = "45238"). */
    public static function key($v): ?string
    {
        $s = strtoupper(preg_replace('/\s+/', '', trim((string) $v)) ?? '');
        $s = ltrim($s, '0');

        return $s === '' ? null : $s;
    }

    /** O código de IVA da fatura como chave ("0" é um código válido). */
    private static function vatKey($v): ?string
    {
        $s = strtoupper(trim((string) $v));

        return $s === '' ? null : $s;
    }

    private static function vatMismatch(array $d, array $line): ?string
    {
        $dc = self::vatKey($d['vat_code'] ?? null);
        $lc = self::vatKey($line['vat_code'] ?? null);
        $item = trim((string) ($line['item'] ?? '')) ?: 'artigo';
        if ($dc !== null && $lc !== null) {
            return $dc === $lc ? null
                : sprintf('O IVA do desconto %s (código %s) não é o do artigo %s (código %s).', $d['code'], $d['vat_code'], $item, $line['vat_code']);
        }
        $dr = $d['vat_rate'] ?? null;
        $lr = $line['vat_rate'] ?? null;
        if ($dr !== null && $lr !== null && (int) $dr !== (int) $lr) {
            return sprintf('O IVA do desconto %s (%d %%) não é o do artigo %s (%d %%).', $d['code'], $dr, $item, $lr);
        }

        return null;
    }
}
