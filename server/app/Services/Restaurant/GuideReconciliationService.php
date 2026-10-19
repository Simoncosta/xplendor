<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinSupplierDocument;
use App\Services\OcrPingwinLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 5 · Conferência fatura × guias (SÓ LEITURA). Uma fatura de fornecedor (OCR) refere
 * as guias de entrega (guide_refs). Cada guia emparelha-se com um documento do mesmo fornecedor no
 * PingWin: primeiro pelo número normalizado (a referência do documento), senão pela data (a mesma, ou
 * a mais próxima a ±1 dia) e confirma-se pelas linhas.
 *
 * As linhas da fatura vêm pela ordem das guias (sem a guia em cada linha): percorrem-se as guias por
 * ordem e cada guia lançada "consome" as suas linhas (quantidade e preço); as linhas que ficam entre
 * duas guias lançadas são das guias em falta. Diferenças, cada uma com € (com IVA):
 *  · guia em falta (referida e não lançada) → o valor das suas linhas;
 *  · guia a mais (lançada no período e não referida) → o total do documento;
 *  · por linha: quantidade, preço e desconto.
 * O caso de referência: a FT FAR.2026/379 refere 14 guias; faltam a GT 3233 (06/05) e a GT 3732 (26/05):
 * 189,95 €.
 */
class GuideReconciliationService
{
    /** Faturas e guias de fornecedor (nunca encomendas nem anulados). */
    public const DOC_TYPES = ['1209', OcrPingwinLinkService::FRC, '1203', '1201', '1208'];
    public const DATE_TOLERANCE = 1;

