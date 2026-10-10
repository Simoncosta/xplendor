<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LaunchBlocked;
use App\Jobs\LaunchPingwinDocumentJob;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentWrite;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". Cria a Fatura de fornecedor (1209) em RASCUNHO (8001)
 * a partir da fatura OCR; depois a pessoa fecha-a (8002) ou anula-a (8003). Cada ação é uma escrita
 * (pingwin_document_writes) feita por um job no worker e confirmada por RELEITURA.
 *
 * ⚠️ O PingWin aceita fornecedores anulados e não valida nada antes do SAVE (spike FB-0): TODAS as
 * guardas são nossas — verificadas ao pedir e repetidas no job (com as verificações vivas):
 *   tipo FT · QR desta empresa e linhas a conferir (ou diferença aceite) · fornecedor ativo (e vivo)
 *   · F3 "não lançada" (e a lista viva não a tem) · linhas ligadas a artigos ativos · unidade de cada
 *   linha entre as do artigo · IVA conhecido · quantidade > 0 e preço ≥ 0 · acerto ≤ 0,05 € (estimado
 *   aqui, real no PingWin antes do SAVE) · uma escrita de cada vez.
 */
class OcrInvoiceLaunchService
{
    public const DOCCONFIG = '1209';
    public const DRAFT = '8001';
    public const CLOSED = '8002';
    public const VOIDED = '8003';
    public const DOCREF_ID = '1649601156562';   // "Documento de Referência" (lista docreference do modelo)
    public const DOCREF_MAX = 25;               // tamanho do docreference_number (datasetinfo)
    public const MAX_ADJUSTMENT_CENTS = 5;
    public const OUT_OF_SCOPE = 'Por agora, lançar à mão no PingWin.';

    private const UNIT_SYNONYMS = [
        'UN' => ['UN', 'UND', 'UNID', 'UNIDADE', 'U', 'UNI', 'UNIDADES', 'PC', 'PCS', 'PECA'],
        'KG' => ['KG', 'KGS', 'KILO', 'KILOS', 'QUILO', 'QUILOS', 'QUILOGRAMA', 'QUILOGRAMAS'],
        'LT' => ['L', 'LT', 'LTS', 'LITRO', 'LITROS'],
    ];

