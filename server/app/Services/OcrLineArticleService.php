<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CreatePingwinCatalogJob;
use App\Jobs\UpdatePingwinCatalogJob;
use App\Jobs\WriteArticleSupplierCodeJob;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCatalogWrite;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocumentLine;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use App\Support\Gtin;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * XPLENDOR — F2b: liga cada linha da fatura OCR a um artigo do catálogo espelhado, por esta
 * ordem (guarda o método e a confiança):
 *   a. MAPA: NIF do fornecedor + código do artigo NO FORNECEDOR → artigo ("aprendido");
 *   b. PINGWIN: com 1 documento ligado (F3), emparelha com as linhas desse documento (total,
 *      quantidade e preço; a ordem desempata) → o artigo vem do PingWin; com código do
 *      fornecedor, o par entra no mapa (ocr_link);
 *   b2. MEMÓRIA (só linhas sem código do fornecedor): NIF + descrição → o artigo da última ligação à
 *      mão (DescriptionMemoryService; "Ligada pela memória", pode desfazer-se);
 *   c. DESCRIÇÃO normalizada igual à de UM artigo → automático;
 *   d. descrição PARECIDA (trigramas ≥ SUGGEST_MIN) → até 3 sugestões, NUNCA automático;
 *   e. nada → por ligar.
 * ⚠️ Nunca pelo NOSSO código de artigo (spike F2-0: "Forma Redonda" ligava a um barril).
 * As ligações manuais (manual / sugestão aceite / artigo criado) nunca são refeitas.
 *
 * F2d: vários códigos do fornecedor para o mesmo artigo. O PingWin guarda UM por fornecedor (o
 * principal); associar um código novo a um artigo que já tem outro NÃO o substitui: o novo fica
 * como alternativo na XPLENDOR (mapa) e a linha fica ligada sem perguntas. "Tornar principal no
 * PingWin" é uma ação à parte. Um código com formato EAN/GTIN e controlo errado ("provavelmente
 * mal lido") nunca entra no mapa sozinho nem vai para o PingWin; só por associação à mão (mapa).
 * Um GTIN-14 e um EAN-13 do mesmo produto (caixa / unidade) dão uma SUGESTÃO, nunca ligação.
 * A fatura está "pronta para lançar" quando TODAS as linhas estão ligadas (guarda da Fase B).
 */
class OcrLineArticleService
{
    public const SUGGEST_MIN = 0.6;
    public const SUGGEST_MAX = 3;
    /** Taxa de IVA → taxgroup do PingWin (lktaxgroup da instalação). */
    public const TAXGROUPS = [23 => '1003001', 13 => '1003002', 6 => '1003003', 0 => '1003004'];
    /** Unidades base do PingWin. */
    public const UNITS = ['UN' => '11001', 'KG' => '11002', 'LT' => '11003'];
    /** Nome do grupo de IVA no catálogo (quando o espelho não tem o id) → taxa. */
    public const TAXGROUP_NAMES = ['Normal' => 23, 'Intermédia' => 13, 'Reduzida' => 6, 'Isenta' => 0];
    /** Score das sugestões "caixa / unidade do mesmo produto" (GTIN-14 ↔ EAN-13). */
    public const GTIN_SCORE = 0.95;

    public function __construct(private readonly SupplierArticleMapService $map, private readonly SupplierCodesService $codes) {}

    /** A taxa de IVA do artigo no catálogo (pelo id do grupo; senão pelo nome), ou null. */
    public static function articleVatRate(?PingwinCatalogItem $a): ?int
    {
        if (! $a) {
            return null;
        }
        $byId = array_flip(self::TAXGROUPS);
        if ($a->taxgroup_id !== null && isset($byId[(string) $a->taxgroup_id])) {
            return (int) $byId[(string) $a->taxgroup_id];
        }

        return self::TAXGROUP_NAMES[trim((string) $a->taxgroup)] ?? null;
    }

