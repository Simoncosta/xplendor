<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F3: liga uma Fatura OCR ao(s) documento(s) de fornecedor do PingWin, só com os
 * ESPELHOS (F1 documentos + F4 linhas + fornecedores). ⚠️ Nunca escreve no PingWin; a única
 * chamada viva é a PESQUISA de fornecedor por NIF (só leitura, e só no worker: $live).
 *
 * Regras (spike F2-0):
 *  1. Fornecedor: NIF do QR (A) só com dígitos = suppliers.tax_number só com dígitos. Sem ele no
 *     espelho → pesquisa viva por NIF (se $live); se nem assim → 'fornecedor_em_falta'.
 *  2. Documento — só do mesmo fornecedor, NÃO anulado e de tipo compatível com o D do QR:
 *     a. nº: último grupo de dígitos do G (sem zeros à esquerda) = docreference_number → 'lancada' (numero)
 *     b. total ao cêntimo e |docreference_date (ou doc_date) − F| ≤ 3 dias: 1 → 'lancada'
 *        (total_data, por confirmar); vários → 'possivel' com os candidatos
 *     c. fatura de guias: se a fatura refere guias (GT/GR/GD) → 'possivel' (modo guias) com os
 *        documentos do período das guias; sem guias e nada em a/b, os candidatos dos 35 dias até
 *        F ficam disponíveis para escolha, mas o estado é 'nao_lancada'
 *     d. nada → 'nao_lancada'
 *  3. Duplicada: outra fatura com o mesmo A + G (ou o mesmo ATCUD) → 'duplicada' (liga à original).
 *  4. Documento ligado que passou a anulado → as ligações caem e volta a procurar (aviso guardado).
 * Ligações confirmadas pela pessoa ou pelo nº nunca são refeitas automaticamente; "Desligar"
 * guarda os documentos rejeitados para não voltarem a ser ligados sozinhos.
 */
class OcrPingwinLinkService
{
    public const MISSING_SUPPLIER = 'fornecedor_em_falta';
    public const NOT_LAUNCHED = 'nao_lancada';
    public const POSSIBLE = 'possivel';
    public const LAUNCHED = 'lancada';
    public const LAUNCHED_GUIDES = 'lancada_guias';
    public const DUPLICATE = 'duplicada';

    public const VOIDED = '8003';
    public const FRC = '584955579139752236';
    public const DATE_WINDOW_DAYS = 3;
    public const GUIDES_FALLBACK_DAYS = 35;
    /** O nº da fatura (ex. "3") repete-se de ano para ano: só conta dentro desta janela. */
    public const NUMBER_WINDOW_DAYS = 120;
    public const CHOICE_WINDOW_DAYS = 45;

    /** Tipo do QR (D) → docconfig_id do PingWin compatíveis. */
    public const TYPES = [
        'FT' => ['1209'],
        'FR' => [self::FRC, '1209'],
        'FS' => [self::FRC, '1209'],
        'NC' => ['1205'],
        'ND' => ['1206'],
    ];
    public const DEFAULT_TYPES = ['1209', self::FRC];

    private const LINKABLE_STATUSES = ['por_validar', 'validada'];

    public function __construct(private readonly PingwinService $pingwin) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Normalização
    // ─────────────────────────────────────────────────────────────────────────

    public static function digits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    /** "FT FAR.2026/379" → "379"; "FAC 02002202601/024366" → "24366"; "0001" → "1"; sem dígitos → null. */
    public static function numberKey(?string $v): ?string
    {
        if (! preg_match_all('/\d+/', (string) $v, $m) || $m[0] === []) {
            return null;
        }
        $last = ltrim(end($m[0]), '0');

        return $last === '' ? '0' : $last;
    }

    public static function typesFor(?string $docType): array
    {
        return self::TYPES[strtoupper(trim((string) $docType))] ?? self::DEFAULT_TYPES;
    }

