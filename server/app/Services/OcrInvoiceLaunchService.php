<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LaunchBlocked;
use App\Jobs\LaunchPingwinDocumentJob;
use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceDiscount;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentWrite;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinUnit;
use App\Models\ProductUnitProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". Cria a Fatura de fornecedor (1209), ou a Nota de crédito de
 * fornecedor (1205) quando o documento é uma nota de crédito, em RASCUNHO (8001)
 * a partir da fatura OCR; depois a pessoa fecha-a (8002) ou anula-a (8003). Cada ação é uma escrita
 * (pingwin_document_writes) feita por um job no worker e confirmada por RELEITURA.
 *
 * ⚠️ O PingWin aceita fornecedores anulados e não valida nada antes do SAVE (spike FB-0): TODAS as
 * guardas são nossas — verificadas ao pedir e repetidas no job (com as verificações vivas):
 *   tipo FT · QR desta empresa e linhas a conferir (ou diferença aceite) · fornecedor ativo (e vivo)
 *   · F3 "não lançada" (e a lista viva não a tem) · linhas ligadas a artigos ativos · unidade de cada
 *   linha entre as do artigo · IVA conhecido · quantidade > 0 e preço ≥ 0 · acerto ≤ 0,05 € (estimado
 *   aqui, real no PingWin antes do SAVE) · uma escrita de cada vez.
 *
 * Nota de crédito (1205): o mesmo protocolo e as mesmas guardas da fatura (HAR de 21/10); muda o tipo,
 * a data do documento é a da nota (não a do dia), o Documento de referência é o número da nota do
 * fornecedor com a data dela (a 1205 não tem tipo de referência: docreference_id vazio), os valores são
 * os da nota, positivos (o sinal vem do tipo), e o total das linhas tem de ser igual ao da nota (sem acerto).
 * As notas de débito continuam à mão.
 *
 * Fatura-recibo (FR): lança-se como "Fatura-recibo compra" (FR C, liquidada no ato), com o mesmo
 * protocolo (HAR de 23/10), a data da fatura e o Documento de referência; a forma de pagamento fica por
 * preencher. A fatura simplificada (FS) também: é paga no ato.
 *
 * Artigos de agrupamento (Cadastros › Artigos, definição da XPLENDOR): as linhas ligadas a um deles juntam-se
 * numa só (quantidade 1, preço = a soma dos valores das linhas, já com os descontos), só com a mesma taxa
 * de IVA. Unidades que não aceitam decimais (frac_unit 0): o PingWin arredonda a quantidade para cima; a
 * guarda "Unidade de cada linha" falha e o total estimado já simula esse arredondamento.
 *
 * Sem QR legível: lança-se só com o NIF do adquirente lido igual ao da empresa, o IVA por taxa a bater
 * com o quadro do IVA lido e "Conferi os totais com o papel" marcado (quem e quando).
 *
 * IEC numa coluna à parte (somado ao valor da linha, ex.: bebidas): lança-se como as faturas anteriores
 * do mesmo fornecedor no PingWin, numa linha à parte com o artigo do IEC (o da linha de IEC mais
 * recente desse fornecedor), quantidade 1 e o IEC total como preço, por taxa de IVA; a linha do
 * artigo leva o preço e o desconto lidos, sem o IEC. Sem essa linha no histórico, não se lança.
 */
class OcrInvoiceLaunchService
{
    public const DOCCONFIG = '1209';
    public const CREDIT_DOCCONFIG = '1205';      // Nota de crédito de fornecedor (V/NC, série VNC)
    public const DRAFT = '8001';
    public const CLOSED = '8002';
    public const VOIDED = '8003';
    public const DOCREF_ID = '1649601156562';   // "Documento de Referência" (lista docreference do modelo)
    public const DOCREF_MAX = 25;               // tamanho do docreference_number (datasetinfo)
    public const MAX_ADJUSTMENT_CENTS = 5;
    public const OUT_OF_SCOPE = 'Por agora, lançar à mão no PingWin.';
    public const FR_DOCCONFIG = OcrPingwinLinkService::FRC; // Fatura-recibo compra (FR C, série " BO")
    /** Os tipos que se lançam: o tipo do documento → o tipo do PingWin. */
    // A fatura simplificada (FS) é paga no ato: lança-se como a fatura-recibo (FR C).
    public const LAUNCHABLE = ['FT' => self::DOCCONFIG, 'NC' => self::CREDIT_DOCCONFIG, 'FR' => self::FR_DOCCONFIG, 'FS' => self::FR_DOCCONFIG];
    public const NO_TYPE = 'O QR não se leu e a leitura não identificou o tipo.';
    /** Notas de débito: ainda à mão. */
    public const DEBIT_NOTE_BLOCKED = 'As notas de débito ainda se lançam à mão no PingWin. Disponível em breve.';

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

    /** Porque é que este tipo de documento não se lança (só a Fatura, FT, se lança). */
    public static function typeBlockedMessage(?string $docType): string
    {
        return match (strtoupper(trim((string) $docType))) {
            'ND' => self::DEBIT_NOTE_BLOCKED,
            '' => self::NO_TYPE,
            default => self::OUT_OF_SCOPE,
        };
    }

