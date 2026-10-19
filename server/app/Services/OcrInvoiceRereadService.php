<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OcrInvoice;
use App\Models\OcrInvoiceDiscount;
use App\Models\OcrInvoiceLine;
use App\Services\Ocr\InvoiceDiscountAllocator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * XPLENDOR — Faturas: "Revalidar" e "Reler do documento".
 *  · Revalidar: volta a ligar os descontos aos artigos (pelo código da promoção das linhas de
 *    agora), refaz a conferência por taxa com o QR e o quadro "IVA por taxa". Sem IA.
 *  · Reler: volta a correr o OCR. Antes, mostra o que foi mudado à mão (contra a leitura
 *    original) e pergunta se fica ou se é substituído; "manter" volta a aplicar essas alterações
 *    à nova leitura, linha a linha (pelo código do artigo, ou pela descrição lida).
 */
class OcrInvoiceRereadService
{
    /** Os campos da linha que contam como "alteração manual" (os lidos da fatura). */
    public const FIELDS = [
        'supplier_code' => 'Código', 'item' => 'Descrição', 'quantity' => 'Quantidade', 'unit' => 'Unidade',
        'unit_price' => 'Preço unitário', 'discount_pct' => 'Desconto %', 'gross_total_cents' => 'Valor',
        'vat_rate' => 'IVA', 'promo_code' => 'Código da promoção',
    ];

    public function __construct(private readonly InvoiceOcrService $ocr) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Revalidar
    // ─────────────────────────────────────────────────────────────────────────

    /** Descontos → artigos, conferência por taxa com o QR. Devolve a conferência. */
    public function revalidate(OcrInvoice $inv): array
    {
        $inv->load(['lines', 'discounts']);
        $lines = $inv->lines->sortBy('position')->values();
        $res = InvoiceDiscountAllocator::allocate(
            $lines->map(fn (OcrInvoiceLine $l) => ['promo_code' => $l->promo_code, 'vat_code' => $l->vat_code, 'vat_rate' => $l->vat_rate,
                'gross_total_cents' => $l->grossCents(), 'item' => $l->item])->all(),
            $inv->discounts->map(fn (OcrInvoiceDiscount $d) => ['code' => $d->code, 'label' => $d->label, 'amount_cents' => $d->amount_cents,
                'vat_code' => $d->vat_code, 'vat_rate' => $d->vat_rate])->values()->all()
        );

        $check = null;
        DB::transaction(function () use ($inv, $lines, $res, &$check) {
            foreach ($res['lines'] as $i => $r) {
                $lines[$i]->update(['gross_total_cents' => $r['gross_total_cents'], 'discount_cents' => $r['discount_cents'],
                    'discount_code' => $r['discount_code'], 'discount_label' => $r['discount_label'], 'line_total_cents' => $r['line_total_cents']]);
            }
            foreach ($inv->discounts->values() as $k => $d) {
                $r = $res['discounts'][$k];
                $d->update(['status' => $r['status'], 'warning' => $r['warning'],
                    'allocations' => array_map(fn ($a) => ['line_id' => $lines[$a['line']]->id, 'cents' => $a['cents']], $r['allocations'])]);
            }
            $byRate = $inv->qr_data['by_rate'] ?? [];
            if ($inv->qr_ok && $byRate !== []) {
                $check = $this->ocr->conference($lines->map(fn ($l) => ['vat_rate' => $l->vat_rate, 'line_total_cents' => $l->line_total_cents])->all(), ['by_rate' => $byRate]);
                $inv->update(['check_status' => $check['ok'] ? InvoiceOcrService::CHECK_OK : InvoiceOcrService::CHECK_MISMATCH, 'check_diff' => $check['rows']]);
            }
        });

        return ['check' => $check];
    }