    /** Guias referidas num texto: "GT 3105/2026 de 02/05/2026" → [{ref, date}]. */
    public static function extractGuides(string $text): array
    {
        $out = [];
        if (preg_match_all('/\b(G[TRD])\s*([A-Z0-9.\-]*\s?\d+(?:\/\d+)?)(?:\s+(?:de|-)?\s*(\d{2})[\/\-.](\d{2})[\/\-.](\d{4}))?/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) {
                $ref = trim($g[1] . ' ' . trim($g[2]));
                $date = (isset($g[5]) && $g[5] !== '' && checkdate((int) $g[4], (int) $g[3], (int) $g[5])) ? "{$g[5]}-{$g[4]}-{$g[3]}" : null;
                $out[$ref] = ['ref' => $ref, 'date' => $date ?? ($out[$ref]['date'] ?? null)];
            }
        }

        return array_values($out);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ligação automática
    // ─────────────────────────────────────────────────────────────────────────

    /** Corre as regras para UMA fatura e grava o estado. $live = pode pesquisar o fornecedor no PingWin (worker). */
    public function link(OcrInvoice $inv, bool $live = false): OcrInvoice
    {
        if (! in_array($inv->status, self::LINKABLE_STATUSES, true)) {
            if ($inv->link_status !== null) {
                $inv->update(['link_status' => null, 'link_candidates' => null, 'link_search_pending' => false]);
            }

            return $inv;
        }
        $inv->loadMissing('summary');
        $state = ['link_checked_at' => now(), 'link_search_pending' => false, 'duplicate_of_id' => null];

        // 3. Duplicada
        if ($original = $this->duplicateOf($inv)) {
            $inv->pingwinLinks()->whereNull('confirmed_at')->delete();
            $inv->update(array_merge($state, ['link_status' => self::DUPLICATE, 'duplicate_of_id' => $original->id, 'link_candidates' => null, 'link_diff_cents' => null]));

            return $inv;
        }

        // 1. Fornecedor
        $suppliers = $this->suppliersFor($inv, $live);
        if ($suppliers->isEmpty()) {
            $inv->update($state + ['link_status' => self::MISSING_SUPPLIER, 'link_candidates' => null, 'link_diff_cents' => null]);

            return $inv;
        }
        if ($inv->supplier_id === null) {
            $state['supplier_id'] = ($suppliers->firstWhere('is_active', true) ?? $suppliers->first())->id;
        }
        $entities = $suppliers->pluck('pingwin_id')->filter()->map(fn ($v) => (string) $v)->values()->all();

        // 4. Ligações existentes: um documento anulado (ou desaparecido) desfaz a ligação.
        $links = $inv->pingwinLinks()->get();
        if ($links->isNotEmpty()) {
            $docs = $this->docs($inv->company_id, $links->pluck('docheader_id')->all());
            $gone = $links->filter(fn ($l) => ! isset($docs[$l->docheader_id]) || $docs[$l->docheader_id]->docstatus_id === self::VOIDED);
            if ($gone->isEmpty()) {
                $inv->update($state + $this->linkedState($inv, $links, $docs));

                return $inv;
            }
            $names = $gone->map(fn ($l) => $docs[$l->docheader_id]->document ?? $l->docheader_id)->implode(', ');
            $inv->pingwinLinks()->delete();
            $state['link_note'] = "O documento ligado ({$names}) foi anulado no PingWin.";
            Log::info('[OCR↔PingWin] documento ligado anulado — ligação desfeita', ['invoice_id' => $inv->id, 'docs' => $names]);
        }

        // 2. Procura
        $pool = $this->pool($inv, $entities);
        $state += $this->autoMatch($inv, $pool);
        $inv->update($state);

        return $inv;
    }

    /** a → b → c → d. Devolve os campos a gravar (e cria a ligação automática, se houver). */
    private function autoMatch(OcrInvoice $inv, Collection $pool): array
    {
        $f = $inv->issue_date ? CarbonImmutable::parse($inv->issue_date->toDateString()) : null;
        $total = $this->invoiceTotalCents($inv);
        $refDate = fn (PingwinSupplierDocument $d) => CarbonImmutable::parse(($d->docreference_date ?? $d->doc_date)->toDateString());
        $dist = fn (PingwinSupplierDocument $d) => $f ? abs($refDate($d)->diffInDays($f, false)) : PHP_INT_MAX;

        // a. Pelo nº
        $key = self::numberKey($inv->number);
        if ($key !== null) {
            $byNumber = $pool->filter(fn ($d) => $d->docreference_number !== null && self::numberKey($d->docreference_number) === $key
                && $dist($d) <= self::NUMBER_WINDOW_DAYS)->sortBy($dist)->values();
            if ($byNumber->isNotEmpty()) {
                return $this->autoLink($inv, [$byNumber->first()], OcrInvoicePingwinLink::NUMBER);
            }
        }

        // b. Total e data
        if ($total !== null && $total !== 0 && $f) {
            $byTotal = $pool->filter(fn ($d) => (int) $d->total_cents === $total && $dist($d) <= self::DATE_WINDOW_DAYS)->sortBy($dist)->values();
            if ($byTotal->count() === 1) {
                return $this->autoLink($inv, [$byTotal->first()], OcrInvoicePingwinLink::TOTAL_DATE);
            }
            if ($byTotal->count() > 1) {
                return ['link_status' => self::POSSIBLE, 'link_diff_cents' => null,
                    'link_candidates' => ['mode' => 'total_data', 'docs' => $byTotal->pluck('docheader_id')->all()]];
            }
        }

        // c. Fatura de guias (ou nada bateu): documentos do período das guias / dos 35 dias até F.
        $guides = array_values(array_filter((array) ($inv->guide_refs ?? [])));
        $period = $this->guidePeriod($guides, $f);
        $guideDocs = $period === null ? collect() : $pool->filter(function ($d) use ($refDate, $period) {
            $day = $refDate($d)->toDateString();

            return $day >= $period['from'] && $day <= $period['to'];
        })->sortBy(fn ($d) => $refDate($d)->toDateString() . $d->document)->values();

        $candidates = $period === null ? null : [
            'mode' => 'guias', 'docs' => $guideDocs->pluck('docheader_id')->all(),
            'period' => $period, 'guides' => count($guides),
        ];
        if ($guides !== [] && $guideDocs->isNotEmpty()) {
            return ['link_status' => self::POSSIBLE, 'link_diff_cents' => null, 'link_candidates' => $candidates];
        }

        // d. Nada (os candidatos dos 35 dias ficam para escolha manual)
        return ['link_status' => self::NOT_LAUNCHED, 'link_diff_cents' => null, 'link_candidates' => $candidates];
    }

    /** Período das guias (datas legíveis) ou, sem datas, os 35 dias até F. Nunca depois de F. */
    private function guidePeriod(array $guides, ?CarbonImmutable $f): ?array
    {
        $dates = array_values(array_filter(array_map(fn ($g) => $g['date'] ?? null, $guides)));
        if ($dates !== []) {
            sort($dates);
            $from = CarbonImmutable::parse($dates[0])->subDay();
            $to = CarbonImmutable::parse(end($dates))->addDays(self::DATE_WINDOW_DAYS);
            if ($f && $to->greaterThan($f)) {
                $to = $f;
            }

            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'source' => 'guias'];
        }
        if (! $f) {
            return null;
        }

        return ['from' => $f->subDays(self::GUIDES_FALLBACK_DAYS)->toDateString(), 'to' => $f->toDateString(), 'source' => '35_dias'];
    }