    /**
     * O Documento de referência (25 carateres no PingWin): o número do fornecedor; se não couber, sem o
     * prefixo do tipo ("FS 0489064251229I824/052982" → "0489064251229I824/052982"); só depois se corta.
     */
    public static function docReference(?string $number, bool $cut = true): string
    {
        $n = trim((string) $number);
        if (mb_strlen($n) > self::DOCREF_MAX) {
            $n = (string) preg_replace('/^(FT|FS|FR|NC|ND|FAC|VD)\s+/iu', '', $n);
        }

        return $cut ? mb_substr($n, 0, self::DOCREF_MAX) : $n;
    }

    /** O rótulo da guarda do tipo: o tipo verdadeiro do documento ("Fatura-recibo (FR)", "Sem tipo"). */
    public static function typeLabel(?string $docType): string
    {
        $code = strtoupper(trim((string) $docType));
        if ($code === '') {
            return 'Sem tipo';
        }
        $name = AtInvoiceQr::docTypeLabel($code) ?? ['VD' => 'Venda a dinheiro', 'GT' => 'Guia de transporte', 'GR' => 'Guia de remessa', 'RC' => 'Recibo'][$code] ?? null;

        return $name ? "{$name} ({$code})" : "Tipo {$code}";
    }

    /** Nota de crédito (lança-se como 1205). */
    public static function isCreditNote(OcrInvoice $inv): bool
    {
        return strtoupper(trim((string) $inv->doc_type)) === 'NC';
    }

    /** O tipo de documento do PingWin: 1209 nas faturas, 1205 nas notas de crédito, FR C nas faturas-recibo. */
    public static function docconfigFor(OcrInvoice $inv): string
    {
        return self::LAUNCHABLE[strtoupper(trim((string) $inv->doc_type))] ?? self::DOCCONFIG;
    }

    /** O nome do tipo do PingWin (o espelho depois do lançamento). */
    private static function doctypeName(string $docconfig): string
    {
        return match ($docconfig) {
            self::CREDIT_DOCCONFIG => 'Nota de crédito de fornecedor',
            self::FR_DOCCONFIG => 'Fatura-recibo compra',
            default => 'Fatura de fornecedor',
        };
    }

    /**
     * Sem QR legível, o que falta para se poder lançar: o adquirente lido ser a empresa, o IVA por taxa
     * bater com o quadro do IVA lido e "Conferi os totais com o papel". Lista vazia = pode.
     *
     * @return list<string>
     */
    public function withoutQrMissing(OcrInvoice $inv, string $ownNif): array
    {
        $missing = [];
        if ($ownNif === '' || OcrPingwinLinkService::digits($inv->buyer_nif) !== $ownNif) {
            $missing[] = 'o NIF do adquirente lido ser o da empresa' . ($inv->buyer_nif ? " (lido: {$inv->buyer_nif})" : ' (não foi lido)');
        }
        if (! $this->readVatMatches($inv)) {
            $missing[] = 'o IVA por taxa bater com o quadro do IVA lido';
        }
        if ($inv->paper_checked_at === null) {
            $missing[] = 'marcar "Conferi os totais com o papel"';
        }

        return $missing;
    }

    /** Sem QR: as linhas batem com o quadro do IVA lido na fatura (ou com o sumário), taxa a taxa. */
    private function readVatMatches(OcrInvoice $inv): bool
    {
        $v = app(OcrInvoiceRereadService::class)->vatByRate($inv);

        return $v['ok'] && in_array($v['source'], ['fatura', 'sumario'], true);
    }

    /** "Conferi os totais com o papel" (só sem QR): marca com quem e quando, ou desmarca. */
    public function setPaperCheck(OcrInvoice $inv, bool $checked, ?int $userId): void
    {
        if ($inv->qr_ok) {
            throw new \InvalidArgumentException('Esta fatura tem o QR lido: a conferência é feita pelo QR.');
        }
        $inv->update($checked ? ['paper_checked_by' => $userId, 'paper_checked_at' => now()] : ['paper_checked_by' => null, 'paper_checked_at' => null]);
    }