    /**
     * "IVA por taxa": o lido (QR; sem QR, o quadro do IVA da fatura; senão o sumário) e o calculado
     * das linhas (líquido de descontos), lado a lado, com a diferença e as linhas que a explicam.
     */
    public function vatByRate(OcrInvoice $inv): array
    {
        $inv->loadMissing(['lines', 'summary', 'discounts']);
        $tol = (int) config('services.openai.ocr.check_tolerance_cents', 2);
        $source = 'qr';
        $read = [];
        foreach ((array) ($inv->qr_ok ? ($inv->qr_data['by_rate'] ?? []) : []) as $r) {
            $read[(int) $r['rate']] = ['base' => (int) $r['base_cents'], 'vat' => (int) $r['vat_cents']];
        }
        if ($read === [] && $inv->vat_table) {
            $source = 'fatura';
            foreach ($inv->vat_table as $r) {
                $read[(int) $r['rate']] ??= ['base' => 0, 'vat' => 0];
                $read[(int) $r['rate']]['base'] += (int) $r['base_cents'];
                $read[(int) $r['rate']]['vat'] += (int) $r['vat_cents'];
            }
        }
        if ($read === [] && $inv->summary) {
            $source = 'sumario';
            foreach ((array) $inv->summary->vat_breakdown as $r) {
                if (isset($r['rate'])) {
                    $read[(int) $r['rate']] = ['base' => (int) ($r['base_cents'] ?? 0), 'vat' => (int) ($r['vat_cents'] ?? 0)];
                }
            }
        }
        $codes = [];
        foreach ((array) $inv->vat_table as $r) {
            $codes[(int) $r['rate']][] = (string) $r['code'];
        }

        $lines = $inv->lines->sortBy('position')->values();
        $calc = [];
        foreach ($lines as $l) {
            if ($l->vat_rate !== null) {
                $calc[$l->vat_rate] = ($calc[$l->vat_rate] ?? 0) + (int) $l->line_total_cents;
            }
        }
        $noRate = $lines->whereNull('vat_rate');

        $rates = array_unique(array_merge(array_keys($read), array_keys($calc)));
        sort($rates);
        $rows = [];
        foreach ($rates as $rate) {
            $base = $calc[$rate] ?? 0;
            $vat = (int) round($base * $rate / 100);
            $rb = $read[$rate]['base'] ?? null;
            $rv = $read[$rate]['vat'] ?? null;
            $diffBase = $rb === null ? null : $base - $rb;
            $diffVat = $rv === null ? null : $vat - $rv;
            $ok = $rb !== null && abs($diffBase) <= $tol && abs($diffVat) <= $tol;
            $rows[] = [
                'rate' => $rate, 'codes' => array_values(array_unique($codes[$rate] ?? [])),
                'read_base' => $rb === null ? null : $rb / 100, 'read_vat' => $rv === null ? null : $rv / 100,
                'calc_base' => $base / 100, 'calc_vat' => $vat / 100,
                'diff_base' => $diffBase === null ? null : $diffBase / 100, 'diff_vat' => $diffVat === null ? null : $diffVat / 100,
                'ok' => $ok,
                'explained_by' => $ok || $diffBase === null ? [] : $this->explainers($inv, $lines, $noRate, $rate, $diffBase),
                // Um desconto por atribuir com o valor da diferença (a base fica a mais).
                'explained_by_discounts' => $ok || $diffBase === null || $diffBase <= 0 ? [] : $inv->discounts
                    ->where('status', OcrInvoiceDiscount::UNASSIGNED)->filter(fn ($d) => (int) $d->amount_cents === $diffBase)->pluck('code')->values()->all(),
            ];
        }

        return ['source' => $source, 'rows' => $rows, 'ok' => $rows !== [] && collect($rows)->every('ok') && $noRate->isEmpty(),
            'lines_without_rate' => $noRate->pluck('id')->values()->all()];
    }

