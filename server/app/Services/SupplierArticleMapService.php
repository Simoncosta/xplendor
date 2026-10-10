<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F2b: mapa de aprendizagem "NIF do fornecedor + código do artigo no fornecedor →
 * artigo do catálogo". É a 1.ª regra da ligação das linhas OCR.
 *
 * Bootstrap (idempotente; madrugada + comando):
 *  · linhas das faturas reais do PingWin (F4) com "Código do fornecedor" (entity_product_id) e
 *    artigo no catálogo → NIF do fornecedor do documento;
 *  · linhas de fornecedor dos artigos (pingwin_supplier_prices.sup_product_code).
 * Conflito (o mesmo código ligado a vários artigos): ganha o artigo utilizável (não Inativo nem
 * Descontinuado), depois o mais frequente, depois o mais recente; conta-se quantos outros houve. As entradas manuais/ocr_link NUNCA são refeitas.
 *
 * ⚠️ Regra do spike F2-0: a chave é SEMPRE o código DO FORNECEDOR, nunca o nosso código de artigo.
 */
class SupplierArticleMapService
{
    public static function nif(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    /** "  00005 " → "5"; "29 001280 47001" → "2900128047001"; "a-12" → "A-12"; só zeros → "0". */
    public static function normCode(?string $v): string
    {
        $c = strtoupper(preg_replace('/\s+/u', '', trim((string) $v)) ?? '');
        if ($c === '') {
            return '';
        }
        $c = ltrim($c, '0');

        return $c === '' ? '0' : mb_substr($c, 0, 60);
    }

    /** O artigo aprendido para (NIF, código do fornecedor), ou null. */
    public function lookup(int $companyId, string $nif, ?string $code): ?SupplierArticleMap
    {
        $norm = self::normCode($code);
        if ($nif === '' || $norm === '') {
            return null;
        }

        return SupplierArticleMap::where('company_id', $companyId)->where('supplier_nif', $nif)->where('supplier_code_norm', $norm)->first();
    }

    /**
     * Aprende um par (manual ou ocr_link). O manual substitui sempre; o ocr_link não substitui um
     * manual com outro artigo (conta como conflito).
     */
    public function learn(int $companyId, string $nif, ?string $code, int $articleId, string $source, ?string $price = null, ?string $unit = null): ?SupplierArticleMap
    {
        $norm = self::normCode($code);
        if ($nif === '' || $norm === '') {
            return null;
        }
        $row = SupplierArticleMap::firstOrNew(['company_id' => $companyId, 'supplier_nif' => $nif, 'supplier_code_norm' => $norm]);
        if ($row->exists && $row->article_id !== $articleId && $row->source === SupplierArticleMap::SOURCE_MANUAL && $source !== SupplierArticleMap::SOURCE_MANUAL) {
            $row->conflicts++;
            $row->save();

            return $row;
        }
        if ($row->exists && $row->article_id === $articleId) {
            $row->times_seen++;
        } else {
            if ($row->exists) {
                $row->conflicts++;
            }
            $row->times_seen = 1;
        }
        $row->fill([
            'supplier_code' => mb_substr(trim((string) $code), 0, 60),
            'article_id'    => $articleId,
            'source'        => $source,
            'last_seen_at'  => now(),
            'last_price'    => $price ?? $row->last_price,
            'unit'          => $unit ?? $row->unit,
        ])->save();

        return $row;
    }

    /** Esquece um par aprendido à mão (desfazer uma ligação manual). */
    public function forgetManual(int $companyId, string $nif, ?string $code, int $articleId): void
    {
        SupplierArticleMap::where('company_id', $companyId)->where('supplier_nif', $nif)
            ->where('supplier_code_norm', self::normCode($code))->where('article_id', $articleId)
            ->where('source', SupplierArticleMap::SOURCE_MANUAL)->delete();
    }

    /**
     * Bootstrap a partir da F4 e dos preços de fornecedor. Devolve estatística:
     * pares, fornecedores, conflitos (com exemplos), inseridos/atualizados.
     */
    public function bootstrap(int $companyId): array
    {
        $articles = PingwinCatalogItem::where('company_id', $companyId)->pluck('id')->flip();
        // Num conflito ganham primeiro os artigos utilizáveis (não Inativos/Descontinuados).
        $usable = OcrLineArticleService::usable(PingwinCatalogItem::where('company_id', $companyId))->pluck('id')->flip();

        // ── F4: linhas reais com código do fornecedor e artigo no catálogo
        $f4 = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->whereNotNull('l.supplier_code')->where('l.supplier_code', '!=', '')
            ->whereNotNull('l.article_id')
            ->where(fn ($q) => $q->whereNull('d.docstatus_id')->orWhere('d.docstatus_id', '!=', '8003'))
            ->get(['l.supplier_code', 'l.article_id', 'l.price', 'l.unit_code', 'd.tax_number', 'd.doc_date']);

        /** @var array<string, array<int, array{n:int, last:?string, price:?string, unit:?string, code:string}>> $pairs */
        $pairs = [];
        foreach ($f4 as $r) {
            $nif = self::nif($r->tax_number);
            $norm = self::normCode($r->supplier_code);
            if ($nif === '' || $norm === '' || ! isset($articles[(int) $r->article_id])) {
                continue;
            }
            $k = "{$nif}|{$norm}";
            $a = (int) $r->article_id;
            $cur = $pairs[$k][$a] ?? ['n' => 0, 'last' => null, 'price' => null, 'unit' => null, 'code' => trim((string) $r->supplier_code), 'src' => SupplierArticleMap::SOURCE_F4];
            $cur['n']++;
            $date = $r->doc_date ? substr((string) $r->doc_date, 0, 10) : null;
            if ($cur['last'] === null || ($date !== null && $date >= $cur['last'])) {
                $cur['last'] = $date;
                $cur['price'] = $r->price !== null ? (string) $r->price : $cur['price'];
                $cur['unit'] = $r->unit_code ?? $cur['unit'];
            }
            $pairs[$k][$a] = $cur;
        }

        // ── Linhas de fornecedor dos artigos (sup_product_code)
        $supplierNif = PingwinSupplier::where('company_id', $companyId)->whereNotNull('pingwin_id')
            ->pluck('tax_number', 'pingwin_id')->map(fn ($v) => self::nif($v));
        $sp = PingwinSupplierPrice::where('company_id', $companyId)->where('is_active', true)
            ->whereNotNull('sup_product_code')->where('sup_product_code', '!=', '')->whereNotNull('catalog_item_id')
            ->get(['supplier_pingwin_id', 'sup_product_code', 'catalog_item_id', 'price_cents', 'unit_name', 'synced_at']);
        foreach ($sp as $r) {
            $nif = (string) ($supplierNif[$r->supplier_pingwin_id] ?? '');
            $norm = self::normCode($r->sup_product_code);
            if ($nif === '' || $norm === '' || ! isset($articles[(int) $r->catalog_item_id])) {
                continue;
            }
            $k = "{$nif}|{$norm}";
            $a = (int) $r->catalog_item_id;
            if (isset($pairs[$k][$a])) {
                $pairs[$k][$a]['n']++; // a mesma ligação também está na ficha do artigo
                continue;
            }
            $pairs[$k][$a] = ['n' => 1, 'last' => $r->synced_at?->toDateString(), 'unit' => $r->unit_name,
                'price' => $r->price_cents !== null ? number_format($r->price_cents / 100, 6, '.', '') : null,
                'code' => trim((string) $r->sup_product_code), 'src' => SupplierArticleMap::SOURCE_SUPPLIER_PRICES];
        }

        // ── Escolha por par + gravação (sem tocar nas manuais/ocr_link)
        $existing = SupplierArticleMap::where('company_id', $companyId)->get()->keyBy(fn ($m) => "{$m->supplier_nif}|{$m->supplier_code_norm}");
        $stats = ['pairs' => 0, 'suppliers' => [], 'conflicts' => 0, 'conflict_examples' => [], 'inserted' => 0, 'updated' => 0, 'kept_manual' => 0];
        foreach ($pairs as $k => $byArticle) {
            foreach ($byArticle as $id => &$v) {
                $v['ok'] = isset($usable[$id]) ? 1 : 0;
            }
            unset($v);
            uasort($byArticle, fn ($x, $y) => [$y['ok'], $y['n'], $y['last'] ?? ''] <=> [$x['ok'], $x['n'], $x['last'] ?? '']);
            $winnerId = (int) array_key_first($byArticle);
            $w = $byArticle[$winnerId];
            $conflicts = count($byArticle) - 1;
            [$nif, $norm] = explode('|', $k, 2);
            $stats['pairs']++;
            $stats['suppliers'][$nif] = true;
            if ($conflicts > 0) {
                $stats['conflicts']++;
                if (count($stats['conflict_examples']) < 5) {
                    $stats['conflict_examples'][] = ['nif' => $nif, 'code' => $w['code'],
                        'articles' => collect($byArticle)->map(fn ($v, $id) => ['article_id' => $id, 'seen' => $v['n'], 'last' => $v['last']])->values()->all()];
                }
            }
            $row = $existing[$k] ?? null;
            if ($row && in_array($row->source, [SupplierArticleMap::SOURCE_MANUAL, SupplierArticleMap::SOURCE_OCR_LINK], true)) {
                $stats['kept_manual']++;
                continue;
            }
            $data = [
                'supplier_code' => mb_substr($w['code'], 0, 60), 'article_id' => $winnerId, 'source' => $w['src'],
                'times_seen' => $w['n'], 'conflicts' => $conflicts,
                'last_seen_at' => $w['last'] ? $w['last'] . ' 00:00:00' : null, 'last_price' => $w['price'],
                'unit' => $w['unit'] ? mb_substr((string) $w['unit'], 0, 30) : null,
            ];
            if ($row) {
                $row->fill($data)->save();
                $stats['updated']++;
            } else {
                SupplierArticleMap::create($data + ['company_id' => $companyId, 'supplier_nif' => $nif, 'supplier_code_norm' => $norm]);
                $stats['inserted']++;
            }
        }
        $stats['suppliers'] = count($stats['suppliers']);

        return $stats;
    }
}