    public function for(OcrInvoice $inv): ?array
    {
        $guides = array_values(array_filter((array) ($inv->guide_refs ?? []), fn ($g) => ! empty($g['ref'])));
        if (! $guides) {
            return null;
        }
        usort($guides, fn ($a, $b) => [(string) ($a['date'] ?? '9999'), $a['ref']] <=> [(string) ($b['date'] ?? '9999'), $b['ref']]);
        $docs = $this->docs($inv, $guides);
        $lines = DB::table('ocr_invoice_lines')->where('ocr_invoice_id', $inv->id)->orderBy('position')->get()
            ->map(fn ($l) => ['position' => (int) $l->position, 'code' => $l->supplier_code, 'item' => (string) $l->item, 'qty' => (float) $l->quantity, 'unit' => $l->unit,
                'price' => (float) $l->unit_price, 'discount' => (float) ($l->discount_pct ?? 0), 'total_cents' => (int) $l->line_total_cents, 'vat' => (float) ($l->vat_rate ?? 0)])->values()->all();

        // 1. Emparelhar cada guia com um documento: pelo número; depois a mesma data (todas as guias);
        //    só no fim a ±1 dia, para uma guia não ficar com o documento da guia do dia seguinte.
        $used = [];
        $pairs = [];
        foreach ($guides as $i => $g) {
            $pairs[$i] = ['guide' => $g, 'doc' => null, 'method' => null];
        }
        $take = function (int $i, $doc, string $method) use (&$pairs, &$used) {
            $pairs[$i]['doc'] = $doc;
            $pairs[$i]['method'] = $method;
            $used[$doc->docheader_id] = true;
        };
        foreach ($guides as $i => $g) {
            $key = OcrPingwinLinkService::numberKey($g['ref']);
            $doc = $key === null ? null : $docs->first(fn ($d) => ! isset($used[$d->docheader_id]) && OcrPingwinLinkService::numberKey($d->docreference_number) === $key);
            if ($doc) {
                $take($i, $doc, 'numero');
            }
        }
        foreach ([0, self::DATE_TOLERANCE] as $tolerance) {
            foreach ($guides as $i => $g) {
                if ($pairs[$i]['doc'] || empty($g['date'])) {
                    continue;
                }
                $target = CarbonImmutable::parse($g['date']);
                $doc = $docs->filter(fn ($d) => ! isset($used[$d->docheader_id]) && abs($target->diffInDays(CarbonImmutable::parse(substr((string) $d->doc_date, 0, 10)), false)) <= $tolerance)
                    ->sortBy(fn ($d) => abs($target->diffInDays(CarbonImmutable::parse(substr((string) $d->doc_date, 0, 10)), false)))->first();
                if ($doc) {
                    $take($i, $doc, 'data');
                }
            }
        }

        // 2. As linhas da fatura, pela ordem das guias.
        $pwLines = $this->pwLines($docs->pluck('docheader_id')->all());
        $p = 0;
        $pending = [];
        $out = [];
        foreach ($pairs as $i => $x) {
            if (! $x['doc']) {
                $pending[] = $i;
                $out[$i] = ['lines' => []];
                continue;
            }
            $mine = $pwLines[$x['doc']->docheader_id] ?? [];
            $k = count($mine);
            $start = $k ? $this->findRun($lines, $p, $mine) : null;
            if ($start === null) {
                $start = $p;   // as linhas não batem: as k seguintes, com as diferenças
            }
            $this->assign($out, $pending, array_slice($lines, $p, max(0, $start - $p)));
            $pending = [];
            $out[$i] = ['lines' => $this->compare(array_slice($lines, $start, $k), $mine)];
            $p = $start + $k;
        }
        $this->assign($out, $pending, array_slice($lines, $p));

        // 3. O resultado por guia, as guias a mais e os totais.
        $rows = [];
        $missing = 0;
        $diff = 0;
        foreach ($pairs as $i => $x) {
            $g = $x['guide'];
            if (! $x['doc']) {
                $eur = array_sum(array_map(fn ($l) => self::withVat($l['total_cents'], $l['vat']), $out[$i]['lines']));
                $missing += $eur;
                $rows[] = ['ref' => $g['ref'], 'date' => $g['date'] ?? null, 'status' => 'em_falta', 'doc' => null, 'method' => null,
                    'lines' => array_map(fn ($l) => ['ocr' => $l, 'pingwin' => null, 'diffs' => []], $out[$i]['lines']), 'eur_cents' => $eur];
                continue;
            }
            $lineDiff = array_sum(array_map(fn ($l) => array_sum(array_column($l['diffs'], 'eur_cents')), $out[$i]['lines']));
            $diff += $lineDiff;
            $rows[] = ['ref' => $g['ref'], 'date' => $g['date'] ?? null, 'status' => $lineDiff !== 0 ? 'com_diferencas' : 'lancada', 'method' => $x['method'],
                'doc' => self::doc($x['doc']), 'lines' => $out[$i]['lines'], 'eur_cents' => $lineDiff];
        }
        $period = [min(array_filter(array_column($guides, 'date')) ?: [null]), substr((string) $inv->issue_date, 0, 10)];
        $extra = $docs->filter(fn ($d) => ! isset($used[$d->docheader_id]) && $period[0] && substr((string) $d->doc_date, 0, 10) >= $period[0] && substr((string) $d->doc_date, 0, 10) <= $period[1])
            ->map(fn ($d) => self::doc($d) + ['eur_cents' => (int) $d->total_cents])->values()->all();

        return [
            'guides' => $rows,
            'extra' => $extra,
            'summary' => [
                'referred' => count($guides),
                'launched' => count(array_filter($rows, fn ($r) => $r['status'] !== 'em_falta')),
                'missing' => count(array_filter($rows, fn ($r) => $r['status'] === 'em_falta')),
                'missing_eur_cents' => $missing,
                'extra_eur_cents' => array_sum(array_column($extra, 'eur_cents')),
                'line_diff_eur_cents' => $diff,
                'period' => ['from' => $period[0], 'to' => $period[1]],
            ],
        ];
    }

    /** Os documentos do mesmo fornecedor (pelo NIF), do período das guias até à fatura, sem os ligados a outra fatura. */
    private function docs(OcrInvoice $inv, array $guides): \Illuminate\Support\Collection
    {
        $nif = OcrPingwinLinkService::digits($inv->supplier_nif);
        $dates = array_filter(array_column($guides, 'date'));
        $from = $dates ? CarbonImmutable::parse(min($dates))->subDays(self::DATE_TOLERANCE + 2)->toDateString() : CarbonImmutable::parse((string) $inv->issue_date)->subDays(OcrPingwinLinkService::GUIDES_FALLBACK_DAYS)->toDateString();
        $to = substr((string) $inv->issue_date, 0, 10);
        $taken = OcrInvoicePingwinLink::where('company_id', $inv->company_id)->where('ocr_invoice_id', '!=', $inv->id)->pluck('docheader_id')->all();

        return PingwinSupplierDocument::where('company_id', $inv->company_id)->whereIn('docconfig_id', self::DOC_TYPES)
            ->where(fn ($q) => $q->whereNull('docstatus_id')->orWhere('docstatus_id', '!=', OcrPingwinLinkService::VOIDED))
            ->whereBetween('doc_date', [$from, $to . ' 23:59:59'])->whereNotIn('docheader_id', $taken)->orderBy('doc_date')->orderBy('id')->get()
            ->filter(fn ($d) => $nif !== '' && OcrPingwinLinkService::digits($d->tax_number) === $nif)->values();
    }