    public function __construct(
        private readonly PingwinService $pingwin,
        private readonly OcrLineArticleService $lines,
        private readonly OcrPingwinLinkService $links,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Guardas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * As guardas e o que a UI precisa: lista {key, ok, label, message}, a unidade de cada linha (com
     * as opções do artigo), o acerto estimado e o estado das escritas.
     */
    public function preview(OcrInvoice $inv): array
    {
        $inv->loadMissing(['lines', 'summary']);
        $lines = $inv->lines->sortBy('position')->values();
        $supplier = $this->lines->supplier($inv);
        $ownNif = OcrPingwinLinkService::digits(Company::where('id', $inv->company_id)->value('nipc'));
        $articles = PingwinCatalogItem::whereIn('id', $lines->pluck('article_id')->filter())->get()->keyBy('id');
        $usable = OcrLineArticleService::usable(PingwinCatalogItem::whereIn('id', $lines->pluck('article_id')->filter()))->pluck('id')->flip();

        $lineInfo = $lines->map(fn (OcrInvoiceLine $l) => $this->lineInfo($l, $articles[$l->article_id] ?? null))->values();
        $estimate = $this->estimate($inv, $lines);
        $writes = PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->orderByDesc('id')->get();
        $busy = $this->blockingWrite($inv, $writes);

        $g = [];
        $add = function (string $key, bool $ok, string $label, ?string $msg = null) use (&$g): void {
            $g[] = ['key' => $key, 'ok' => $ok, 'label' => $label, 'message' => $ok ? null : $msg];
        };
        $add('tipo', strtoupper((string) $inv->doc_type) === 'FT', 'Fatura (FT)', self::OUT_OF_SCOPE);
        $add('qr', (bool) $inv->qr_ok && $ownNif !== '' && OcrPingwinLinkService::digits($inv->buyer_nif) === $ownNif,
            'QR lido e da empresa', 'A fatura tem de ter o QR lido, com o NIF desta empresa como adquirente.');
        $add('conferencia', $inv->check_status === InvoiceOcrService::CHECK_OK || $inv->check_accepted_at !== null,
            'Linhas a conferir com o QR', 'As linhas não conferem com o QR: corrija-as, ou aceite a diferença ao lançar.');
        $add('fornecedor', $supplier !== null && $supplier->is_active && (bool) $supplier->pingwin_id,
            'Fornecedor ativo no PingWin', 'O fornecedor da fatura não existe ou está anulado no PingWin.');
        $add('nao_lancada', $inv->link_status === OcrPingwinLinkService::NOT_LAUNCHED,
            'Ainda não lançada no PingWin', $this->launchedMessage($inv));
        $add('artigos', $lines->isNotEmpty() && $lines->every(fn ($l) => $l->link_state === OcrInvoiceLine::LINKED && isset($usable[$l->article_id])),
            'Todas as linhas ligadas a artigos ativos', 'Há linhas por ligar a um artigo ativo.');
        $add('unidades', $lineInfo->every(fn ($l) => $l['unit_ok']), 'Unidade de cada linha', 'Escolha a unidade das linhas assinaladas.');
        $add('iva', $lines->every(fn ($l) => $l->vat_rate !== null && isset(OcrLineArticleService::TAXGROUPS[$l->vat_rate])),
            'IVA de cada linha', 'Há linhas sem taxa de IVA.');
        $add('valores', $lines->every(fn ($l) => (float) $l->quantity > 0 && $l->unit_price !== null && (float) $l->unit_price >= 0),
            'Quantidades e preços', 'Há linhas sem quantidade (> 0) ou sem preço.');
        $add('acerto', $estimate['adjustment_cents'] !== null && abs($estimate['adjustment_cents']) <= self::MAX_ADJUSTMENT_CENTS,
            'Total da fatura (acerto até 0,05 €)', $estimate['adjustment_cents'] === null
                ? 'Falta o total da fatura (QR).'
                : sprintf('As linhas dão %s € e a fatura %s € (diferença %s €).', number_format($estimate['computed_cents'] / 100, 2, ',', ' '),
                    number_format($estimate['target_cents'] / 100, 2, ',', ' '), number_format($estimate['adjustment_cents'] / 100, 2, ',', ' ')));
        $add('escrita', $busy === null, 'Sem outra escrita em curso', $busy);

        $draft = $this->draftDoc($inv);

        return [
            'can_launch'  => collect($g)->every('ok'),
            'guards'      => $g,
            'lines'       => $lineInfo,
            'estimate'    => $estimate,
            'supplier'    => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name, 'pingwin_id' => $supplier->pingwin_id] : null,
            'docreference' => ['number' => mb_substr((string) $inv->number, 0, self::DOCREF_MAX), 'truncated' => mb_strlen((string) $inv->number) > self::DOCREF_MAX,
                'date' => $inv->issue_date?->toDateString()],
            'defaults'    => $this->defaults($inv->company_id),
            'draft'       => $draft,
            'writes'      => $writes->take(5)->map(fn ($w) => $this->presentWrite($w))->values(),
        ];
    }

    private function launchedMessage(OcrInvoice $inv): string
    {
        return match ($inv->link_status) {
            OcrPingwinLinkService::LAUNCHED, OcrPingwinLinkService::LAUNCHED_GUIDES => 'A fatura já está lançada no PingWin.',
            OcrPingwinLinkService::POSSIBLE => 'Há documentos no PingWin que podem ser esta fatura: veja "No PingWin" primeiro.',
            OcrPingwinLinkService::DUPLICATE => 'Esta fatura é duplicada de outra.',
            OcrPingwinLinkService::MISSING_SUPPLIER => 'O fornecedor não existe no PingWin.',
            default => 'Verifique primeiro se a fatura já está no PingWin ("Procurar no PingWin").',
        };
    }