    /**
     * As linhas que explicam a diferença de uma taxa: a que vale exatamente a diferença (a mais, a
     * menos, ou com outra taxa), a que foi mudada à mão nesse valor, o desconto que falta ou sobra,
     * e as linhas sem taxa. Com uma só linha na taxa, é essa.
     */
    private function explainers(OcrInvoice $inv, Collection $lines, Collection $noRate, int $rate, int $diff): array
    {
        $abs = abs($diff);
        $read = collect((array) $inv->read_snapshot)->keyBy('id');
        if ($lines->where('vat_rate', $rate)->count() === 1 && $noRate->isEmpty()) {
            return [$lines->firstWhere('vat_rate', $rate)->id];
        }
        $hits = $lines->filter(function (OcrInvoiceLine $l) use ($rate, $diff, $abs, $read) {
            $net = (int) $l->line_total_cents;
            if ($l->vat_rate === $rate) {
                $readGross = $read[$l->id]['gross_total_cents'] ?? null;

                // Linha a mais (diff > 0), mudada à mão nesse valor, ou desconto a menos/a mais.
                return ($diff > 0 && $net === $abs) || ($readGross !== null && $l->grossCents() - (int) $readGross === $diff)
                    || ((int) $l->discount_cents === $abs);
            }

            // Uma linha de outra taxa que devia ser desta (falta aqui: diff < 0).
            return $diff < 0 && $net === $abs;
        });
        if ($noRate->isNotEmpty() && $diff < 0 && (int) $noRate->sum('line_total_cents') === $abs) {
            $hits = $hits->merge($noRate);
        }

        return $hits->pluck('id')->unique()->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reler
    // ─────────────────────────────────────────────────────────────────────────

    /** Os campos da leitura de uma linha, como ficam na leitura original. */
    public static function snapshotOf(array $l): array
    {
        return [
            'supplier_code' => $l['supplier_code'] ?? null, 'item' => $l['item'] ?? null,
            'quantity' => isset($l['quantity']) ? (float) $l['quantity'] : null, 'unit' => $l['unit'] ?? null,
            'unit_price' => isset($l['unit_price']) ? (float) $l['unit_price'] : null,
            'discount_pct' => isset($l['discount_pct']) ? (float) $l['discount_pct'] : null,
            'gross_total_cents' => isset($l['gross_total_cents']) ? (int) $l['gross_total_cents'] : (isset($l['line_total_cents']) ? (int) $l['line_total_cents'] : null),
            'vat_rate' => isset($l['vat_rate']) ? (int) $l['vat_rate'] : null, 'promo_code' => $l['promo_code'] ?? null,
        ];
    }

    private static function current(OcrInvoiceLine $l): array
    {
        return self::snapshotOf([
            'supplier_code' => $l->supplier_code, 'item' => $l->item, 'quantity' => $l->quantity, 'unit' => $l->unit,
            'unit_price' => $l->unit_price, 'discount_pct' => $l->discount_pct, 'gross_total_cents' => $l->grossCents(),
            'vat_rate' => $l->vat_rate, 'promo_code' => $l->promo_code,
        ]);
    }

    /**
     * O que foi mudado à mão desde a leitura: linhas alteradas (campo a campo), apagadas e
     * acrescentadas, o artigo escolhido à mão e a unidade escolhida.
     * available = false quando a fatura foi lida antes de se guardar a leitura original.
     */
    public function manualChanges(OcrInvoice $inv): array
    {
        $inv->loadMissing('lines');
        $snapshot = $inv->read_snapshot;
        if (! is_array($snapshot)) {
            return ['available' => false, 'changes' => []];
        }
        $current = $inv->lines->keyBy('id');
        $changes = [];
        foreach ($snapshot as $s) {
            $l = $current[$s['id']] ?? null;
            if (! $l) {
                $changes[] = ['type' => 'apagada', 'line_id' => $s['id'], 'item' => $s['item'], 'read' => $s, 'fields' => []];

                continue;
            }
            $fields = $this->diffFields($s, self::current($l));
            if ($l->isManual() && $l->article_id) {
                $fields[] = ['field' => 'artigo', 'label' => 'Artigo', 'read' => null, 'current' => $l->article_id];
            }
            if ($l->launch_unit_id) {
                $fields[] = ['field' => 'unidade', 'label' => 'Unidade no PingWin', 'read' => null, 'current' => $l->launch_unit_id];
            }
            if ($fields !== []) {
                $changes[] = ['type' => 'alterada', 'line_id' => $l->id, 'item' => $l->item, 'read' => $s, 'fields' => $fields];
            }
        }
        $known = collect($snapshot)->pluck('id')->flip();
        foreach ($inv->lines->sortBy('position') as $l) {
            if (! isset($known[$l->id])) {
                $changes[] = ['type' => 'acrescentada', 'line_id' => $l->id, 'item' => $l->item, 'read' => null,
                    'fields' => $this->diffFields(array_fill_keys(array_keys(self::FIELDS), null), self::current($l))];
            }
        }

        return ['available' => true, 'changes' => $changes];
    }

    private function diffFields(array $read, array $now): array
    {
        $out = [];
        foreach (self::FIELDS as $f => $label) {
            $a = $read[$f] ?? null;
            $b = $now[$f] ?? null;
            $same = is_float($a) || is_float($b) || is_int($a) || is_int($b)
                ? ($a === null) === ($b === null) && abs((float) $a - (float) $b) < 0.000001
                : trim((string) $a) === trim((string) $b);
            if (! $same) {
                $out[] = ['field' => $f, 'label' => $label, 'read' => $a, 'current' => $b];
            }
        }

        return $out;
    }

    /** Guarda o que fica para depois da nova leitura ("manter") ou nada ("substituir"). */
    public function prepare(OcrInvoice $inv, bool $keep): array
    {
        $mc = $this->manualChanges($inv);
        if (! $keep || $mc['changes'] === []) {
            $inv->update(['reread_keep' => null]);

            return $mc;
        }
        $lines = $inv->lines->keyBy('id');
        $kept = [];
        foreach ($mc['changes'] as $c) {
            $l = $lines[$c['line_id']] ?? null;
            $entry = ['type' => $c['type'], 'item' => $c['item'], 'key' => $c['read'] ? self::key($c['read']) : null, 'values' => []];
            if ($c['type'] !== 'apagada' && $l) {
                foreach ($c['fields'] as $f) {
                    match ($f['field']) {
                        'artigo' => $entry['values'] += ['article_id' => $l->article_id, 'link_state' => $l->link_state, 'link_method' => $l->link_method,
                            'link_confidence' => $l->link_confidence, 'linked_by' => $l->linked_by, 'linked_at' => $l->linked_at?->toIso8601String()],
                        'unidade' => $entry['values'] += ['launch_unit_id' => $l->launch_unit_id],
                        default => $entry['values'][$f['field']] = $f['current'],
                    };
                }
            }
            $kept[] = $entry;
        }
        $inv->update(['reread_keep' => ['changes' => $kept]]);

        return $mc;
    }

    /** Depois da nova leitura: volta a aplicar as alterações manuais guardadas, e revalida. */
    public function applyKept(OcrInvoice $inv): void
    {
        $kept = (array) ($inv->reread_keep['changes'] ?? []);
        $inv->load('lines');
        $pool = $inv->lines->sortBy('position')->values()->map(fn ($l) => ['line' => $l, 'key' => self::key(self::current($l)), 'used' => false])->all();
        $unmatched = [];
        DB::transaction(function () use ($inv, $kept, &$pool, &$unmatched) {
            $pos = (int) $inv->lines->max('position') + 1;
            foreach ($kept as $c) {
                if ($c['type'] === 'acrescentada') {
                    OcrInvoiceLine::create($this->lineValues($c['values']) + ['ocr_invoice_id' => $inv->id, 'company_id' => $inv->company_id, 'position' => $pos++]);

                    continue;
                }
                $idx = null;
                foreach ($pool as $k => $p) {
                    if (! $p['used'] && $p['key'] === $c['key']) {
                        $idx = $k;
                        break;
                    }
                }
                if ($idx === null) {
                    $unmatched[] = $c['item'];

                    continue;
                }
                $pool[$idx]['used'] = true;
                $line = $pool[$idx]['line'];
                $c['type'] === 'apagada' ? $line->delete() : $line->update($this->lineValues($c['values']));
            }
            $inv->update(['reread_keep' => $unmatched === [] ? null : ['unmatched' => array_values(array_filter($unmatched))]]);
        });
        $this->revalidate($inv->fresh());
    }

    /** Os valores guardados → colunas da linha (o valor é o bruto; o líquido sai da revalidação). */
    private function lineValues(array $v): array
    {
        $out = [];
        foreach ($v as $f => $val) {
            $out[$f] = match ($f) {
                'unit_price' => $val === null ? null : number_format((float) $val, 6, '.', ''),
                'gross_total_cents' => $val,
                default => $val,
            };
        }
        if (array_key_exists('gross_total_cents', $out)) {
            $out['line_total_cents'] = $out['gross_total_cents'];
        }

        return $out;
    }

    /** A chave de uma linha lida: o código do artigo, ou a descrição (sem acentos nem espaços a mais). */
    private static function key(array $s): string
    {
        $code = trim((string) ($s['supplier_code'] ?? ''));

        return $code !== '' ? "c:{$code}" : 'i:' . Str::upper(preg_replace('/\s+/', ' ', Str::ascii(trim((string) ($s['item'] ?? '')))) ?? '');
    }
}