    private function pwLines(array $ids): array
    {
        $out = [];
        foreach (DB::table('pingwin_supplier_document_lines')->whereIn('docheader_id', $ids)->orderBy('line_number')->get() as $l) {
            $out[(string) $l->docheader_id][] = ['code' => $l->product_code, 'item' => (string) $l->description, 'qty' => (float) $l->qnt, 'price' => (float) $l->price,
                'discount' => (float) ($l->discount1 ?? 0), 'total_cents' => (int) $l->total_cents];
        }

        return $out;
    }

    private static function key(array $l): string
    {
        return sprintf('%.3f|%.4f', $l['qty'], $l['price']);
    }

    /** O início da primeira sequência de linhas da fatura (a partir de $p) com as mesmas quantidades e preços do documento. */
    private function findRun(array $lines, int $p, array $mine): ?int
    {
        $want = array_map([self::class, 'key'], $mine);
        sort($want);
        $k = count($mine);
        for ($s = $p; $s + $k <= count($lines); $s++) {
            $got = array_map([self::class, 'key'], array_slice($lines, $s, $k));
            sort($got);
            if ($got === $want) {
                return $s;
            }
        }

        return null;
    }

    /** As linhas que sobram entre duas guias lançadas: das guias em falta (uma guia: todas; várias: a primeira fica com elas). */
    private function assign(array &$out, array $pending, array $lines): void
    {
        if (! $lines) {
            return;
        }
        if (! $pending) {
            return;   // linhas sem guia em falta antes: ficam por atribuir (não contam como guia em falta)
        }
        // Com várias guias em falta seguidas, as linhas dividem-se pela ordem: uma por guia até à última, que fica com o resto.
        $n = count($pending);
        foreach ($pending as $j => $i) {
            $out[$i]['lines'] = $j < $n - 1 ? array_splice($lines, 0, 1) : $lines;
        }
    }

    /** Linha a linha (pela ordem, depois pela quantidade e preço): as diferenças de quantidade, preço e desconto, em € com IVA. */
    private function compare(array $ocr, array $pw): array
    {
        $out = [];
        foreach ($ocr as $j => $o) {
            $w = $pw[$j] ?? null;
            $diffs = [];
            if ($w) {
                if (abs($o['qty'] - $w['qty']) > 1e-6) {
                    $diffs[] = ['kind' => 'quantidade', 'eur_cents' => self::withVat((int) round(($o['qty'] - $w['qty']) * $o['price'] * 100), $o['vat'])];
                }
                if (abs($o['price'] - $w['price']) > 1e-4) {
                    $diffs[] = ['kind' => 'preco', 'eur_cents' => self::withVat((int) round(($o['price'] - $w['price']) * $w['qty'] * 100), $o['vat'])];
                }
                if (abs($o['discount'] - $w['discount']) > 1e-6) {
                    $diffs[] = ['kind' => 'desconto', 'eur_cents' => self::withVat((int) round(($w['discount'] - $o['discount']) / 100 * $o['qty'] * $o['price'] * 100), $o['vat'])];
                }
            }
            $out[] = ['ocr' => $o, 'pingwin' => $w, 'diffs' => $diffs];
        }

        return $out;
    }

    private static function withVat(int $cents, float $vat): int
    {
        return (int) round($cents * (1 + $vat / 100));
    }

    private static function doc(PingwinSupplierDocument $d): array
    {
        return ['docheader_id' => (string) $d->docheader_id, 'document' => (string) $d->document, 'date' => substr((string) $d->doc_date, 0, 10), 'total_cents' => (int) $d->total_cents];
    }
}
