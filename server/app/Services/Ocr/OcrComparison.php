<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Comparação dos modelos do OCR: as medidas, sem rede. Uma leitura (o resultado sanitizado do
 * InvoiceOcrService, em cêntimos) mede-se contra a revisão gravada (a verdade), campo a campo e
 * linha a linha. Sem revisão, só se apontam os campos em que os modelos discordam.
 */
final class OcrComparison
{
    public const FIELDS = ['nif' => 'NIF', 'numero' => 'Número', 'data' => 'Data', 'total' => 'Total', 'base' => 'Base tributável', 'iva' => 'IVA total', 'iva_por_taxa' => 'IVA por taxa'];
    public const LINE_FIELDS = ['descricao' => 'Descrição', 'quantidade' => 'Quantidade', 'preco' => 'Preço unitário', 'taxa' => 'Taxa de IVA', 'total' => 'Total da linha'];

    /** A verdade a partir de uma fatura com a revisão gravada. */
    public static function truthFrom(\App\Models\OcrInvoice $invoice): array
    {
        $s = $invoice->summary;

        return [
            'supplier_nif' => $invoice->supplier_nif, 'number' => $invoice->number,
            'issue_date' => $invoice->issue_date ? substr((string) $invoice->issue_date, 0, 10) : null,
            'summary' => [
                'total_cents' => (int) ($s->total_cents ?? 0), 'taxable_base_cents' => (int) ($s->taxable_base_cents ?? 0),
                'vat_total_cents' => (int) ($s->vat_total_cents ?? 0), 'vat_breakdown' => (array) ($s->vat_breakdown ?? []),
            ],
            'lines' => $invoice->lines()->orderBy('position')->get()->map(fn ($l) => [
                'item' => $l->item, 'quantity' => $l->quantity !== null ? (float) $l->quantity : null,
                'unit_price' => $l->unit_price !== null ? (float) $l->unit_price : null,
                'vat_rate' => $l->vat_rate !== null ? (int) $l->vat_rate : null, 'line_total_cents' => $l->line_total_cents !== null ? (int) $l->line_total_cents : null,
            ])->all(),
        ];
    }

    /** @return array<string, bool> campo => certo */
    public static function fields(array $truth, array $read): array
    {
        $t = $truth['summary'] ?? [];
        $r = $read['summary'] ?? [];

        return [
            'nif' => self::digits($truth['supplier_nif'] ?? null) !== '' && self::digits($truth['supplier_nif'] ?? null) === self::digits($read['supplier_nif'] ?? null),
            'numero' => self::norm($truth['number'] ?? null) !== '' && self::norm($truth['number'] ?? null) === self::norm($read['number'] ?? null),
            'data' => ($truth['issue_date'] ?? null) !== null && ($truth['issue_date'] ?? null) === ($read['issue_date'] ?? null),
            'total' => self::cents($t['total_cents'] ?? null, $r['total_cents'] ?? null),
            'base' => self::cents($t['taxable_base_cents'] ?? null, $r['taxable_base_cents'] ?? null),
            'iva' => self::cents($t['vat_total_cents'] ?? null, $r['vat_total_cents'] ?? null),
            'iva_por_taxa' => self::byRate($t['vat_breakdown'] ?? []) !== [] && self::byRateEqual(self::byRate($t['vat_breakdown'] ?? []), self::byRate($r['vat_breakdown'] ?? [])),
        ];
    }