    /**
     * As guardas e o que a UI precisa: lista {key, ok, label, message}, a unidade de cada linha (com
     * as opções do artigo), o acerto estimado e o estado das escritas.
     */
    public function preview(OcrInvoice $inv): array
    {
        $inv->loadMissing(['lines', 'summary', 'discounts']);
        $lines = $inv->lines->sortBy('position')->values();
        $supplier = $this->lines->supplier($inv);
        $ownNif = OcrPingwinLinkService::digits(Company::where('id', $inv->company_id)->value('nipc'));
        $articles = PingwinCatalogItem::whereIn('id', $lines->pluck('article_id')->filter())->get()->keyBy('id');
        $usable = OcrLineArticleService::usable(PingwinCatalogItem::whereIn('id', $lines->pluck('article_id')->filter()))->pluck('id')->flip();

        $grouping = $this->groupingArticles($inv, $articles);
        $lineInfo = $lines->map(function (OcrInvoiceLine $l) use ($articles, $grouping) {
            $info = $this->lineInfo($l, $articles[$l->article_id] ?? null);
            // Linha de um artigo de agrupamento: vai junta, com quantidade 1 (a unidade é a do artigo).
            if (isset($grouping[$l->article_id])) {
                $info = ['grouped' => true, 'unit_id' => $this->groupUnit($articles[$l->article_id]), 'unit_source' => 'agrupamento', 'unit_ok' => true] + $info;
            }

            return $info;
        })->values();
        $iec = $lines->contains(fn ($l) => (int) $l->iec_cents !== 0) ? $this->iecArticle($inv) : null;
        $estimate = $this->estimate($inv, $lines, $iec, $grouping, $lineInfo->pluck('unit_id', 'id')->all());
        $writes = PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->orderByDesc('id')->get();
        $busy = $this->blockingWrite($inv, $writes);

        $g = [];
        $add = function (string $key, bool $ok, string $label, ?string $msg = null) use (&$g): void {
            $g[] = ['key' => $key, 'ok' => $ok, 'label' => $label, 'message' => $ok ? null : $msg];
        };
        $credit = self::isCreditNote($inv);
        // O rótulo é o tipo verdadeiro do documento; só FT, NC e FR se lançam.
        $add('tipo', isset(self::LAUNCHABLE[strtoupper(trim((string) $inv->doc_type))]), self::typeLabel($inv->doc_type), self::typeBlockedMessage($inv->doc_type));
        if ($inv->qr_ok) {
            $add('qr', $ownNif !== '' && OcrPingwinLinkService::digits($inv->buyer_nif) === $ownNif,
                'QR lido e da empresa', 'A fatura tem de ter o QR lido, com o NIF desta empresa como adquirente.');
            $add('conferencia', $inv->check_status === InvoiceOcrService::CHECK_OK || $inv->check_accepted_at !== null,
                'Linhas a conferir com o QR', 'As linhas não conferem com o QR: corrija-as, ou aceite a diferença ao lançar.');
        } else {
            // Sem QR legível: o adquirente lido, o IVA lido a bater e a conferência com o papel.
            $missing = $this->withoutQrMissing($inv, $ownNif);
            $add('qr', $missing === [], 'Sem QR: adquirente, IVA lido e totais conferidos com o papel',
                'Sem QR legível, falta: ' . implode('; ', $missing) . '.');
            $add('conferencia', $this->readVatMatches($inv), 'Linhas a conferir com o quadro do IVA lido',
                'As linhas não batem com o quadro do IVA lido na fatura: corrija-as.');
        }
        $add('fornecedor', $supplier !== null && $supplier->is_active && (bool) $supplier->pingwin_id,
            'Fornecedor ativo no PingWin', 'O fornecedor da fatura não existe ou está anulado no PingWin.');
        $add('nao_lancada', $inv->link_status === OcrPingwinLinkService::NOT_LAUNCHED,
            'Ainda não lançada no PingWin', $this->launchedMessage($inv));
        $add('artigos', $lines->isNotEmpty() && $lines->every(fn ($l) => $l->link_state === OcrInvoiceLine::LINKED && isset($usable[$l->article_id])),
            'Todas as linhas ligadas a artigos ativos', 'Há linhas por ligar a um artigo ativo.');
        // A unidade de cada linha (as de agrupamento não contam) e as quantidades com decimais numa unidade
        // que não os aceita (o PingWin arredondava-as para cima).
        $unitMsgs = $lineInfo->every(fn ($l) => $l['unit_ok']) ? [] : ['Escolha a unidade das linhas assinaladas.'];
        foreach (collect($estimate['planned'])->where('kind', 'linha')->whereNotNull('rounded_from') as $row) {
            $l = $lines->firstWhere('id', $row['line_id']);
            $unitMsgs[] = sprintf('O artigo %s é à unidade e a linha tem %s%s: o PingWin arredonda para %s. Confirme a unidade no Guião de unidades ou marque o artigo como artigo de agrupamento.',
                $row['article']['description'] ?? (string) $l?->item, self::qty($row['rounded_from']), $l?->unit ? ' ' . mb_strtolower((string) $l->unit) : '', self::qty($row['qnt']));
        }
        $add('unidades', $unitMsgs === [], 'Unidade de cada linha', implode(' ', $unitMsgs));
        // Artigos de agrupamento: só se juntam linhas com a mesma taxa de IVA.
        $groupRows = collect($estimate['planned'])->where('kind', 'grupo');
        if ($groupRows->isNotEmpty()) {
            $mixed = $groupRows->groupBy(fn ($r) => $r['article']['id'])->filter(fn ($rows) => $rows->count() > 1);
            $add('agrupamento', $mixed->isEmpty(), 'Linhas dos artigos de agrupamento juntas numa só',
                $mixed->map(fn ($rows) => sprintf('As linhas do artigo %s têm taxas de IVA diferentes; crie um artigo por taxa.', $rows->first()['article']['description']))->implode(' '));
        }
        if ($lines->contains(fn ($l) => (int) $l->iec_cents !== 0)) {
            $add('iec', $iec !== null, 'IEC numa linha à parte, como nas faturas anteriores',
                'As linhas têm IEC numa coluna à parte, mas nenhuma fatura deste fornecedor no PingWin tem a linha do IEC para seguir. Lance esta fatura à mão.');
        }
        $add('iva', $lines->every(fn ($l) => $l->vat_rate !== null && isset(OcrLineArticleService::TAXGROUPS[$l->vat_rate])),
            'IVA de cada linha', 'Há linhas sem taxa de IVA.');
        // Os descontos de promoção ficam no artigo (não são linhas): aqui conta só o artigo.
        $add('valores', $lines->every(fn ($l) => (float) $l->quantity > 0 && $l->unit_price !== null && (float) $l->unit_price >= 0),
            'Quantidades e preços', 'Há linhas sem quantidade (> 0) ou sem preço.');
        $unassigned = $inv->discounts->where('status', OcrInvoiceDiscount::UNASSIGNED);
        $add('descontos', $unassigned->isEmpty(), 'Descontos no seu artigo',
            'Desconto por atribuir: ' . $unassigned->map(fn ($d) => trim(($d->label ?? 'Desconto') . ' ' . $d->code) . ' (' . self::eur($d->amount_cents) . ')')->implode('; ')
            . '. Corrija o código da promoção na linha do artigo e carregue em "Revalidar".');
        // Nota de crédito: o total das linhas tem de ser igual ao da nota (sem acerto).
        $maxAdj = $credit ? 0 : self::MAX_ADJUSTMENT_CENTS;
        $add('acerto', $estimate['adjustment_cents'] !== null && abs($estimate['adjustment_cents']) <= $maxAdj,
            $credit ? 'Total das linhas igual ao total da nota' : 'Total da fatura (acerto até 0,05 €)', $estimate['adjustment_cents'] === null
                ? 'Falta o total da fatura (QR).'
                : sprintf('As linhas dão %s € e a fatura %s € (diferença %s €).', number_format($estimate['computed_cents'] / 100, 2, ',', ' '),
                    number_format($estimate['target_cents'] / 100, 2, ',', ' '), number_format($estimate['adjustment_cents'] / 100, 2, ',', ' ')));
        $add('escrita', $busy === null, 'Sem outra escrita em curso', $busy);

        $draft = $this->draftDoc($inv);
        $close = $this->closeInfo($inv, $g, $lines, $articles, $draft, $grouping);

        return [
            'can_launch'  => collect($g)->every('ok'),
            'guards'      => $g,
            'close'       => $close,
            'discount_warnings' => $inv->discounts->pluck('warning')->filter()->values(),
            'lines'       => $lineInfo,
            'estimate'    => $estimate,
            // O que iria para o PingWin: cada linha (e a do IEC) com o preço, o desconto, o IVA e o total.
            // Sem QR: "Conferi os totais com o papel" (quem e quando).
            'paper_check' => $inv->qr_ok ? null : ['checked' => $inv->paper_checked_at !== null, 'at' => $inv->paper_checked_at?->toIso8601String(),
                'by_name' => $inv->paper_checked_by ? User::whereKey($inv->paper_checked_by)->value('name') : null,
                'missing' => $this->withoutQrMissing($inv, $ownNif)],
            'planned'     => ['lines' => $estimate['planned'], 'total_cents' => $estimate['computed_cents'], 'target_cents' => $estimate['target_cents'],
                'iec_from' => $iec['document'] ?? null],
            'supplier'    => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name, 'pingwin_id' => $supplier->pingwin_id] : null,
            'docreference' => ['number' => self::docReference($inv->number), 'truncated' => mb_strlen(self::docReference($inv->number, false)) > self::DOCREF_MAX,
                'date' => $inv->issue_date?->toDateString()],
            'defaults'    => $this->defaults($inv->company_id),
            'draft'       => $draft,
            'writes'      => $writes->take(5)->map(fn ($w) => $this->presentWrite($w))->values(),
        ];
    }

    /**
     * "Gravar no PingWin como fechada": só com todas as validações a verde e todas as unidades das
     * linhas confirmadas no Guião de unidades. mode: launch (lança e fecha) | draft (fecha o rascunho).
     */
    private function closeInfo(OcrInvoice $inv, array $guards, Collection $lines, Collection $articles, ?array $draft, array $grouping = []): array
    {
        if ($draft && $draft['docstatus_id'] === self::CLOSED) {
            return ['mode' => null, 'can_close' => false, 'blockers' => [['key' => 'fechada', 'label' => 'Fechada', 'message' => 'A fatura já está fechada no PingWin.']],
                'closed_at' => $inv->closed_at?->toIso8601String(), 'closed_by_name' => $inv->closed_by ? User::whereKey($inv->closed_by)->value('name') : null];
        }
        $mode = $draft && $draft['docstatus_id'] === self::DRAFT ? 'draft' : 'launch';
        // Um rascunho já lançado: "Ainda não lançada" e "Sem outra escrita" (o próprio lançamento) não contam.
        $skip = $mode === 'draft' ? ['nao_lancada', 'escrita'] : [];
        $blockers = collect($guards)->where('ok', false)->reject(fn ($x) => in_array($x['key'], $skip, true))
            ->map(fn ($x) => ['key' => $x['key'], 'label' => $x['label'], 'message' => $x['message']])->values()->all();
        if ($mode === 'draft' && PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->where('status', PingwinDocumentWrite::PENDING)->exists()) {
            $blockers[] = ['key' => 'escrita', 'label' => 'Sem outra escrita em curso', 'message' => 'Há uma escrita no PingWin em curso para esta fatura.'];
        }
        // Os artigos de agrupamento vão com quantidade 1: não precisam das unidades confirmadas.
        if ($missing = $this->unconfirmedUnits($inv, $lines->reject(fn ($l) => isset($grouping[$l->article_id])), $articles)) {
            $names = array_slice($missing, 0, 5);
            $blockers[] = ['key' => 'unidades_confirmadas', 'label' => 'Unidades confirmadas no Guião de unidades',
                'message' => 'Falta confirmar as unidades no Guião de unidades: ' . implode(', ', $names)
                    . (count($missing) > 5 ? sprintf(' e mais %d', count($missing) - 5) : '') . '.'];
        }

        return ['mode' => $mode, 'can_close' => $blockers === [], 'blockers' => $blockers, 'closed_at' => null, 'closed_by_name' => null];
    }

    /** Os artigos das linhas cujas unidades ainda não foram confirmadas (confirmado ou aplicado no PingWin). */
    private function unconfirmedUnits(OcrInvoice $inv, Collection $lines, Collection $articles): array
    {
        $ids = $lines->pluck('article_id')->filter()->unique()->map(fn ($id) => $articles[$id] ?? null)->filter();
        $confirmed = ProductUnitProfile::where('company_id', $inv->company_id)
            ->whereIn('product_pingwin_id', $ids->pluck('pingwin_id')->map(fn ($v) => (string) $v)->all())
            ->whereIn('state', ['confirmado', 'aplicado_no_pingwin'])->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->flip();
        $missing = $ids->reject(fn ($a) => isset($confirmed[(string) $a->pingwin_id]))->map(fn ($a) => (string) $a->description);
        // Linha sem artigo: também falta (o guarda "artigos" já o diz; aqui conta como unidade por confirmar).
        $noArticle = $lines->whereNull('article_id')->map(fn ($l) => (string) ($l->item ?? 'linha sem artigo'));

        return $missing->merge($noArticle)->unique()->values()->all();
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    private function launchedMessage(OcrInvoice $inv): string
    {
        if (self::docconfigFor($inv) !== self::DOCCONFIG && in_array($inv->link_status, [OcrPingwinLinkService::LAUNCHED, OcrPingwinLinkService::LAUNCHED_GUIDES], true)) {
            $docs = PingwinSupplierDocument::where('company_id', $inv->company_id)->whereIn('docheader_id', $inv->pingwinLinks()->pluck('docheader_id'))->pluck('document')->filter()->implode(', ');

            return 'Já está lançada no PingWin' . ($docs !== '' ? " ({$docs})" : '') . '.';
        }

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

    /** Σ linhas + IVA por linha (arredondado à linha, como o PingWin) vs O do QR. As linhas são as que vão (com a do IEC). */
    public function estimate(OcrInvoice $inv, Collection $lines, ?array $iec = null, array $grouping = [], array $units = []): array
    {
        $target = $this->links->invoiceTotalCents($inv);
        $planned = $this->plannedLines($lines, $iec, $grouping, $units);
        $net = array_sum(array_column($planned, 'net_cents'));
        $tax = array_sum(array_column($planned, 'tax_cents'));
        $computed = $net + $tax;

        return ['target_cents' => $target, 'computed_cents' => $computed, 'net_cents' => $net, 'tax_cents' => $tax,
            'adjustment_cents' => $target === null ? null : $target - $computed, 'planned' => $planned];
    }

    /**
     * As linhas que iriam para o PingWin, sem escrever nada: as da fatura (preço e desconto lidos; o
     * valor sem o IEC) e, se houver IEC numa coluna à parte, uma linha do IEC por taxa de IVA
     * (quantidade 1, preço = o IEC dessas linhas), como nas faturas anteriores do fornecedor.
     *
     * @return list<array{kind: string, line_id: ?int, item: ?string, article: ?array, qnt: float, price: float, discount_pct: float, promo_cents: int, vat_rate: int, net_cents: int, tax_cents: int, total_cents: int}>
     */
    public function plannedLines(Collection $lines, ?array $iec, array $grouping = [], array $units = []): array
    {
        $iecByRate = [];
        $articles = PingwinCatalogItem::whereIn('id', $lines->pluck('article_id')->filter())->get()->keyBy('id');
        $whole = $this->wholeUnits((int) ($lines->first()?->company_id ?? 0), array_values(array_filter($units)));
        // As linhas dos artigos de agrupamento, por artigo e taxa de IVA (o valor sem o IEC, já com os descontos).
        $groups = [];
        foreach ($lines->sortBy('position') as $l) {
            if (isset($grouping[$l->article_id])) {
                $groups["{$l->article_id}:" . (int) $l->vat_rate][] = $l;
            }
        }
        $out = [];
        $emitted = [];
        foreach ($lines->sortBy('position') as $l) {
            $iecCents = (int) $l->iec_cents;
            if ($iecCents !== 0) {
                $iecByRate[(int) $l->vat_rate] = ($iecByRate[(int) $l->vat_rate] ?? 0) + $iecCents;
            }
            $a = $articles[$l->article_id] ?? null;
            if (isset($grouping[$l->article_id])) {
                $key = "{$l->article_id}:" . (int) $l->vat_rate;
                if (! isset($emitted[$key])) {
                    $emitted[$key] = true;
                    $members = collect($groups[$key]);
                    $sum = (int) $members->sum(fn ($m) => (int) $m->line_total_cents - (int) $m->iec_cents);
                    $out[] = $this->plannedRow('grupo', null, $a?->description, $a, 1.0, $sum / 100, 0.0, 0, (int) $l->vat_rate, $sum)
                        + ['lines' => $members->count(), 'line_ids' => $members->pluck('id')->all(), 'rounded_from' => null];
                }

                continue;
            }
            $q = (float) $l->quantity;
            $net = (int) $l->line_total_cents - $iecCents;
            $rounded = null;
            // Unidade sem decimais e quantidade com decimais: o PingWin arredonda para cima (simulado aqui).
            if (isset($whole[(string) ($units[$l->id] ?? '')]) && abs($q - round($q)) > 1e-6) {
                $rounded = (float) ceil($q);
                $net = (int) round($rounded * (float) $l->unit_price * (1 - (float) ($l->discount_pct ?? 0) / 100) * 100) - (int) $l->discount_cents;
            }
            $out[] = $this->plannedRow('linha', $l->id, $l->item, $a, $rounded ?? $q, (float) $l->unit_price, (float) ($l->discount_pct ?? 0),
                (int) $l->discount_cents, (int) $l->vat_rate, $net) + ['rounded_from' => $rounded !== null ? $q : null];
        }
        foreach ($iecByRate as $rate => $cents) {
            $out[] = $this->plannedRow('iec', null, 'IEC', $iec['article'] ?? null, 1.0, $cents / 100, 0.0, 0, $rate, $cents) + ['rounded_from' => null];
        }

        return $out;
    }

    /** As unidades (pingwin_id) que não aceitam decimais (frac_unit 0 no PingWin). @return array<string, true> */
    private function wholeUnits(int $companyId, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return PingwinUnit::where('company_id', $companyId)->whereIn('pingwin_id', array_unique(array_map('strval', $unitIds)))->get(['pingwin_id', 'raw'])
            ->filter(function ($u) {
                $raw = is_array($u->raw) ? $u->raw : (array) json_decode((string) $u->raw, true);

                return array_key_exists('frac_unit', $raw) && ! (bool) $raw['frac_unit'];
            })->mapWithKeys(fn ($u) => [(string) $u->pingwin_id => true])->all();
    }

    /** Os artigos de agrupamento entre os das linhas (id do catálogo → true). */
    private function groupingArticles(OcrInvoice $inv, Collection $articles): array
    {
        $ids = \App\Models\ArticleGrouping::pingwinIdsFor($inv->company_id);

        return $articles->filter(fn ($a) => isset($ids[(string) $a->pingwin_id]))->mapWithKeys(fn ($a) => [$a->id => true])->all();
    }

    /** A unidade da linha junta (quantidade 1): a de compra do artigo, senão a base, senão a primeira. */
    private function groupUnit(PingwinCatalogItem $a): ?string
    {
        $u = (string) ($a->default_purchase_unit_id ?: ($a->base_unit_id ?: ($this->articleUnits($a)->first()['id'] ?? '')));

        return $u === '' ? null : $u;
    }

    /** 8.09 → "8,09"; 9.0 → "9". */
    private static function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, ',', ''), '0'), ',');
    }

    private function plannedRow(string $kind, ?int $lineId, ?string $item, ?PingwinCatalogItem $a, float $qnt, float $price, float $disc, int $promo, int $rate, int $net): array
    {
        $tax = (int) round($net * $rate / 100);

        return ['kind' => $kind, 'line_id' => $lineId, 'item' => $item,
            'article' => $a ? ['id' => $a->id, 'code' => $a->code, 'description' => $a->description, 'pingwin_id' => (string) $a->pingwin_id] : null,
            'qnt' => $qnt, 'price' => $price, 'discount_pct' => $disc, 'promo_cents' => $promo, 'vat_rate' => $rate,
            'net_cents' => $net, 'tax_cents' => $tax, 'total_cents' => $net + $tax];
    }

    /**
     * O artigo do IEC deste fornecedor: o da linha de IEC mais recente das faturas dele já no PingWin
     * (com a unidade dessa linha). Null se não houver nenhuma, ou se o artigo já não estiver ativo.
     *
     * @return array{article: PingwinCatalogItem, unit_id: string, document: ?string}|null
     */
    public function iecArticle(OcrInvoice $inv): ?array
    {
        $supplier = $this->lines->supplier($inv);
        if (! $supplier || ! $supplier->pingwin_id) {
            return null;
        }
        $rows = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $inv->company_id)->where('d.entity_pingwin_id', (string) $supplier->pingwin_id)
            ->where('d.docconfig_id', self::DOCCONFIG)->whereNotNull('l.product_pingwin_id')->where('l.description', 'like', '%IEC%')
            ->orderByDesc('d.doc_date')->orderByDesc('d.id')->limit(20)
            ->get(['l.product_pingwin_id', 'l.unit_id', 'l.description', 'd.document']);
        $row = $rows->first(fn ($r) => preg_match('/\bIEC\b/iu', (string) $r->description) === 1);
        if (! $row) {
            return null;
        }
        $a = OcrLineArticleService::usable(PingwinCatalogItem::where('company_id', $inv->company_id)->where('pingwin_id', (string) $row->product_pingwin_id))->first();
        if (! $a) {
            return null;
        }
        $unit = (string) ($row->unit_id ?: ($a->default_purchase_unit_id ?: ($this->articleUnits($a)->first()['id'] ?? '')));

        return $unit === '' ? null : ['article' => $a, 'unit_id' => $unit, 'document' => $row->document];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pedidos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lançar: guardas → escrita "pendente" (com lock da fatura) → job. $close = "Gravar no PingWin
     * como fechada": lança em rascunho e, confirmado pela releitura, fecha (8002), com as guardas do fecho.
     */
    public function requestLaunch(OcrInvoice $inv, ?int $userId, bool $acceptCheckDiff, bool $close = false): PingwinDocumentWrite
    {
        return DB::transaction(function () use ($inv, $userId, $acceptCheckDiff, $close) {
            $inv = OcrInvoice::whereKey($inv->id)->lockForUpdate()->firstOrFail(); // lock por fatura
            if ($acceptCheckDiff && $inv->check_status !== InvoiceOcrService::CHECK_OK && $inv->check_accepted_at === null) {
                $inv->update(['check_accepted_by' => $userId, 'check_accepted_at' => now()]);
            }
            $p = $this->preview($inv->fresh());
            if (! $p['can_launch']) {
                throw new LaunchBlocked(collect($p['guards'])->where('ok', false)->values()->all());
            }
            if ($close && ! $p['close']['can_close']) {
                throw new LaunchBlocked($p['close']['blockers']);
            }
            $doc = $this->buildDoc($inv->fresh(['lines', 'summary']), $p);
            $write = PingwinDocumentWrite::create([
                'company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'user_id' => $userId,
                'action' => PingwinDocumentWrite::LAUNCH, 'status' => PingwinDocumentWrite::PENDING,
                'payload' => ['doc' => $doc, 'estimate' => $p['estimate'], 'check_accepted' => $inv->fresh()->check_accepted_at !== null && $inv->check_status !== InvoiceOcrService::CHECK_OK,
                    'close_after' => $close],
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
            if ($action === PingwinDocumentWrite::CLOSE && ! ($c = $this->preview($inv)['close'])['can_close']) {
                throw new LaunchBlocked($c['blockers']);
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
        $byId = $inv->lines->keyBy('id');
        $iec = null;
        $lines = [];
        // As linhas que vão (as previstas): as da fatura, as juntas dos artigos de agrupamento e a do IEC.
        foreach ($p['planned']['lines'] ?? [] as $row) {
            if ($row['kind'] === 'linha') {
                $l = $byId[$row['line_id']];
                $a = PingwinCatalogItem::find($l->article_id);
                $lines[] = [
                    'line_id'    => $l->id,
                    'product_id' => (string) $a->pingwin_id,
                    'unit_id'    => (string) $units[$l->id]['unit_id'],
                    'qnt'        => number_format((float) $l->quantity, 6, '.', ''),
                    'price'      => number_format((float) $l->unit_price, 6, '.', ''),
                    'discount1'  => rtrim(rtrim(number_format((float) ($l->discount_pct ?? 0), 4, '.', ''), '0'), '.') ?: '0',
                    // Desconto de promoção em € na linha ("adjustment" negativo; spike de 19/10/2026).
                    'adjustment' => (int) $l->discount_cents > 0 ? '-' . number_format((int) $l->discount_cents / 100, 2, '.', '') : null,
                    'vat_rate'   => (int) $l->vat_rate,
                ];
            } elseif ($row['kind'] === 'grupo') {
                // Artigo de agrupamento: uma linha, quantidade 1, o preço é a soma dos valores das linhas.
                $a = PingwinCatalogItem::find($row['article']['id']);
                $lines[] = [
                    'line_id'    => null,
                    'line_ids'   => $row['line_ids'],
                    'product_id' => (string) $a->pingwin_id,
                    'unit_id'    => (string) $this->groupUnit($a),
                    'qnt'        => '1.000000',
                    'price'      => number_format($row['net_cents'] / 100, 6, '.', ''),
                    'discount1'  => '0',
                    'adjustment' => null,
                    'vat_rate'   => (int) $row['vat_rate'],
                ];
            } else {
                // O IEC numa linha à parte (como nas faturas anteriores do fornecedor): uma por taxa de IVA.
                $iec ??= $this->iecArticle($inv);
                $lines[] = [
                    'line_id'    => null,
                    'product_id' => (string) $iec['article']->pingwin_id,
                    'unit_id'    => $iec['unit_id'],
                    'qnt'        => '1.000000',
                    'price'      => number_format($row['net_cents'] / 100, 6, '.', ''),
                    'discount1'  => '0',
                    'adjustment' => null,
                    'vat_rate'   => (int) $row['vat_rate'],
                ];
            }
        }

        $credit = self::isCreditNote($inv);
        $docconfig = self::docconfigFor($inv);
        $doc = [
            'docconfig_id'        => $docconfig,
            'docstatus_id'        => self::DRAFT,
            'entity_id'           => (string) $supplier->pingwin_id,
            'lines'               => $lines,
            'target_total'        => number_format($p['estimate']['target_cents'] / 100, 2, '.', ''),
            // Nota de crédito: sem acerto (o total das linhas tem de ser o da nota).
            'max_adjustment'      => number_format(($credit ? 0 : self::MAX_ADJUSTMENT_CENTS) / 100, 2, '.', ''),
            // A 1205 não tem tipo de referência (a lista vem só com o vazio); o número e a data ficam.
            'docreference_id'     => $credit ? '' : self::DOCREF_ID,
            'docreference_number' => self::docReference($inv->number),
            'docreference_date'   => $inv->issue_date ? $inv->issue_date->format('Ymd') . 'T00:00:00' : '',
        ];
        // A data do documento é a da nota ou da fatura-recibo (à mão ficava a do dia do lançamento).
        if ($docconfig !== self::DOCCONFIG && $inv->issue_date) {
            $doc['doc_date'] = $inv->issue_date->format('Ymd') . 'T00:00:00';
        }

        return $doc;
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

                return $this->finish($w, PingwinDocumentWrite::ERROR, match (self::docconfigFor($inv)) {
                    self::CREDIT_DOCCONFIG => "Já está lançada no PingWin ({$found}). Ficou ligada à nota; nada foi criado.",
                    self::FR_DOCCONFIG => "Já está lançada no PingWin ({$found}). Ficou ligada à fatura-recibo; nada foi criado.",
                    default => "Já lançada no PingWin: {$found} (ligada à fatura).",
                });
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
        $this->finish($w, PingwinDocumentWrite::OK, null);

        // "Gravar como fechada": o rascunho confirmado fecha-se logo, numa escrita própria (com releitura).
        if (! empty($w->payload['close_after'])) {
            $close = PingwinDocumentWrite::create([
                'company_id' => $inv->company_id, 'ocr_invoice_id' => $inv->id, 'user_id' => $w->user_id, 'action' => PingwinDocumentWrite::CLOSE,
                'status' => PingwinDocumentWrite::PENDING, 'docheader_id' => (string) $r['docheader_id'], 'document' => $r['document'] ?? null,
                'payload' => ['docstatus_id' => self::CLOSED, 'after_launch' => $w->id], 'started_at' => now(),
            ]);
            $this->executeStatus($close, $inv->fresh(['lines', 'summary']));
        }

        return $w->fresh();
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
        if (isset($h['docconfig_id']) && (string) $h['docconfig_id'] !== (string) ($doc['docconfig_id'] ?? self::DOCCONFIG)) {
            $p[] = "o tipo do documento não é o esperado ({$h['docconfig_id']})";
        }
        if (isset($doc['doc_date']) && substr((string) ($h['doc_date'] ?? ''), 0, 8) !== substr((string) $doc['doc_date'], 0, 8)) {
            $p[] = 'a data do documento não é a da nota (' . (self::date($h['doc_date'] ?? null) ?? '?') . ')';
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
                'docconfig_id' => self::docconfigFor($inv), 'doctype' => self::doctypeName(self::docconfigFor($inv)), 'document' => $r['document'],
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
            $res = $this->pingwin->documentStatus($inv->company_id, (string) $w->docheader_id, $target, self::docconfigFor($inv));
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
        // O fecho fica registado na fatura: quem e quando (a escrita também o guarda).
        $inv->update($target === self::CLOSED ? ['closed_by' => $w->user_id, 'closed_at' => now()] : ['closed_by' => null, 'closed_at' => null]);
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
                return trim((string) ($d['document'] ?? $d['id']));
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

    /** Loja e série por omissão do PingWin: as do último lançamento da empresa (antes do primeiro, nenhuma). */
    private function defaults(int $companyId): array
    {
        return Cache::get("pingwin:launch-defaults:{$companyId}") ?? ['store' => null, 'store_code' => null, 'serie' => null, 'at' => null];
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