    /** Uma escrita em curso, ou um lançamento OK cujo documento não foi anulado, bloqueia outra. */
    private function blockingWrite(OcrInvoice $inv, Collection $writes): ?string
    {
        if ($writes->contains('status', PingwinDocumentWrite::PENDING)) {
            return 'Há uma escrita no PingWin em curso para esta fatura.';
        }
        if ($writes->contains('status', PingwinDocumentWrite::CONFIRM_ERROR)) {
            return 'Um lançamento anterior pode ter gravado no PingWin sem confirmação: reveja-o antes de tentar outra vez.';
        }
        $launch = $writes->first(fn ($w) => $w->action === PingwinDocumentWrite::LAUNCH && $w->status === PingwinDocumentWrite::OK);
        if ($launch && ! $writes->contains(fn ($w) => $w->action === PingwinDocumentWrite::VOID && $w->status === PingwinDocumentWrite::OK && $w->id > $launch->id)) {
            return 'Esta fatura já foi lançada pela XPLENDOR (' . $launch->document . ').';
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Unidades
    // ─────────────────────────────────────────────────────────────────────────

    /** Unidades do artigo (ids do PingWin): as do artigo + as embalagens dele + as que o nome indica. */
    public function articleUnits(PingwinCatalogItem $a): Collection
    {
        $ids = collect([$a->default_purchase_unit_id, $a->base_unit_id, $a->default_stock_unit_id, $a->default_sale_unit_id])->filter()->map(fn ($v) => (string) $v);
        $units = PingwinUnit::where('company_id', $a->company_id)
            ->where(fn ($q) => $q->whereIn('pingwin_id', $ids->all())
                ->orWhere('product_pingwin_id', $a->pingwin_id)
                ->orWhereIn('description', array_filter([$a->purchaseunit, $a->saleunit])))
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->get(['pingwin_id', 'description', 'shortname', 'product_pingwin_id']);

        return $units->unique('pingwin_id')->map(fn ($u) => [
            'id' => (string) $u->pingwin_id, 'label' => trim($u->description . ($u->shortname ? " ({$u->shortname})" : '')),
            'short' => $u->shortname, 'description' => $u->description,
        ])->sortBy(fn ($u) => [$u['id'] === (string) $a->default_purchase_unit_id ? 0 : 1, $u['label']])->values();
    }

    /** A unidade da linha: a escolhida pela pessoa, ou a do OCR se corresponder a UMA do artigo. */
    private function lineInfo(OcrInvoiceLine $l, ?PingwinCatalogItem $a): array
    {
        $options = $a ? $this->articleUnits($a) : collect();
        $chosen = $l->launch_unit_id && $options->contains('id', $l->launch_unit_id) ? $l->launch_unit_id : null;
        $auto = null;
        if (! $chosen && $a) {
            $want = self::unitKey($l->unit);
            $hits = $want === null ? collect() : $options->filter(fn ($u) => self::unitKey($u['short']) === $want || self::unitKey($u['description']) === $want);
            $auto = $hits->count() === 1 ? $hits->first()['id'] : null;
        }

        return [
            'id'           => $l->id,
            'position'     => $l->position,
            'item'         => $l->item,
            'ocr_unit'     => $l->unit,
            'quantity'     => $l->quantity !== null ? (float) $l->quantity : null,
            'article'      => $a ? ['id' => $a->id, 'code' => $a->code, 'description' => $a->description, 'pingwin_id' => $a->pingwin_id] : null,
            'unit_options' => $options->map(fn ($u) => ['value' => $u['id'], 'label' => $u['label']])->values(),
            'unit_id'      => $chosen ?? $auto,
            'unit_source'  => $chosen ? 'escolhida' : ($auto ? 'fatura' : null),
            'unit_ok'      => ($chosen ?? $auto) !== null,
        ];
    }

    /** "KG", "Kgs.", "Quilograma" → KG; "Un", "UND" → UN; "L", "Litro" → LT; outras: o próprio texto. */
    public static function unitKey(?string $u): ?string
    {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) $u)) ?? '');
        if ($k === '') {
            return null;
        }
        foreach (self::UNIT_SYNONYMS as $key => $syn) {
            if (in_array($k, $syn, true)) {
                return $key;
            }
        }

        return $k;
    }

    /** A pessoa escolhe a unidade de uma linha (e, se for preciso, corrige a quantidade). */
    public function setLineUnit(OcrInvoice $inv, OcrInvoiceLine $line, string $unitId, ?float $quantity): void
    {
        $a = $line->article_id ? PingwinCatalogItem::where('company_id', $inv->company_id)->find($line->article_id) : null;
        if (! $a || ! $this->articleUnits($a)->contains('id', $unitId)) {
            throw new \InvalidArgumentException('Unidade desconhecida para este artigo.');
        }
        $data = ['launch_unit_id' => $unitId];
        if ($quantity !== null) {
            if ($quantity <= 0) {
                throw new \InvalidArgumentException('A quantidade tem de ser maior que zero.');
            }
            $data['quantity'] = number_format($quantity, 6, '.', '');
        }
        $line->update($data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Acerto estimado
    // ─────────────────────────────────────────────────────────────────────────

    /** Σ linhas + IVA por linha (arredondado à linha, como o PingWin) vs O do QR. */
    public function estimate(OcrInvoice $inv, Collection $lines): array
    {
        $target = $this->links->invoiceTotalCents($inv);
        $net = 0;
        $tax = 0;
        foreach ($lines as $l) {
            $t = (int) $l->line_total_cents;
            $net += $t;
            $tax += (int) round($t * ((int) $l->vat_rate) / 100);
        }
        $computed = $net + $tax;

        return ['target_cents' => $target, 'computed_cents' => $computed, 'net_cents' => $net, 'tax_cents' => $tax,
            'adjustment_cents' => $target === null ? null : $target - $computed];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pedidos
    // ─────────────────────────────────────────────────────────────────────────

    /** Lançar: guardas → escrita "pendente" (com lock da fatura) → job. */
    public function requestLaunch(OcrInvoice $inv, ?int $userId, bool $acceptCheckDiff): PingwinDocumentWrite
    {
        return DB::transaction(function () use ($inv, $userId, $acceptCheckDiff) {
            $inv = OcrInvoice::whereKey($inv->id)->lockForUpdate()->firstOrFail(); // lock por fatura
            if ($acceptCheckDiff && $inv->check_status !== InvoiceOcrService::CHECK_OK && $inv->check_accepted_at === null) {
                $inv->update(['check_accepted_by' => $userId, 'check_accepted_at' => now()]);
            }
            $p = $this->preview($inv->fresh());
            if (! $p['can_launch']) {
                throw new LaunchBlocked(collect($p['guards'])->where('ok', false)->values()->all());
            }
            $doc = $this->buildDoc($inv->fresh(['lines', 'summary']), $p);
            $write = PingwinDocumentWrite::create([
                'company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'user_id' => $userId,
                'action' => PingwinDocumentWrite::LAUNCH, 'status' => PingwinDocumentWrite::PENDING,
                'payload' => ['doc' => $doc, 'estimate' => $p['estimate'], 'check_accepted' => $inv->fresh()->check_accepted_at !== null && $inv->check_status !== InvoiceOcrService::CHECK_OK],
            ]);
            LaunchPingwinDocumentJob::dispatch($write->id)->afterCommit();

            return $write;
        });
    }

    /** Fechar (8002) ou anular (8003) o rascunho lançado pela XPLENDOR. */
    public function requestStatus(OcrInvoice $inv, string $action, ?int $userId): PingwinDocumentWrite
    {
        return DB::transaction(function () use ($inv, $action, $userId) {
            $inv = OcrInvoice::whereKey($inv->id)->lockForUpdate()->firstOrFail();
            $draft = $this->draftDoc($inv);
            if (! $draft) {
                throw new \InvalidArgumentException('Esta fatura não tem um documento lançado pela XPLENDOR.');
            }
            if (PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->where('status', PingwinDocumentWrite::PENDING)->exists()) {
                throw new \InvalidArgumentException('Há uma escrita no PingWin em curso para esta fatura.');
            }
            $allowed = $action === PingwinDocumentWrite::CLOSE ? [self::DRAFT] : [self::DRAFT, self::CLOSED];
            if (! in_array($draft['docstatus_id'], $allowed, true)) {
                throw new \InvalidArgumentException($action === PingwinDocumentWrite::CLOSE ? 'Só se fecha um documento em rascunho.' : 'O documento já está anulado.');
            }
            $write = PingwinDocumentWrite::create([
                'company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'user_id' => $userId, 'action' => $action,
                'status' => PingwinDocumentWrite::PENDING, 'docheader_id' => $draft['docheader_id'], 'document' => $draft['document'],
                'payload' => ['docstatus_id' => $action === PingwinDocumentWrite::CLOSE ? self::CLOSED : self::VOIDED],
            ]);
            LaunchPingwinDocumentJob::dispatch($write->id)->afterCommit();

            return $write;
        });
    }

    /** O payload para o Python: preço e quantidade como string com 6 casas. */
    private function buildDoc(OcrInvoice $inv, array $p): array
    {
        $supplier = $this->lines->supplier($inv);
        $units = collect($p['lines'])->keyBy('id');
        $lines = [];
        foreach ($inv->lines->sortBy('position') as $l) {
            $a = PingwinCatalogItem::find($l->article_id);
            $lines[] = [
                'line_id'    => $l->id,
                'product_id' => (string) $a->pingwin_id,
                'unit_id'    => (string) $units[$l->id]['unit_id'],
                'qnt'        => number_format((float) $l->quantity, 6, '.', ''),
                'price'      => number_format((float) $l->unit_price, 6, '.', ''),
                'discount1'  => rtrim(rtrim(number_format((float) ($l->discount_pct ?? 0), 4, '.', ''), '0'), '.') ?: '0',
                'vat_rate'   => (int) $l->vat_rate,
            ];
        }

        return [
            'docconfig_id'        => self::DOCCONFIG,
            'docstatus_id'        => self::DRAFT,
            'entity_id'           => (string) $supplier->pingwin_id,
            'lines'               => $lines,
            'target_total'        => number_format($p['estimate']['target_cents'] / 100, 2, '.', ''),
            'max_adjustment'      => number_format(self::MAX_ADJUSTMENT_CENTS / 100, 2, '.', ''),
            'docreference_id'     => self::DOCREF_ID,
            'docreference_number' => mb_substr((string) $inv->number, 0, self::DOCREF_MAX),
            'docreference_date'   => $inv->issue_date ? $inv->issue_date->format('Ymd') . 'T00:00:00' : '',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Execução (no worker)
    // ─────────────────────────────────────────────────────────────────────────

    public function execute(int $writeId): PingwinDocumentWrite
    {
        $w = PingwinDocumentWrite::findOrFail($writeId);
        if ($w->status !== PingwinDocumentWrite::PENDING) {
            return $w; // idempotente: uma escrita nunca se repete
        }
        $w->update(['started_at' => now()]);
        $inv = OcrInvoice::with(['lines', 'summary'])->find($w->ocr_invoice_id);
        if (! $inv) {
            return $this->finish($w, PingwinDocumentWrite::ERROR, 'A fatura já não existe.');
        }

        return $w->action === PingwinDocumentWrite::LAUNCH ? $this->executeLaunch($w, $inv) : $this->executeStatus($w, $inv);
    }

    private function executeLaunch(PingwinDocumentWrite $w, OcrInvoice $inv): PingwinDocumentWrite
    {
        // 1. Guardas outra vez (o mundo pode ter mudado desde o pedido), sem contar com esta escrita.
        $p = $this->preview($inv);
        $failed = collect($p['guards'])->where('ok', false)->reject(fn ($g) => $g['key'] === 'escrita');
        if ($failed->isNotEmpty()) {
            return $this->finish($w, PingwinDocumentWrite::ERROR, 'Guardas: ' . $failed->pluck('message')->implode(' '));
        }
        $doc = $w->payload['doc'] ?? [];
        $supplier = $this->lines->supplier($inv);

        try {
            // 2. Fornecedor confirmado VIVO (ativo e o mesmo id).
            $live = $this->pingwin->findSuppliersByNif($inv->company_id, OcrPingwinLinkService::digits($inv->supplier_nif));
            if (! collect($live['active'] ?? [])->contains(fn ($r) => (string) ($r['id'] ?? '') === (string) $supplier->pingwin_id)) {
                return $this->finish($w, PingwinDocumentWrite::ERROR, 'O fornecedor não está ativo no PingWin (pesquisa viva por NIF).');
            }
            // 3. A lista viva do PingWin ainda não tem esta fatura?
            if ($found = $this->liveAlreadyLaunched($inv, (string) $supplier->pingwin_id)) {
                $this->links->link($inv->fresh(), false);

                return $this->finish($w, PingwinDocumentWrite::ERROR, "Já lançada no PingWin: {$found} (ligada à fatura).");
            }
            // 4. Lançar (o Python relê no fim).
            $res = $this->pingwin->launchSupplierInvoice($inv->company_id, $doc);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Lançar] falhou antes do SAVE', ['write_id' => $w->id, 'error' => $e->getMessage()]);

            return $this->finish($w, PingwinDocumentWrite::ERROR, $e->getMessage());
        }

        $r = $res['result'];
        $saved = $r['saved'] ?? false;
        $w->update(['result' => ['launch' => $r, 'reread' => $res['reread']], 'docheader_id' => $r['docheader_id'] ?? ($r['header_before_save']['id'] ?? null),
            'document' => $r['document'] ?? null]);
        if ($saved === false) {
            $msg = $r['error'] ?? 'O PingWin não gravou o documento.';
            if (($r['reason'] ?? null) === 'acerto') {
                $msg .= ' ' . $this->vatDiffText($inv, $r);
            }

            return $this->finish($w, PingwinDocumentWrite::ERROR, $msg);
        }
        if ($saved !== true) {
            return $this->finish($w, PingwinDocumentWrite::CONFIRM_ERROR, ($r['error'] ?? 'Não se sabe se o documento foi gravado.') . ' Reveja no PingWin antes de tentar outra vez.');
        }

        // 5. Confirmação por releitura.
        $problems = $this->confirm($inv, $doc, $r, $res['reread']);
        if ($problems !== []) {
            return $this->finish($w, PingwinDocumentWrite::CONFIRM_ERROR, 'Gravado (' . ($r['document'] ?? $r['docheader_id']) . '), mas a releitura não confirma: ' . implode('; ', $problems) . '. Reveja no PingWin.');
        }
        $this->afterLaunch($inv, $w, $r, $res['reread'], $supplier);

        return $this->finish($w, PingwinDocumentWrite::OK, null);
    }

    /** A releitura (leitor da F4) tem de bater: nº, rascunho, fornecedor, total, referência, linhas. */
    public function confirm(OcrInvoice $inv, array $doc, array $r, ?array $reread): array
    {
        if (! $reread || ! ($reread['ok'] ?? false)) {
            return ['não foi possível reler o documento'];
        }
        $h = (array) ($reread['header'] ?? []);
        $details = (array) ($reread['details'] ?? []);
        $p = [];
        $before = (array) ($r['header_before_save'] ?? []);
        if ((string) ($h['doc_number'] ?? '') !== (string) ($before['doc_number'] ?? '') || (string) ($h['doc_prefix'] ?? '') !== (string) ($before['doc_prefix'] ?? '')) {
            $p[] = 'o número não é o esperado';
        }
        if ((string) ($h['docstatus_id'] ?? '') !== self::DRAFT) {
            $p[] = 'o estado não é rascunho (8001)';
        }
        if ((string) ($h['entity_id'] ?? '') !== (string) $doc['entity_id']) {
            $p[] = 'o fornecedor não é o da fatura';
        }
        if ((int) round(((float) ($h['total'] ?? 0)) * 100) !== (int) round(((float) $doc['target_total']) * 100)) {
            $p[] = "o total ({$h['total']}) não é o da fatura ({$doc['target_total']})";
        }
        if ((string) ($h['docreference_number'] ?? '') !== (string) $doc['docreference_number']) {
            $p[] = 'o nº da fatura do fornecedor não ficou';
        }
        if (count($details) !== count($doc['lines'])) {
            $p[] = 'o número de linhas não bate (' . count($details) . ' ≠ ' . count($doc['lines']) . ')';
        }
        $ocrNet = $inv->lines->sum(fn ($l) => (int) $l->line_total_cents);
        $pwNet = (int) collect($details)->sum(fn ($d) => (int) round(((float) ($d['total'] ?? 0)) * 100));
        if (abs($ocrNet - $pwNet) > count($doc['lines'])) { // tolerância: 1 cêntimo de arredondamento por linha
            $p[] = sprintf('a soma das linhas não bate (%.2f ≠ %.2f)', $pwNet / 100, $ocrNet / 100);
        }

        return $p;
    }

    /** OK: espelho do documento + linhas (F4) + ligação F3 confirmada (método "xplendor"). */
    private function afterLaunch(OcrInvoice $inv, PingwinDocumentWrite $w, array $r, array $reread, PingwinSupplier $supplier): void
    {
        $h = (array) $reread['header'];
        $doc = PingwinSupplierDocument::updateOrCreate(
            ['company_id' => $inv->company_id, 'docheader_id' => (string) $r['docheader_id']],
            [
                'docconfig_id' => self::DOCCONFIG, 'doctype' => 'Fatura de fornecedor', 'document' => $r['document'],
                'entity_pingwin_id' => (string) $supplier->pingwin_id, 'entity_name' => $supplier->name, 'fiscalname' => $supplier->fiscal_name,
                'tax_number' => $supplier->tax_number, 'supplier_id' => $supplier->id,
                'store_pingwin_id' => $h['store_id'] ?? null, 'store_name' => $this->storeName($inv->company_id, $h['store_id'] ?? null),
                'doc_date' => self::date($h['doc_date'] ?? null) ?? now()->toDateString(),
                'total_cents' => (int) round(((float) ($h['total'] ?? 0)) * 100), 'paid' => false,
                'docstatus_id' => self::DRAFT, 'docstatus_description' => 'Aberto',
                'docreference_number' => $h['docreference_number'] ?? null,
                'raw' => ['launched_by_xplendor' => true, 'write_id' => $w->id], 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]
        );
        app(SupplierDocumentLinesService::class)->apply($doc, $reread);
        DB::transaction(function () use ($inv, $w, $r) {
            $inv->pingwinLinks()->delete();
            OcrInvoicePingwinLink::create(['company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => (string) $r['docheader_id'],
                'method' => OcrInvoicePingwinLink::XPLENDOR, 'confirmed_by' => $w->user_id, 'confirmed_at' => now()]);
        });
        $this->links->link($inv->fresh(), false);
        Cache::forever("pingwin:launch-defaults:{$inv->company_id}", [
            'store' => $this->storeName($inv->company_id, $h['store_id'] ?? null), 'store_code' => $r['header_before_save']['store_code'] ?? null,
            'serie' => $h['doc_prefix'] ?? null, 'at' => now()->toIso8601String(),
        ]);
    }

    private function executeStatus(PingwinDocumentWrite $w, OcrInvoice $inv): PingwinDocumentWrite
    {
        $target = (string) ($w->payload['docstatus_id'] ?? '');
        try {
            $res = $this->pingwin->documentStatus($inv->company_id, (string) $w->docheader_id, $target);
        } catch (\Throwable $e) {
            // Pedido único OPEN,EDIT,SAVE,CLOSE: sem resposta não se sabe se mudou → rever.
            return $this->finish($w, PingwinDocumentWrite::CONFIRM_ERROR, $e->getMessage() . ' Reveja o estado no PingWin.');
        }
        $w->update(['result' => $res]);
        $status = (string) ($res['reread']['header']['docstatus_id'] ?? '');
        if (! ($res['result']['ok'] ?? false) && $status !== $target) {
            return $this->finish($w, PingwinDocumentWrite::ERROR, (string) ($res['result']['error'] ?? 'O PingWin recusou a mudança de estado.'));
        }
        if ($status !== $target) {
            return $this->finish($w, PingwinDocumentWrite::CONFIRM_ERROR, "A releitura mostra o estado {$status} (esperado {$target}). Reveja no PingWin.");
        }
        PingwinSupplierDocument::where('company_id', $inv->company_id)->where('docheader_id', $w->docheader_id)
            ->update(['docstatus_id' => $target, 'docstatus_description' => $target === self::CLOSED ? 'Fechado' : 'Anulado', 'last_seen_at' => now()]);
        $this->links->link($inv->fresh(), false); // anulado → a ligação cai e a fatura volta a "não lançada"

        return $this->finish($w, PingwinDocumentWrite::OK, null);
    }

    /** Documento do fornecedor já no PingWin (lista viva): total igual ±3 dias, ou o mesmo nº de referência. */
    private function liveAlreadyLaunched(OcrInvoice $inv, string $entity): ?string
    {
        $f = $inv->issue_date ? CarbonImmutable::parse($inv->issue_date->toDateString()) : CarbonImmutable::today();
        $from = $f->subDays(OcrPingwinLinkService::DATE_WINDOW_DAYS);
        $to = CarbonImmutable::today()->max($f->addDays(OcrPingwinLinkService::DATE_WINDOW_DAYS));
        $docs = $this->pingwin->fetchSupplierDocuments($inv->company_id, $from->toDateString(), $to->toDateString())['documents'];
        app(SupplierDocumentsService::class)->persist($inv->company_id, $docs); // o espelho fica em dia
        $total = $this->links->invoiceTotalCents($inv);
        $key = OcrPingwinLinkService::numberKey($inv->number);
        foreach ($docs as $d) {
            if ((string) ($d['entity_id'] ?? '') !== $entity || (string) ($d['docstatus_id'] ?? '') === self::VOIDED
                || ! in_array((string) ($d['docconfig_id'] ?? ''), OcrPingwinLinkService::typesFor($inv->doc_type), true)) {
                continue;
            }
            $sameTotal = (int) round(((float) ($d['total'] ?? 0)) * 100) === $total;
            $docDate = self::date($d['doc_date'] ?? null);
            $near = $docDate && abs(CarbonImmutable::parse($docDate)->diffInDays($f, false)) <= OcrPingwinLinkService::DATE_WINDOW_DAYS;
            $sameRef = $key !== null && OcrPingwinLinkService::numberKey($d['docreference_number'] ?? null) === $key;
            if (($sameTotal && $near) || $sameRef) {
                return (string) ($d['document'] ?? $d['id']);
            }
        }

        return null;
    }

    /** Erro do acerto: a diferença por taxa (linhas do PingWin vs QR). */
    private function vatDiffText(OcrInvoice $inv, array $r): string
    {
        $qr = collect($inv->qr_data['by_rate'] ?? [])->keyBy('rate');
        $byRate = [];
        foreach ((array) ($r['lines'] ?? []) as $l) {
            $rate = match (Str::lower(Str::ascii((string) ($l['tax_description'] ?? '')))) {
                'normal' => 23, 'intermedia' => 13, 'reduzida' => 6, 'isenta' => 0, default => null,
            };
            $byRate[$rate] = ($byRate[$rate] ?? 0) + (int) round(((float) ($l['total'] ?? 0)) * 100);
        }
        $parts = [];
        foreach (array_unique(array_merge(array_keys($byRate), $qr->keys()->all())) as $rate) {
            $pw = $byRate[$rate] ?? 0;
            $q = (int) ($qr[$rate]['base_cents'] ?? 0);
            if ($pw !== $q) {
                $parts[] = sprintf('IVA %s%%: PingWin %.2f € vs QR %.2f €', $rate ?? '?', $pw / 100, $q / 100);
            }
        }

        return $parts ? 'Diferença por taxa — ' . implode('; ', $parts) . '.' : '';
    }

    private function finish(PingwinDocumentWrite $w, string $status, ?string $error): PingwinDocumentWrite
    {
        $w->update(['status' => $status, 'error' => $error ? mb_substr($error, 0, 2000) : null, 'finished_at' => now()]);

        return $w;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apresentação
    // ─────────────────────────────────────────────────────────────────────────

    /** O documento lançado pela XPLENDOR (ligação F3 "xplendor") e o seu estado no espelho. */
    public function draftDoc(OcrInvoice $inv): ?array
    {
        $link = OcrInvoicePingwinLink::where('ocr_invoice_id', $inv->id)->where('method', OcrInvoicePingwinLink::XPLENDOR)->latest('id')->first();
        $docheader = $link?->docheader_id
            ?? PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->where('action', PingwinDocumentWrite::LAUNCH)->where('status', PingwinDocumentWrite::OK)->latest('id')->value('docheader_id');
        if (! $docheader) {
            return null;
        }
        $d = PingwinSupplierDocument::where('company_id', $inv->company_id)->where('docheader_id', $docheader)->first();
        if (! $d) {
            return null;
        }

        return ['docheader_id' => $d->docheader_id, 'document' => $d->document, 'docstatus_id' => $d->docstatus_id,
            'docstatus_label' => match ($d->docstatus_id) { self::DRAFT => 'Rascunho (Aberto)', self::CLOSED => 'Fechado', self::VOIDED => 'Anulado', default => $d->docstatus_description },
            'total' => $d->total_cents / 100, 'store_name' => $d->store_name, 'doc_date' => $d->doc_date?->toDateString()];
    }

    public function presentWrite(PingwinDocumentWrite $w): array
    {
        return ['id' => $w->id, 'action' => $w->action, 'status' => $w->status, 'document' => $w->document, 'docheader_id' => $w->docheader_id,
            'error' => $w->error, 'adjustment' => $w->result['launch']['adjustment'] ?? null,
            'tax_overrides' => collect($w->result['launch']['lines'] ?? [])->filter(fn ($l) => ! empty($l['tax_override']))->count(),
            'created_at' => $w->created_at?->toIso8601String(), 'finished_at' => $w->finished_at?->toIso8601String()];
    }

    /** Loja e série por omissão do PingWin (as do último lançamento; antes disso, as do spike FB-0). */
    private function defaults(int $companyId): array
    {
        return Cache::get("pingwin:launch-defaults:{$companyId}") ?? ['store' => 'Yuko BO', 'store_code' => 'BO', 'serie' => 'VFT BOVFT', 'at' => null];
    }

    private function storeName(int $companyId, ?string $storeId): ?string
    {
        if (! $storeId) {
            return null;
        }

        return DB::table('pingwin_stores')->where('company_id', $companyId)->where('external_id', $storeId)->value('description')
            ?? PingwinSupplierDocument::where('company_id', $companyId)->where('store_pingwin_id', $storeId)->whereNotNull('store_name')->value('store_name');
    }

    private static function date($v): ?string
    {
        return preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', trim((string) $v), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
