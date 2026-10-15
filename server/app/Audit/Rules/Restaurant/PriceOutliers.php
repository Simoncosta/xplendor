<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\RecipeCostService;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Auditoria (regra 2): as linhas de compra (180 dias) com o preço por unidade base acima
 * de 4× ou abaixo de ¼ da mediana do artigo (12 meses, pelo menos 3 linhas comparáveis, artigos de
 * preço estável). Cada linha vai para um de dois grupos:
 *  · "Provável unidade trocada": a razão está perto do tamanho de uma embalagem (6, 12, 20, 24, 28,
 *    30, 50; ou uma embalagem que o próprio artigo usa nas compras ou mostra no nome, como "(80UN)",
 *    "(60)", "X24", "C24"; ±10%). O impacto fica "a confirmar", salvo
 *    quando a fatura lida pelo OCR para esse documento não bate com o total no PingWin: aí conta a
 *    diferença.
 *  · "Preço de compra fora do normal": as outras; o impacto é |total − quantidade × mediana|.
 * Calcula-se uma vez por corrida (as duas regras partilham-no).
 */
final class PriceOutliers
{
    public const PACKS = [6, 12, 20, 24, 28, 30, 50];
    private const FACTOR = RecipeCostService::OUTLIER_FACTOR;
    private const MIN_LINES = 3;
    private const RECENT_DAYS = 180;
    private const STABLE_SHARE = 0.6;
    private const PACK_TOLERANCE = 0.1;
    /** Diferenças de cêntimos entre a fatura e o PingWin são arredondamentos. */
    private const OCR_TOLERANCE_CENTS = 5;

    /** @return list<array<string, mixed>> */
    public static function for(AuditScope $scope): array
    {
        return $scope->memo('restauracao.price_outliers', fn () => self::compute(RestaurantAuditData::for($scope)));
    }

    /** A embalagem que explica a razão (o fator e o rótulo), ou null. */
    public static function packFor(float $ratio, array $articlePacks = []): ?array
    {
        $n = $ratio >= 1 ? $ratio : 1 / $ratio;
        foreach ($articlePacks as $p) {
            if (abs($n / $p['factor'] - 1) <= self::PACK_TOLERANCE) {
                return $p;
            }
        }
        foreach (self::PACKS as $p) {
            if (abs($n / $p - 1) <= self::PACK_TOLERANCE) {
                return ['factor' => (float) $p, 'unit' => null];
            }
        }

        return null;
    }

    /**
     * Os tamanhos de embalagem escritos no nome do artigo: "(80UN)", "(60)", "12UN", "X24", "C24",
     * "CX 12", "0.33X15". Só contagens inteiras de 4 a 999.
     *
     * @return list<array{factor: float, unit: string}>
     */
    public static function namePacks(?string $description): array
    {
        $t = mb_strtoupper((string) $description);
        $found = [];
        foreach (['/\((\d{1,3})\s*(?:UN\w*|U)?\)/u', '/\b(\d{1,3})\s*(?:UN|UND|UNID|UNIDADES)\b/u', '/\b(?:X|C|CX)\s?(\d{1,3})\b/u', '/\d[.,]\d+\s?X\s?(\d{1,3})\b(?![.,]\d)/u'] as $re) {
            if (preg_match_all($re, $t, $m)) {
                foreach ($m[1] as $n) {
                    if ((int) $n >= 4) {
                        $found[(int) $n] = ['factor' => (float) $n, 'unit' => "embalagem de {$n} (no nome do artigo)"];
                    }
                }
            }
        }

        return array_values($found);
    }