    private function autoLink(OcrInvoice $inv, array $docs, string $method): array
    {
        DB::transaction(function () use ($inv, $docs, $method) {
            $inv->pingwinLinks()->delete();
            foreach ($docs as $d) {
                OcrInvoicePingwinLink::create(['company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id,
                    'docheader_id' => $d->docheader_id, 'method' => $method]);
            }
        });
        $links = $inv->pingwinLinks()->get();

        return $this->linkedState($inv, $links, $this->docs($inv->company_id, $links->pluck('docheader_id')->all()));
    }

    private function linkedState(OcrInvoice $inv, Collection $links, array $docs): array
    {
        $sum = $links->sum(fn ($l) => (int) ($docs[$l->docheader_id]->total_cents ?? 0));
        $total = $this->invoiceTotalCents($inv);
        $guides = $links->count() > 1 || $links->contains('method', OcrInvoicePingwinLink::GUIDES);

        return [
            'link_status'     => $guides ? self::LAUNCHED_GUIDES : self::LAUNCHED,
            'link_diff_cents' => $total === null ? null : $sum - $total,
            'link_candidates' => null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ações da pessoa
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Confirma: sem ids, confirma a ligação automática atual; com ids, liga a esses documentos
     * (escolha de candidato, "Escolher outro" ou modo guias). Os documentos têm de ser da
     * empresa e não anulados. Devolve a fatura atualizada.
     */
    public function confirm(OcrInvoice $inv, array $docheaderIds, ?string $method, ?int $userId): OcrInvoice
    {
        $docheaderIds = array_values(array_unique(array_map('strval', $docheaderIds)));
        if ($docheaderIds === []) {
            $links = $inv->pingwinLinks()->get();
            if ($links->isEmpty()) {
                throw new \InvalidArgumentException('Não há nenhum documento ligado para confirmar.');
            }
            $inv->pingwinLinks()->update(['confirmed_by' => $userId, 'confirmed_at' => now()]);
            $inv->update(['link_note' => null] + $this->linkedState($inv, $links, $this->docs($inv->company_id, $links->pluck('docheader_id')->all())));

            return $inv;
        }

        $docs = $this->docs($inv->company_id, $docheaderIds);
        foreach ($docheaderIds as $id) {
            if (! isset($docs[$id])) {
                throw new \InvalidArgumentException('Documento do PingWin desconhecido.');
            }
            if ($docs[$id]->docstatus_id === self::VOIDED) {
                throw new \InvalidArgumentException("O documento {$docs[$id]->document} está anulado no PingWin.");
            }
        }
        $method = in_array($method, [OcrInvoicePingwinLink::NUMBER, OcrInvoicePingwinLink::TOTAL_DATE, OcrInvoicePingwinLink::GUIDES, OcrInvoicePingwinLink::MANUAL], true)
            ? $method : (count($docheaderIds) > 1 ? OcrInvoicePingwinLink::GUIDES : OcrInvoicePingwinLink::MANUAL);

        DB::transaction(function () use ($inv, $docheaderIds, $method, $userId) {
            $inv->pingwinLinks()->delete();
            foreach ($docheaderIds as $id) {
                OcrInvoicePingwinLink::create(['company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => $id,
                    'method' => $method, 'confirmed_by' => $userId, 'confirmed_at' => now()]);
            }
        });
        $links = $inv->pingwinLinks()->get();
        $rejected = array_values(array_diff((array) ($inv->link_rejected ?? []), $docheaderIds));
        $inv->update(['link_rejected' => $rejected ?: null, 'link_note' => null, 'link_checked_at' => now()]
            + $this->linkedState($inv, $links, $docs));

        return $inv;
    }

    /** Desligar: os documentos ligados passam a rejeitados (não voltam sozinhos) e volta a procurar. */
    public function unlink(OcrInvoice $inv): OcrInvoice
    {
        $ids = $inv->pingwinLinks()->pluck('docheader_id')->all();
        $inv->pingwinLinks()->delete();
        $inv->update(['link_rejected' => array_values(array_unique(array_merge((array) ($inv->link_rejected ?? []), $ids))) ?: null, 'link_note' => null]);

        return $this->link($inv->fresh(), false);
    }

    /** Volta a verificar as faturas dos últimos $days dias (madrugada, depois da sync de documentos e linhas). */
    public function relinkRecent(int $companyId, int $days = 90, bool $live = true): array
    {
        $count = ['checked' => 0, 'changed' => 0];
        OcrInvoice::where('company_id', $companyId)->whereIn('status', self::LINKABLE_STATUSES)
            ->where(fn ($q) => $q->where('issue_date', '>=', now()->subDays($days)->toDateString())
                ->orWhere('created_at', '>=', now()->subDays($days)))
            ->orderBy('id')->each(function (OcrInvoice $inv) use (&$count, $live) {
                $before = $inv->link_status;
                try {
                    $this->link($inv, $live);
                } catch (\Throwable $e) {
                    Log::warning('[OCR↔PingWin] verificação falhou', ['invoice_id' => $inv->id, 'error' => $e->getMessage()]);

                    return;
                }
                $count['checked']++;
                $count['changed'] += $inv->link_status !== $before ? 1 : 0;
            });

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apresentação (bloco "No PingWin")
    // ─────────────────────────────────────────────────────────────────────────

    public function present(OcrInvoice $inv): array
    {
        $links = $inv->pingwinLinks()->get();
        $cand = (array) ($inv->link_candidates ?? []);
        $ids = array_merge($links->pluck('docheader_id')->all(), (array) ($cand['docs'] ?? []));
        $docs = $this->docs($inv->company_id, $ids);
        $linkedElsewhere = $this->linkedElsewhere($inv, array_keys($docs));
        $total = $this->invoiceTotalCents($inv);

        $linked = $links->map(fn ($l) => isset($docs[$l->docheader_id]) ? $this->presentDoc($docs[$l->docheader_id], $linkedElsewhere) + [
            'method' => $l->method, 'confirmed' => $l->confirmed_at !== null, 'confirmed_at' => $l->confirmed_at?->toIso8601String(),
        ] : null)->filter()->values()->all();
        $candidates = collect($cand['docs'] ?? [])->map(fn ($id) => isset($docs[$id]) ? $this->presentDoc($docs[$id], $linkedElsewhere) : null)->filter()->values()->all();

        $original = $inv->duplicate_of_id ? OcrInvoice::where('company_id', $inv->company_id)->find($inv->duplicate_of_id) : null;

        return [
            'status'          => $inv->link_status,
            'diff'            => $inv->link_diff_cents === null ? null : $inv->link_diff_cents / 100,
            'invoice_total'   => $total === null ? null : $total / 100,
            'checked_at'      => $inv->link_checked_at?->toIso8601String(),
            'note'            => $inv->link_note,
            'search_pending'  => (bool) $inv->link_search_pending,
            'supplier'        => $this->presentSupplier($inv),
            'linked'          => $linked,
            'candidates_mode' => $cand['mode'] ?? null,
            'candidates'      => $candidates,
            'period'          => $cand['period'] ?? null,
            'guides'          => array_values((array) ($inv->guide_refs ?? [])),
            'choices'         => $this->choices($inv, $linkedElsewhere),
            'duplicate_of'    => $original ? ['id' => $original->id, 'number' => $original->number, 'status' => $original->status] : null,
            'compare'         => count($linked) === 1 ? $this->compareLines($inv, $linked[0]['docheader_id']) : null,
        ];
    }

    /** "Escolher outro": documentos do fornecedor (compatíveis, não anulados) à volta de F. */
    private function choices(OcrInvoice $inv, array $linkedElsewhere): array
    {
        $entities = $this->suppliersFor($inv, false)->pluck('pingwin_id')->filter()->map(fn ($v) => (string) $v)->all();
        if ($entities === []) {
            return [];
        }
        $f = $inv->issue_date;
        $docs = PingwinSupplierDocument::where('company_id', $inv->company_id)->whereIn('entity_pingwin_id', $entities)
            ->whereIn('docconfig_id', self::typesFor($inv->doc_type))
            ->where(fn ($q) => $q->whereNull('docstatus_id')->orWhere('docstatus_id', '!=', self::VOIDED))
            ->when($f, fn ($q) => $q->whereBetween('doc_date', [$f->copy()->subDays(self::CHOICE_WINDOW_DAYS)->toDateString(), $f->copy()->addDays(self::CHOICE_WINDOW_DAYS)->toDateString()]))
            ->orderByDesc('doc_date')->limit(100)->get();
        $linkedElsewhere += $this->linkedElsewhere($inv, $docs->pluck('docheader_id')->all());

        return $docs->map(fn ($d) => $this->presentDoc($d, $linkedElsewhere))->values()->all();
    }

    private function presentDoc(PingwinSupplierDocument $d, array $linkedElsewhere): array
    {
        return [
            'docheader_id'        => $d->docheader_id,
            'document'            => $d->document,
            'doctype'             => $d->doctype,
            'doc_date'            => $d->doc_date?->toDateString(),
            'docreference_number' => $d->docreference_number,
            'docreference_date'   => $d->docreference_date?->toDateString(),
            'total'               => $d->total_cents / 100,
            'paid'                => (bool) $d->paid,
            'store_name'          => $d->store_name,
            'voided'              => $d->docstatus_id === self::VOIDED,
            'linked_to_invoice'   => $linkedElsewhere[$d->docheader_id] ?? null,
        ];
    }

    private function presentSupplier(OcrInvoice $inv): array
    {
        $s = $this->suppliersFor($inv, false)->first();
        $nif = self::digits($inv->supplier_nif);
        $own = $nif !== '' && $nif === self::digits(Company::where('id', $inv->company_id)->value('nipc'));

        return [
            'nif'     => $nif === '' || $own ? null : $nif,
            'own_nif' => $own, // o NIF lido é o da própria empresa (leitura errada): nunca sugerir criá-lo
            'name'    => $s?->name ?? $inv->supplier_name,
            'id'      => $s?->id,
            'found'   => $s !== null,
            'prefill' => ['nif' => $nif === '' || $own ? null : $nif, 'name' => $inv->supplier_name],
        ];
    }

    /** Linhas OCR × PingWin (só informativo): nº, soma e as que não batem (pelo total da linha). */
    public function compareLines(OcrInvoice $inv, string $docheaderId): array
    {
        $ocr = $inv->lines()->get();
        $pw = PingwinSupplierDocumentLine::where('company_id', $inv->company_id)->where('docheader_id', $docheaderId)->orderBy('line_number')->get();
        $pool = $pw->keyBy('id');
        $unmatchedOcr = [];
        foreach ($ocr as $l) {
            $hit = $pool->first(fn ($p) => (int) $p->total_cents === (int) $l->line_total_cents
                && ($l->supplier_code === null || $p->supplier_code === null || $p->supplier_code === $l->supplier_code || $p->product_code === $l->supplier_code))
                ?? $pool->first(fn ($p) => (int) $p->total_cents === (int) $l->line_total_cents);
            if ($hit) {
                $pool->forget($hit->id);
            } else {
                $unmatchedOcr[] = ['code' => $l->supplier_code, 'description' => $l->item, 'quantity' => $l->quantity !== null ? (float) $l->quantity : null, 'total' => ((int) $l->line_total_cents) / 100];
            }
        }

        return [
            'ocr_count'    => $ocr->count(),
            'pw_count'     => $pw->count(),
            'ocr_sum'      => $ocr->sum(fn ($l) => (int) $l->line_total_cents) / 100,
            'pw_sum'       => $pw->sum('total_cents') / 100,
            'pw_synced'    => $pw->isNotEmpty() || PingwinSupplierDocument::where('company_id', $inv->company_id)->where('docheader_id', $docheaderId)->whereNotNull('lines_synced_at')->exists(),
            'unmatched_ocr' => $unmatchedOcr,
            'unmatched_pw' => $pool->values()->map(fn ($p) => ['code' => $p->supplier_code ?? $p->product_code, 'description' => $p->description, 'quantity' => $p->qnt !== null ? (float) $p->qnt : null, 'total' => $p->total_cents / 100])->all(),
        ];
    }

    /** Para a lista: Liquidado, Loja e o estado do documento lançado pela XPLENDOR. @return array<int, array{paid: ?bool, store: ?string, xplendor_status: ?string}> */
    public function listInfo(int $companyId, array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $rows = DB::table('ocr_invoice_pingwin_links as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereIn('l.ocr_invoice_id', $invoiceIds)
            ->get(['l.ocr_invoice_id', 'l.method', 'd.paid', 'd.store_name', 'd.docstatus_id']);
        $out = [];
        foreach ($rows->groupBy('ocr_invoice_id') as $id => $g) {
            $mine = $g->firstWhere('method', OcrInvoicePingwinLink::XPLENDOR);
            $out[(int) $id] = [
                'paid'  => $g->every(fn ($r) => (bool) $r->paid),
                'store' => $g->pluck('store_name')->filter()->unique()->implode(', ') ?: null,
                // FB-1: lançada pela XPLENDOR → "Rascunho" (8001) ou "Lançada" (fechada)
                'xplendor_status' => $mine ? (string) $mine->docstatus_id : null,
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────

    /** Fornecedores do espelho com o NIF (só dígitos); sem nenhum e $live → pesquisa viva (só leitura). */
    private function suppliersFor(OcrInvoice $inv, bool $live): Collection
    {
        $nif = self::digits($inv->supplier_nif);
        $own = self::digits(Company::where('id', $inv->company_id)->value('nipc'));
        if ($nif === '' || $nif === $own) {
            return collect(); // o NIF da própria empresa nunca é o fornecedor
        }
        $found = $this->mirrorByDigits($inv->company_id, $nif);
        if ($found->isEmpty() && $live) {
            try {
                $res = $this->pingwin->findSuppliersByNif($inv->company_id, $nif);
                foreach ($res['active'] ?? [] as $row) {
                    $this->mirrorFromLive($inv->company_id, (array) $row);
                }
                $found = $this->mirrorByDigits($inv->company_id, $nif);
            } catch (\Throwable $e) {
                Log::warning('[OCR↔PingWin] pesquisa viva por NIF falhou', ['invoice_id' => $inv->id, 'error' => $e->getMessage()]);
            }
        }

        return $found;
    }

    private function mirrorByDigits(int $companyId, string $nif): Collection
    {
        return PingwinSupplier::where('company_id', $companyId)->whereNotNull('pingwin_id')->whereNotNull('tax_number')
            ->orderByDesc('is_active')->orderBy('id')->get()
            ->filter(fn (PingwinSupplier $s) => self::digits($s->tax_number) === $nif)->values();
    }

    /** Linha viva do browserdataset de fornecedores → espelho (upsert por pingwin_id). */
    private function mirrorFromLive(int $companyId, array $row): void
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $str = fn ($k) => ($v = trim((string) ($row[$k] ?? ''))) === '' ? null : $v;
        $s = PingwinSupplier::firstOrNew(['company_id' => $companyId, 'pingwin_id' => $id]);
        $s->fill([
            'source'      => 'pingwin',
            'code'        => $str('code') ?? $s->code,
            'name'        => $str('name') ?? $str('description') ?? $s->name,
            'fiscal_name' => $str('fiscalname') ?? $s->fiscal_name,
            'tax_number'  => $str('tax_number') ?? $s->tax_number,
            'address'     => $str('base_address') ?? $s->address,
            'postal_code' => $str('postalcode') ?? $s->postal_code,
            'city'        => $str('postalcode_description') ?? $s->city,
            'is_active'   => true,
            'synced_at'   => now(),
        ])->save();
    }

    /** Documentos do mesmo fornecedor, tipo compatível, não anulados, não ligados a outra fatura, não rejeitados. */
    private function pool(OcrInvoice $inv, array $entities): Collection
    {
        $rejected = (array) ($inv->link_rejected ?? []);
        $taken = OcrInvoicePingwinLink::where('company_id', $inv->company_id)->where('ocr_invoice_id', '!=', $inv->id)->pluck('docheader_id')->all();

        return PingwinSupplierDocument::where('company_id', $inv->company_id)
            ->whereIn('entity_pingwin_id', $entities)
            ->whereIn('docconfig_id', self::typesFor($inv->doc_type))
            ->where(fn ($q) => $q->whereNull('docstatus_id')->orWhere('docstatus_id', '!=', self::VOIDED))
            ->whereNotIn('docheader_id', array_merge($rejected, $taken))
            ->get();
    }

    /** Outra fatura (não anulável: por validar/validada) com o mesmo A + G ou o mesmo ATCUD; a mais antiga. */
    private function duplicateOf(OcrInvoice $inv): ?OcrInvoice
    {
        $nif = self::digits($inv->supplier_nif);
        $number = preg_replace('/\s+/', '', (string) $inv->number);
        $atcud = trim((string) $inv->atcud);
        if (($nif === '' || $number === '') && $atcud === '') {
            return null;
        }

        return OcrInvoice::where('company_id', $inv->company_id)->where('id', '<', $inv->id)
            ->whereIn('status', self::LINKABLE_STATUSES)
            ->orderBy('id')->get(['id', 'supplier_nif', 'number', 'atcud', 'status'])
            ->first(fn (OcrInvoice $o) => ($atcud !== '' && trim((string) $o->atcud) === $atcud)
                || ($nif !== '' && $number !== '' && self::digits($o->supplier_nif) === $nif && preg_replace('/\s+/', '', (string) $o->number) === $number));
    }

    /** @return array<string, PingwinSupplierDocument> por docheader_id */
    private function docs(int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', array_values(array_unique($ids)))
            ->get()->keyBy('docheader_id')->all();
    }

    /** @return array<string, int> docheader_id → id da OUTRA fatura a que está ligado */
    private function linkedElsewhere(OcrInvoice $inv, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return OcrInvoicePingwinLink::where('company_id', $inv->company_id)->where('ocr_invoice_id', '!=', $inv->id)
            ->whereIn('docheader_id', $ids)->pluck('ocr_invoice_id', 'docheader_id')->map(fn ($v) => (int) $v)->all();
    }

    /** O (total) do QR; sem QR, o total do sumário. Cêntimos. */
    public function invoiceTotalCents(OcrInvoice $inv): ?int
    {
        $o = $inv->qr_data['fields']['O'] ?? null;
        if (is_numeric($o)) {
            return (int) round(((float) $o) * 100);
        }
        $inv->loadMissing('summary');

        return $inv->summary ? (int) $inv->summary->total_cents : null;
    }
}