    /**
     * Linha a linha: cada linha da verdade emparelha com a leitura mais parecida (descrição e
     * total); conta os campos certos das emparelhadas e as que faltam ou sobram.
     *
     * @return array{verdade: int, lidas: int, emparelhadas: int, campos: array<string, int>, em_falta: int, a_mais: int}
     */
    public static function lines(array $truthLines, array $readLines): array
    {
        $free = array_values($readLines);
        $fields = array_fill_keys(array_keys(self::LINE_FIELDS), 0);
        $matched = 0;
        foreach ($truthLines as $t) {
            $bestI = null;
            $bestScore = 0.0;
            foreach ($free as $i => $r) {
                $score = self::similarity($t['item'] ?? '', $r['item'] ?? '') + (self::cents($t['line_total_cents'] ?? null, $r['line_total_cents'] ?? null) ? 50 : 0);
                if ($score > $bestScore) {
                    [$bestI, $bestScore] = [$i, $score];
                }
            }
            if ($bestI === null || $bestScore < 40) {
                continue;
            }
            $r = $free[$bestI];
            unset($free[$bestI]);
            $matched++;
            $fields['descricao'] += self::similarity($t['item'] ?? '', $r['item'] ?? '') >= 80 ? 1 : 0;
            $fields['quantidade'] += self::close($t['quantity'] ?? null, $r['quantity'] ?? null, 0.001) ? 1 : 0;
            $fields['preco'] += self::priceClose($t['unit_price'] ?? null, $r['unit_price'] ?? null) ? 1 : 0;
            $fields['taxa'] += ($t['vat_rate'] ?? null) !== null && (int) ($t['vat_rate']) === (int) ($r['vat_rate'] ?? -1) ? 1 : 0;
            $fields['total'] += self::cents($t['line_total_cents'] ?? null, $r['line_total_cents'] ?? null) ? 1 : 0;
        }

        return ['verdade' => count($truthLines), 'lidas' => count($readLines), 'emparelhadas' => $matched, 'campos' => $fields,
            'em_falta' => count($truthLines) - $matched, 'a_mais' => count($free)];
    }

    /**
     * Sem revisão: os campos em que as leituras discordam (o valor de cada modelo).
     *
     * @param array<string, array> $reads modelo => leitura
     * @return array<string, array<string, string>> campo => [modelo => valor]
     */
    public static function disagreements(array $reads): array
    {
        $values = [];
        foreach ($reads as $model => $r) {
            $s = $r['summary'] ?? [];
            $lines = (array) ($r['lines'] ?? []);
            $values[$model] = [
                'NIF' => self::digits($r['supplier_nif'] ?? null) ?: '(vazio)',
                'Número' => (string) ($r['number'] ?? '(vazio)'),
                'Data' => (string) ($r['issue_date'] ?? '(vazio)'),
                'Total' => self::eur($s['total_cents'] ?? null),
                'Base tributável' => self::eur($s['taxable_base_cents'] ?? null),
                'IVA total' => self::eur($s['vat_total_cents'] ?? null),
                'IVA por taxa' => implode('; ', array_map(fn ($rate, $v) => "{$rate}%: " . self::eur($v['base']) . ' + ' . self::eur($v['vat']), array_keys(self::byRate($s['vat_breakdown'] ?? [])), self::byRate($s['vat_breakdown'] ?? []))) ?: '(vazio)',
                'Número de linhas' => (string) count($lines),
                'Soma das linhas' => self::eur(array_sum(array_map(fn ($l) => (int) ($l['line_total_cents'] ?? 0), $lines))),
            ];
        }
        $out = [];
        foreach (array_keys(reset($values) ?: []) as $field) {
            $column = array_column(array_map(fn ($v) => [$field => $v[$field]], $values), $field);
            if (count(array_unique($column)) > 1) {
                $out[$field] = array_combine(array_keys($values), $column);
            }
        }

        return $out;
    }

    private static function byRate(array $breakdown): array
    {
        $out = [];
        foreach ($breakdown as $b) {
            if (isset($b['rate'])) {
                $out[(int) $b['rate']] = ['base' => (int) ($b['base_cents'] ?? 0), 'vat' => (int) ($b['vat_cents'] ?? 0)];
            }
        }
        ksort($out);

        return $out;
    }

    private static function byRateEqual(array $a, array $b): bool
    {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $rate => $v) {
            if (abs($v['base'] - $b[$rate]['base']) > 1 || abs($v['vat'] - $b[$rate]['vat']) > 1) {
                return false;
            }
        }

        return true;
    }

    private static function cents(?int $a, ?int $b): bool
    {
        return $a !== null && $b !== null && abs($a - $b) <= 1;
    }

    private static function close(?float $a, ?float $b, float $tol): bool
    {
        return $a !== null && $b !== null && abs($a - $b) <= $tol;
    }

    private static function priceClose(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return abs((float) $a - (float) $b) <= max(0.005, abs((float) $a) * 0.005);
    }

    private static function similarity(?string $a, ?string $b): float
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);

        return (float) $pct;
    }

    private static function norm(?string $v): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $v)) ?? '');
    }

    private static function digits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    private static function eur(?int $cents): string
    {
        return $cents === null ? '(vazio)' : number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