    /** @return list<array<string, mixed>> */
    private static function compute(RestaurantAuditData $data): array
    {
        $cost = $data->cost();
        $units = $cost->units();
        $recentSince = $data->daysAgo(self::RECENT_DAYS);
        // As unidades de embalagem com fator 1 (Caixa 24 a valer 1) ficam de fora: são da regra 3.
        $packWithFactorOne = [];
        foreach ($units as $id => $u) {
            if (abs((float) $u->unit_value - 1.0) < 1e-9 && UnidadeFatorRule::fromName((string) $u->description) !== null) {
                $packWithFactorOne[(string) $id] = true;
            }
        }

        $out = [];
        foreach ($data->purchases() as $pid => $lines) {
            $pid = (string) $pid;
            $root = $cost->componentBaseRoot($pid);
            // Só se comparam linhas na família da unidade base do artigo (ou com o fator confirmado).
            $valid = array_values(array_filter($lines, fn ($l) => $l['qnt'] > 0 && $l['qnt_base'] > 0 && $l['total'] > 0
                && ($l['qty_source'] === 'confirmado' || ($l['unit_id'] !== '' && ! isset($packWithFactorOne[$l['unit_id']]) && $cost->unitRoot($l['unit_id']) === $root))));
            if (count($valid) < self::MIN_LINES) {
                continue;
            }
            $prices = array_map(fn ($l) => $l['total'] / $l['qnt_base'], $valid);
            $median = RecipeCostService::median($prices);
            if ($median <= 0) {
                continue;
            }
            // Artigos de preço instável (genéricos como "Despesas operacionais") não têm um preço normal.
            if (count(array_filter($prices, fn ($p) => $p >= $median / 2 && $p <= $median * 2)) / count($prices) < self::STABLE_SHARE) {
                continue;
            }
            $base = (string) ($units[$root]->description ?? 'unidade');
            $articlePacks = [];
            foreach ($valid as $l) {
                $f = $l['raw_qnt_base'] / $l['qnt'];
                if ($f > 1.0001) {
                    $articlePacks[(string) round($f, 4)] = ['factor' => $f, 'unit' => $l['unit_label']];
                }
            }
            $articlePacks = array_merge(array_values($articlePacks), self::namePacks($data->item($pid)?->description));

            foreach ($valid as $l) {
                if ($l['date'] < $recentSince) {
                    continue;
                }
                $price = $l['total'] / $l['qnt_base'];
                $ratio = $price / $median;
                if ($ratio <= self::FACTOR && $ratio >= 1 / self::FACTOR) {
                    continue;
                }
                $pack = self::packFor($ratio, $articlePacks);
                $own = $l['raw_qnt_base'] / $l['qnt'];
                $n = Fmt::qty($pack['factor'] ?? 0);
                $hint = match (true) {
                    $pack && $pack['unit'] && str_starts_with($pack['unit'], 'embalagem de') => "O nome do artigo indica embalagens de {$n}.",
                    $pack && $pack['unit'] && $pack['unit'] !== $l['unit_label'] => "{$n} é o número de unidades de uma «{$pack['unit']}».",
                    $pack && $ratio < 1 && $own > 1.0001 && abs($ratio * $own - 1) <= self::PACK_TOLERANCE => "A linha está em «{$l['unit_label']}», que tem {$n} unidades: parece que a quantidade foi contada em unidades.",
                    $pack !== null => "{$n} é um tamanho de embalagem comum (uma caixa ou uma grade, por exemplo).",
                    default => '',
                };
                $out[] = [
                    'pid' => $pid, 'line' => $l, 'valid' => $valid, 'median' => $median, 'base' => $base, 'price' => $price,
                    'ratio' => $ratio, 'times' => Fmt::times($ratio),
                    'pack' => $pack, 'hint' => $hint,
                    'estimate' => (int) round(abs($l['total'] - $l['qnt_base'] * $median) * 100),
                    'description' => (string) ($data->item($pid)?->description ?? $l['description']),
                ];
            }
        }

        // A fatura lida pelo OCR (quando há): se o total não bate com o PingWin, a diferença é dinheiro em jogo.
        $docs = array_values(array_unique(array_map(fn ($o) => $o['line']['docheader_id'], array_filter($out, fn ($o) => $o['pack'] !== null))));
        $ocr = [];
        foreach (array_chunk($docs, 500) as $chunk) {
            foreach (DB::table('ocr_invoice_pingwin_links as k')
                ->join('ocr_invoice_summary as s', 's.ocr_invoice_id', '=', 'k.ocr_invoice_id')
                ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'k.docheader_id')->on('d.company_id', '=', 'k.company_id'))
                ->where('k.company_id', $data->companyId)->whereIn('k.docheader_id', $chunk)
                ->get(['k.docheader_id', 'k.ocr_invoice_id', 's.total_cents as ocr_total', 'd.total_cents as pingwin_total']) as $r) {
                $ocr[(string) $r->docheader_id] = ['ocr_invoice_id' => (int) $r->ocr_invoice_id, 'ocr_total' => (int) $r->ocr_total, 'pingwin_total' => (int) $r->pingwin_total];
            }
        }
        foreach ($out as $i => $o) {
            $match = $ocr[$o['line']['docheader_id']] ?? null;
            $out[$i]['ocr'] = $match ? $match + ['diff' => abs($match['pingwin_total'] - $match['ocr_total'])] : null;
        }

        return $out;
    }

    /** As outras compras do artigo, para a evidência (mais recentes primeiro). */
    public static function othersTable(array $o): array
    {
        $others = array_values(array_filter($o['valid'], fn ($x) => $x['id'] !== $o['line']['id']));
        usort($others, fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

        return [
            'title' => 'As outras compras do artigo (mais recentes primeiro)',
            'columns' => [
                ['key' => 'date', 'label' => 'Data', 'format' => 'date'], ['key' => 'document', 'label' => 'Documento'],
                ['key' => 'supplier', 'label' => 'Fornecedor'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'],
                ['key' => 'base_price', 'label' => 'Preço por ' . mb_strtolower($o['base']), 'align' => 'end', 'format' => 'eur4'],
                ['key' => 'total_cents', 'label' => 'Total', 'align' => 'end', 'format' => 'cents'],
            ],
            'rows' => array_map(fn ($x) => [
                'date' => $x['date'], 'document' => Fmt::doc($x['document']), 'supplier' => $x['supplier'],
                'qty' => Fmt::qty($x['qnt']) . " «{$x['unit_label']}»", 'base_price' => round($x['total'] / $x['qnt_base'], 4),
                'total_cents' => (int) round($x['total'] * 100),
            ], array_slice($others, 0, 12)),
        ];
    }

    /** Os factos comuns às duas regras. */
    public static function facts(array $o): array
    {
        $l = $o['line'];

        return [
            ['label' => 'Documento', 'value' => Fmt::doc($l['document'])],
            ['label' => 'Data', 'value' => Fmt::date($l['date'])],
            ['label' => 'Fornecedor', 'value' => $l['supplier']],
            ['label' => 'Quantidade', 'value' => Fmt::qty($l['qnt']) . " «{$l['unit_label']}»"],
            ['label' => "Preço por «{$l['unit_label']}»", 'value' => $l['price'], 'format' => 'eur4'],
            ['label' => 'Total da linha', 'value' => $l['total'], 'format' => 'eur'],
            ['label' => 'Preço desta compra por ' . mb_strtolower($o['base']), 'value' => round($o['price'], 4), 'format' => 'eur4'],
            ['label' => 'Preço habitual por ' . mb_strtolower($o['base']), 'value' => round($o['median'], 4), 'format' => 'eur4',
                'tip' => sprintf('O valor do meio das últimas %d compras: metade foi mais barata e metade mais cara.', count($o['valid']))],
            ['label' => 'Face ao preço habitual', 'value' => $o['times']],
        ];
    }

    /**
     * "O que vimos": a linha e o preço habitual, em linguagem simples. Ex.: "Na BOVFT/1044 de
     * 01/10/2026, 96 «Unidade» a 13,34 € cada (1 280,64 €). Nas últimas 37 compras pagou, em média,
     * 0,56 € por unidade: desta vez foi 24 vezes mais. 24 é o número de unidades de uma «Grade 24»."
     */
    public static function seen(array $o): string
    {
        $l = $o['line'];

        return trim(sprintf('Na %s de %s, %s «%s» a %s cada (%s). Nas últimas %d compras pagou, em média, %s por %s: desta vez foi %s. %s',
            Fmt::doc($l['document']), Fmt::date($l['date']), Fmt::qty($l['qnt']), $l['unit_label'], Fmt::unitPrice($l['price']), Fmt::eur($l['total']),
            count($o['valid']), Fmt::unitPrice($o['median']), mb_strtolower($o['base']), $o['times'], $o['hint']));
    }

    /** O total ao preço habitual (o que a linha custaria), para "Quanto está em jogo". */
    public static function atUsualPrice(array $o): float
    {
        return $o['line']['qnt_base'] * $o['median'];
    }

    /** @return list<array{0:string,1:string}> */
    public static function refs(array $o): array
    {
        $l = $o['line'];

        return array_values(array_filter([['artigo', $o['pid']], ['documento', $l['docheader_id']], $l['supplier_id'] !== '' ? ['fornecedor', $l['supplier_id']] : null]));
    }
}