    /** A taxa de um taxgroup_id do PingWin (linhas da F4), ou null. */
    public static function taxgroupRate(?string $taxgroupId): ?int
    {
        $byId = array_flip(self::TAXGROUPS);

        return $taxgroupId !== null && isset($byId[$taxgroupId]) ? (int) $byId[$taxgroupId] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Normalização e semelhança
    // ─────────────────────────────────────────────────────────────────────────

    /** Minúsculas, sem acentos, só letras/dígitos separados por um espaço. */
    public static function normDesc(?string $s): string
    {
        $s = Str::lower(Str::ascii((string) $s));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '') ?? '');
    }

    /** Trigramas à pg_trgm (cada palavra com "  " antes e " " depois). */
    public static function trigrams(string $norm): array
    {
        $out = [];
        foreach (explode(' ', $norm) as $w) {
            if ($w === '') {
                continue;
            }
            $p = "  {$w} ";
            for ($i = 0, $n = strlen($p) - 2; $i < $n; $i++) {
                $out[substr($p, $i, 3)] = true;
            }
        }

        return $out;
    }

    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));

        return $inter / (count($a) + count($b) - $inter);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ligação automática
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre a → e nas linhas não manuais. Devolve contagem por método. Fornecedor de despesas gerais
     * (GeneralExpenseService): as linhas com valor ligam-se ao artigo de despesa da sua taxa.
     */
    public function linkInvoice(OcrInvoice $inv): array
    {
        $inv->loadMissing('lines');
        $this->resolvePendingCreations($inv);
        $expenses = app(GeneralExpenseService::class);
        if ($setting = $expenses->forInvoice($inv)) {
            return $expenses->apply($inv, $setting, false)['count'];
        }
        $lines = $inv->lines()->orderBy('position')->get();
        $nif = SupplierArticleMapService::nif($inv->supplier_nif);
        $catalog = $this->catalog($inv->company_id);
        $pairs = $this->pingwinPairs($inv, $lines);

        $count = ['mapa' => 0, 'pingwin' => 0, 'descricao' => 0, 'sugerida' => 0, 'por_ligar' => 0, 'manual' => 0];
        foreach ($lines as $line) {
            if ($line->isManual() && $line->article_id) {
                $count['manual']++;
                continue;
            }
            $res = $this->decide($inv, $line, $nif, $catalog, $pairs[$line->id] ?? null);
            $line->fill($res + ['linked_by' => null, 'linked_at' => $res['link_state'] === OcrInvoiceLine::LINKED ? now() : null])->save();
            $key = $res['link_state'] === OcrInvoiceLine::LINKED ? $res['link_method'] : $res['link_state'];
            $count[$key] = ($count[$key] ?? 0) + 1;
        }

        return $count;
    }

    private function decide(OcrInvoice $inv, OcrInvoiceLine $line, string $nif, Collection $catalog, ?PingwinSupplierDocumentLine $pw): array
    {
        $linked = fn (int $articleId, string $method, float $conf) => [
            'article_id' => $articleId, 'link_state' => OcrInvoiceLine::LINKED, 'link_method' => $method,
            'link_confidence' => $conf, 'link_suggestions' => null,
        ];

        // a. Mapa (NIF + código DO FORNECEDOR)
        if ($nif !== '' && ($m = $this->map->lookup($inv->company_id, $nif, $line->supplier_code)) && isset($catalog[$m->article_id])) {
            return $linked($m->article_id, 'mapa', $m->conflicts > 0 ? 0.9 : 1.0);
        }

        // b. Linha do documento PingWin ligado
        if ($pw && $pw->article_id && isset($catalog[$pw->article_id])) {
            // Um código provavelmente mal lido (EAN/GTIN com controlo errado) não se aprende sozinho.
            if ($nif !== '' && trim((string) $line->supplier_code) !== '' && ! Gtin::probablyMisread($line->supplier_code)) {
                $this->map->learn($inv->company_id, $nif, $line->supplier_code, (int) $pw->article_id, SupplierArticleMap::SOURCE_OCR_LINK,
                    $line->unit_price !== null ? (string) $line->unit_price : null, $line->unit);
            }

            return $linked((int) $pw->article_id, 'pingwin', 0.95);
        }

        // b2. Memória por fornecedor e descrição (só as linhas sem código)
        if (($mem = app(DescriptionMemoryService::class)->lookup($inv, $line)) && isset($catalog[$mem->article_id])) {
            return $linked((int) $mem->article_id, DescriptionMemoryService::METHOD, 0.95);
        }

        // c./d. Descrição
        $norm = self::normDesc($line->item);
        $suggestions = [];
        if ($norm !== '') {
            $equal = $catalog->filter(fn ($a) => $a['norm'] === $norm);
            if ($equal->count() === 1) {
                return $linked((int) $equal->keys()->first(), 'descricao', 0.9);
            }
            $tri = self::trigrams($norm);
            $scored = $catalog->map(fn ($a) => self::similarity($tri, $a['tri']))
                ->filter(fn ($s) => $s >= self::SUGGEST_MIN)->sortDesc()->take(self::SUGGEST_MAX);
            if ($equal->count() > 1) { // descrição igual em vários artigos: vira sugestão, nunca automático
                $scored = $equal->map(fn () => 1.0)->union($scored)->take(self::SUGGEST_MAX);
            }
            $suggestions = $scored->map(fn ($s, $id) => ['article_id' => (int) $id, 'score' => round($s, 2)])->values()->all();
        }

        // F2d: caixa / unidade do mesmo produto (GTIN-14 ↔ EAN-13 já conhecido no mapa) — à frente, nunca automático.
        if ($nif !== '' && ($g = $this->gtinRelated($inv->company_id, $nif, $line->supplier_code)) && isset($catalog[$g['article_id']])) {
            $suggestions = array_values(array_filter($suggestions, fn ($x) => $x['article_id'] !== $g['article_id']));
            array_unshift($suggestions, $g);
            $suggestions = array_slice($suggestions, 0, self::SUGGEST_MAX);
        }
        if ($suggestions !== []) {
            return ['article_id' => null, 'link_state' => OcrInvoiceLine::SUGGESTED, 'link_method' => null,
                'link_confidence' => round((float) $suggestions[0]['score'], 2), 'link_suggestions' => $suggestions];
        }

        return ['article_id' => null, 'link_state' => OcrInvoiceLine::UNLINKED, 'link_method' => null, 'link_confidence' => null, 'link_suggestions' => null];
    }

    /**
     * O artigo de um código do mesmo produto noutra embalagem, já conhecido no mapa deste fornecedor:
     * GTIN-14 (caixa) → EAN-13 (unidade), ou EAN-13 → GTIN-14. @return array{article_id:int, score:float, reason:string, code:string}|null
     */
    public function gtinRelated(int $companyId, string $nif, ?string $code): ?array
    {
        $related = Gtin::related($code);
        if ($related === []) {
            return null;
        }
        $norms = array_map(fn ($r) => SupplierArticleMapService::normCode($r['code']), $related);
        $hit = SupplierArticleMap::where('company_id', $companyId)->where('supplier_nif', $nif)->whereIn('supplier_code_norm', $norms)
            ->orderByDesc('times_seen')->first();
        if (! $hit) {
            return null;
        }
        // A linha é a caixa (GTIN-14) e o conhecido é a unidade, ou o contrário.
        $reason = Gtin::inspect($code)['kind'] === 'GTIN-14' ? 'gtin_caixa' : 'gtin_unidade';

        return ['article_id' => (int) $hit->article_id, 'score' => self::GTIN_SCORE, 'reason' => $reason, 'code' => $hit->supplier_code];
    }

    /** Só artigos utilizáveis: não apagados e não Inativos/Descontinuados no PingWin. */
    public static function usable($q)
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('product_status')->orWhereNotIn('product_status', ['Inativo', 'Descontinuado']))
            ->where(fn ($w) => $w->whereNull('status_id')->orWhereNotIn('status_id', ['2', '3']));
    }

    /** Artigos utilizáveis do catálogo: id → {norm, tri}. */
    private function catalog(int $companyId): Collection
    {
        return self::usable(PingwinCatalogItem::where('company_id', $companyId))
            ->get(['id', 'description'])
            ->mapWithKeys(function ($a) {
                $n = self::normDesc($a->description);

                return [$a->id => ['norm' => $n, 'tri' => self::trigrams($n)]];
            });
    }

    /**
     * b. Emparelha as linhas OCR com as do ÚNICO documento PingWin ligado: 1.º total + quantidade +
     * preço iguais; depois só o total; a ordem das linhas desempata. @return array<int, PingwinSupplierDocumentLine>
     */
    private function pingwinPairs(OcrInvoice $inv, Collection $lines): array
    {
        $docs = OcrInvoicePingwinLink::where('ocr_invoice_id', $inv->id)->pluck('docheader_id');
        if ($docs->count() !== 1) {
            return [];
        }
        $pool = PingwinSupplierDocumentLine::where('company_id', $inv->company_id)->where('docheader_id', $docs->first())
            ->orderBy('line_number')->get()->keyBy('id');
        $out = [];
        foreach ([true, false] as $strict) {
            foreach ($lines as $l) {
                if (isset($out[$l->id]) || $l->line_total_cents === null) {
                    continue;
                }
                $hit = $pool->first(fn ($p) => (int) $p->total_cents === (int) $l->line_total_cents
                    && (! $strict || ($l->quantity !== null && $p->qnt !== null && abs((float) $l->quantity - (float) $p->qnt) < 0.0005
                        && $l->unit_price !== null && $p->price !== null && abs((float) $l->unit_price - (float) $p->price) < 0.00005)));
                if ($hit) {
                    $out[$l->id] = $hit;
                    $pool->forget($hit->id);
                }
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ações da pessoa
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Associa a linha a um artigo (à mão, ou aceitando uma sugestão). Grava no mapa (manual) e,
     * se a linha tiver código do fornecedor, trata do código no artigo (PingWin):
     *  · o artigo ainda não tem código deste fornecedor → pede a escrita (job, releitura);
     *  · já tem OUTRO → por omissão NÃO substitui: o do PingWin fica principal e este fica como
     *    alternativo na XPLENDOR (supplier_code_status = alternativo); com $replaceCode, substitui;
     *  · código provavelmente mal lido → fica só no mapa (não vai para o PingWin).
     */
    public function associate(OcrInvoice $inv, OcrInvoiceLine $line, int $articleId, ?int $userId, string $method = 'manual', bool $replaceCode = false): OcrInvoiceLine
    {
        $article = self::usable(PingwinCatalogItem::where('company_id', $inv->company_id)->whereKey($articleId))->first();
        if (! $article) {
            throw new \InvalidArgumentException('Artigo desconhecido, inativo ou descontinuado.');
        }
        $supplier = $this->supplier($inv);
        $code = trim((string) $line->supplier_code);

        $line->fill([
            'article_id' => $article->id, 'link_state' => OcrInvoiceLine::LINKED, 'link_method' => $method,
            'link_confidence' => 1.0, 'link_suggestions' => null, 'linked_by' => $userId, 'linked_at' => now(),
        ])->save();

        $nif = SupplierArticleMapService::nif($inv->supplier_nif);
        if ($code !== '' && $nif !== '') {
            $this->map->learn($inv->company_id, $nif, $code, $article->id, SupplierArticleMap::SOURCE_MANUAL,
                $line->unit_price !== null ? (string) $line->unit_price : null, $line->unit);
        }
        // Sem código: a memória por descrição aprende (ou muda) o par.
        app(DescriptionMemoryService::class)->learn($inv, $line, (int) $article->id, $userId);
        if (Gtin::probablyMisread($code)) {
            $line->update(['supplier_code_status' => null, 'supplier_code_error' => null]); // só no mapa
        } else {
            $this->requestSupplierCode($inv, $line, $article, $supplier, $replaceCode);
        }

        return $line->fresh();
    }

    /**
     * Aceita a 1.ª sugestão de cada linha com confiança ≥ $min. As de "caixa / unidade do mesmo
     * produto" (GTIN) ficam de fora: pedem a confirmação da unidade e da quantidade.
     */
    public function acceptSuggestions(OcrInvoice $inv, float $min, ?int $userId): array
    {
        $done = 0;
        $skipped = [];
        foreach ($inv->lines()->where('link_state', OcrInvoiceLine::SUGGESTED)->orderBy('position')->get() as $line) {
            $top = $line->link_suggestions[0] ?? null;
            if (! $top || (float) $top['score'] < $min) {
                continue;
            }
            if (! empty($top['reason'])) {
                $skipped[] = ['line_id' => $line->id, 'reason' => 'Caixa ou unidade do mesmo produto: confirme o artigo, a unidade e a quantidade.'];
                continue;
            }
            $this->associate($inv, $line, (int) $top['article_id'], $userId, 'sugestao');
            $done++;
        }

        return ['accepted' => $done, 'skipped' => $skipped];
    }

    /**
     * "Tornar principal no PingWin": substitui o código deste fornecedor no artigo pelo da linha
     * (job WriteArticleSupplierCodeJob com replace; confirmação por releitura). O antigo continua
     * no mapa, agora como alternativo.
     */
    public function makePrincipal(OcrInvoice $inv, OcrInvoiceLine $line): OcrInvoiceLine
    {
        $code = trim((string) $line->supplier_code);
        if ($line->link_state !== OcrInvoiceLine::LINKED || ! $line->article_id || $code === '') {
            throw new \InvalidArgumentException('A linha tem de estar ligada a um artigo e ter código do fornecedor.');
        }
        if (Gtin::probablyMisread($code)) {
            throw new \InvalidArgumentException('O código parece mal lido (dígito de controlo errado): não vai para o PingWin.');
        }
        if ($line->supplier_code_status === 'pendente') {
            throw new \InvalidArgumentException('Já há uma gravação do código em curso.');
        }
        $article = PingwinCatalogItem::where('company_id', $inv->company_id)->find($line->article_id);
        $supplier = $this->supplier($inv);
        if (! $article?->pingwin_id || ! $supplier?->pingwin_id) {
            throw new \InvalidArgumentException('O artigo ou o fornecedor não existem no PingWin.');
        }
        $this->requestSupplierCode($inv, $line, $article, $supplier, true);

        return $line->fresh();
    }

    /**
     * "Corrigir o IVA do artigo no PingWin": muda o grupo de IVA do artigo para a taxa da linha da
     * fatura, pelo fluxo de edição de artigos (PingwinCatalogWrite + UpdatePingwinCatalogJob, que só
     * dá ok se a releitura do servidor tiver o novo grupo).
     */
    public function correctArticleVat(OcrInvoice $inv, OcrInvoiceLine $line, ?int $userId): PingwinCatalogWrite
    {
        $article = $line->article_id ? PingwinCatalogItem::where('company_id', $inv->company_id)->find($line->article_id) : null;
        if (! $article?->pingwin_id) {
            throw new \InvalidArgumentException('A linha não está ligada a um artigo do PingWin.');
        }
        $rate = $line->vat_rate !== null ? (int) $line->vat_rate : null;
        if ($rate === null || ! isset(self::TAXGROUPS[$rate])) {
            throw new \InvalidArgumentException('A taxa de IVA da linha não é uma das taxas do PingWin.');
        }
        if (self::articleVatRate($article) === $rate) {
            throw new \InvalidArgumentException('O artigo já tem esta taxa de IVA.');
        }
        $pending = $line->vat_write_id ? PingwinCatalogWrite::find($line->vat_write_id) : null;
        if ($pending && $pending->status === 'a_editar') {
            throw new \InvalidArgumentException('Já há uma correção do IVA deste artigo em curso.');
        }
        $write = PingwinCatalogWrite::create([
            'company_id' => $inv->company_id, 'user_id' => $userId, 'action' => 'editar',
            'catalog_item_id' => $article->id, 'code' => $article->code,
            'description' => $article->description ?? ('#' . $article->id),
            'payload' => ['taxgroup_id' => self::TAXGROUPS[$rate]], 'status' => 'a_editar',
        ]);
        $line->update(['vat_write_id' => $write->id]);
        UpdatePingwinCatalogJob::dispatch($inv->company_id, $write->id);

        return $write;
    }

    /**
     * Desfaz uma ligação manual ou pela memória: esquece o par aprendido à mão (código ou descrição) e
     * volta à ligação automática.
     */
    public function unlink(OcrInvoice $inv, OcrInvoiceLine $line): OcrInvoiceLine
    {
        if (($line->isManual() || $line->link_method === DescriptionMemoryService::METHOD) && $line->article_id) {
            $this->map->forgetManual($inv->company_id, SupplierArticleMapService::nif($inv->supplier_nif), $line->supplier_code, (int) $line->article_id);
            app(DescriptionMemoryService::class)->forget($inv, $line, (int) $line->article_id);
        }
        $line->fill(['article_id' => null, 'link_state' => OcrInvoiceLine::UNLINKED, 'link_method' => null, 'link_confidence' => null,
            'link_suggestions' => null, 'linked_by' => null, 'linked_at' => null, 'supplier_code_status' => null, 'supplier_code_error' => null])->save();
        $this->linkInvoice($inv->fresh());

        return $line->fresh();
    }

    /**
     * "Criar artigo" pela linha: usa o fluxo de criação de artigos (PingwinCatalogWrite + job,
     * confirmação por releitura). A linha fica à espera (article_write_id); quando o artigo é
     * confirmado, liga-se (método "criado") e grava-se o código do fornecedor.
     */
    public function createArticle(OcrInvoice $inv, OcrInvoiceLine $line, array $data, ?int $userId): PingwinCatalogWrite
    {
        $description = trim((string) $data['description']);
        $unit = (string) ($data['unit_id'] ?? self::UNITS['UN']);
        $product = [
            'description'              => $description,
            'shortname'                => mb_substr($description, 0, 30),
            'button_name'              => mb_substr($description, 0, 30),
            'family_id'                => (string) $data['family_id'],
            'product_type'             => '1',
            'status'                   => '1',
            'taxgroup_id'              => (string) $data['taxgroup_id'],
            'stockconfig_id'           => '7501',
            'base_unit_id'             => $unit,
            'default_sale_unit_id'     => $unit,
            'default_purchase_unit_id' => $unit,
            'default_stock_unit_id'    => $unit,
            'label_unit_id'            => $unit,
            'forsale'                  => 0,
            'forpurchase'              => 1,
            'forproduction'            => 0,
            'change_sale_price'        => 0,
        ];
        $price = $data['purchase_price'] ?? null;
        $write = PingwinCatalogWrite::create([
            'company_id'          => $inv->company_id,
            'user_id'             => $userId,
            'action'              => 'criar',
            'description'         => $description,
            'payload'             => $product,
            'purchaseprice_cents' => is_numeric($price) ? (int) round(((float) $price) * 100) : null,
            'status'              => 'a_criar',
        ]);
        $line->update(['article_write_id' => $write->id]);
        CreatePingwinCatalogJob::dispatch($inv->company_id, $write->id);

        return $write;
    }

    /** Linhas à espera de um artigo criado: quando a criação é confirmada, liga-as. */
    public function resolvePendingCreations(OcrInvoice $inv): void
    {
        foreach ($inv->lines()->whereNotNull('article_write_id')->get() as $line) {
            $write = PingwinCatalogWrite::where('company_id', $inv->company_id)->find($line->article_write_id);
            if (! $write || $write->status !== 'ok' || ! $write->pingwin_id) {
                continue;
            }
            $item = PingwinCatalogItem::where('company_id', $inv->company_id)->where('pingwin_id', $write->pingwin_id)->first();
            if (! $item) {
                continue;
            }
            $line->update(['article_write_id' => null]);
            $this->associate($inv, $line->fresh(), $item->id, $write->user_id, 'criado', true);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Código do fornecedor no artigo (PingWin)
    // ─────────────────────────────────────────────────────────────────────────

    /** O código que o artigo já tem para este fornecedor (espelho), se for OUTRO. */
    public function otherCode(int $companyId, PingwinCatalogItem $article, PingwinSupplier $supplier, string $code): ?string
    {
        $norm = SupplierArticleMapService::normCode($code);
        $codes = PingwinSupplierPrice::where('company_id', $companyId)->where('is_active', true)
            ->where('product_pingwin_id', $article->pingwin_id)->where('supplier_pingwin_id', $supplier->pingwin_id)
            ->whereNotNull('sup_product_code')->where('sup_product_code', '!=', '')->pluck('sup_product_code');
        if ($codes->contains(fn ($c) => SupplierArticleMapService::normCode($c) === $norm)) {
            return null;
        }

        return $codes->first();
    }

    private function requestSupplierCode(OcrInvoice $inv, OcrInvoiceLine $line, PingwinCatalogItem $article, ?PingwinSupplier $supplier, bool $replace): void
    {
        $code = trim((string) $line->supplier_code);
        if ($code === '' || ! $supplier || ! $supplier->pingwin_id || ! $article->pingwin_id) {
            $line->update(['supplier_code_status' => null, 'supplier_code_error' => null]);

            return;
        }
        $already = PingwinSupplierPrice::where('company_id', $inv->company_id)->where('is_active', true)
            ->where('product_pingwin_id', $article->pingwin_id)->where('supplier_pingwin_id', $supplier->pingwin_id)
            ->get(['sup_product_code'])->contains(fn ($p) => SupplierArticleMapService::normCode($p->sup_product_code) === SupplierArticleMapService::normCode($code));
        if ($already) {
            $line->update(['supplier_code_status' => 'ok', 'supplier_code_error' => null]);

            return;
        }
        // Já tem outro código deste fornecedor: não substitui por omissão (fica alternativo).
        if (! $replace && $this->otherCode($inv->company_id, $article, $supplier, $code) !== null) {
            $line->update(['supplier_code_status' => 'alternativo', 'supplier_code_error' => null]);

            return;
        }
        $line->update(['supplier_code_status' => 'pendente', 'supplier_code_error' => null]);
        WriteArticleSupplierCodeJob::dispatch($inv->company_id, $line->id, $replace);
    }

    /** Fornecedor da fatura no espelho (NIF só dígitos; ativo primeiro). */
    public function supplier(OcrInvoice $inv): ?PingwinSupplier
    {
        $nif = SupplierArticleMapService::nif($inv->supplier_nif);
        if ($nif === '') {
            return null;
        }

        return PingwinSupplier::where('company_id', $inv->company_id)->whereNotNull('pingwin_id')->whereNotNull('tax_number')
            ->orderByDesc('is_active')->orderBy('id')->get()
            ->first(fn ($s) => SupplierArticleMapService::nif($s->tax_number) === $nif);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apresentação
    // ─────────────────────────────────────────────────────────────────────────

    /** Resumo da fatura: linhas por estado + "pronta para lançar". */
    public static function summary(Collection $lines): array
    {
        $by = $lines->countBy(fn ($l) => $l->link_state ?? OcrInvoiceLine::UNLINKED);
        $linked = (int) ($by[OcrInvoiceLine::LINKED] ?? 0);

        return [
            'total'     => $lines->count(),
            'linked'    => $linked,
            'suggested' => (int) ($by[OcrInvoiceLine::SUGGESTED] ?? 0),
            'unlinked'  => (int) ($by[OcrInvoiceLine::UNLINKED] ?? 0),
            'ready'     => $lines->count() > 0 && $linked === $lines->count(),
        ];
    }

    /**
     * Campos da ligação de uma linha para a UI (artigo, sugestões com detalhe, escritas).
     * F2d: os códigos deste fornecedor para o artigo (principal no PingWin + alternativos), a
     * validação do código (EAN/GTIN) e o aviso de IVA (taxa da fatura ≠ taxa do artigo).
     *
     * @param array<int, list<array>> $codes  SupplierCodesService::forArticles (por artigo), de codesFor()
     */
    public function presentLine(OcrInvoiceLine $l, Collection $articles, array $codes = []): array
    {
        $a = fn (?int $id) => $id && isset($articles[$id]) ? [
            'id' => $articles[$id]->id, 'code' => $articles[$id]->code, 'description' => $articles[$id]->description,
            'unit' => $articles[$id]->purchaseunit ?? $articles[$id]->saleunit,
        ] : null;
        $write = $l->article_write_id ? PingwinCatalogWrite::find($l->article_write_id) : null;
        $vatWrite = $l->vat_write_id ? PingwinCatalogWrite::find($l->vat_write_id) : null;
        $articleRate = $l->article_id ? self::articleVatRate($articles[$l->article_id] ?? null) : null;
        $invoiceRate = $l->vat_rate !== null ? (int) $l->vat_rate : null;
        $articleCodes = $l->article_id ? ($codes[$l->article_id] ?? []) : [];
        $principal = collect($articleCodes)->firstWhere('principal', true)['code'] ?? null;

        return [
            'article'              => $a($l->article_id),
            'link_state'           => $l->link_state ?? OcrInvoiceLine::UNLINKED,
            'link_method'          => $l->link_method,
            'link_confidence'      => $l->link_confidence,
            'suggestions'          => collect($l->link_suggestions ?? [])->map(fn ($s) => ($art = $a((int) $s['article_id']))
                ? $art + ['score' => $s['score'], 'reason' => $s['reason'] ?? null, 'via_code' => $s['code'] ?? null] : null)->filter()->values()->all(),
            'creating'             => $write ? ['write_id' => $write->id, 'status' => $write->status, 'error' => $write->error_message] : null,
            'supplier_code_status' => $l->supplier_code_status,
            'supplier_code_error'  => $l->supplier_code_error,
            'supplier_code_check'  => Gtin::inspect($l->supplier_code),
            'principal_code'       => $principal,
            'supplier_codes'       => $articleCodes,
            'vat_check'            => $articleRate !== null && $invoiceRate !== null && $articleRate !== $invoiceRate && isset(self::TAXGROUPS[$invoiceRate])
                ? ['invoice_rate' => $invoiceRate, 'article_rate' => $articleRate, 'invoice_taxgroup' => self::TAXGROUPS[$invoiceRate]] : null,
            'vat_write'            => $vatWrite ? ['write_id' => $vatWrite->id, 'status' => $vatWrite->status, 'error' => $vatWrite->error_message] : null,
        ];
    }

    /** Os códigos do fornecedor da fatura para os artigos ligados (uma consulta por fatura). */
    public function codesFor(OcrInvoice $inv, Collection $lines): array
    {
        return $this->codes->forArticles($inv->company_id, (string) $inv->supplier_nif, $lines->pluck('article_id')->filter()->unique()->all());
    }

    /** Artigos referidos pelas linhas (ligados + sugeridos), para presentLine. */
    public static function articlesFor(Collection $lines): Collection
    {
        $ids = $lines->flatMap(fn ($l) => array_merge([$l->article_id], array_column($l->link_suggestions ?? [], 'article_id')))->filter()->unique()->values();

        return PingwinCatalogItem::whereIn('id', $ids)->get(['id', 'code', 'description', 'purchaseunit', 'saleunit', 'taxgroup', 'taxgroup_id'])->keyBy('id');
    }
}
