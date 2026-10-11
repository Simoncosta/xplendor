<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use App\Support\Gtin;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F2d: os códigos de UM fornecedor (NIF) para cada artigo.
 *
 * O PingWin só guarda UM código por fornecedor em cada artigo: é o PRINCIPAL. A XPLENDOR guarda
 * todos no supplier_article_map (chave NIF + código → artigo; vários códigos podem dar o mesmo
 * artigo): os que não estão no PingWin são ALTERNATIVOS. Um fornecedor como a Makro usa vários
 * códigos reais para o mesmo produto (EAN da unidade, GTIN-14 da caixa, códigos de promoção).
 *
 * "Usos" = linhas reais do PingWin (F4) com esse código + vezes que a XPLENDOR o aprendeu das
 * faturas (mapa, origem manual/ocr_link). O principal SUGERIDO é o mais usado; se não for o do
 * PingWin, a UI propõe "Tornar principal no PingWin".
 */
class SupplierCodesService
{
    /**
     * @param int[] $articleIds
     * @return array<int, list<array{code: string, principal: bool, uses: int, suggested_principal: bool, check: ?array}>>
     */
    public function forArticles(int $companyId, string $nif, array $articleIds): array
    {
        $nif = SupplierArticleMapService::nif($nif);
        $articleIds = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
        if ($nif === '' || $articleIds === []) {
            return [];
        }
        /** @var array<int, array<string, array{code: string, principal: bool, uses: int}>> $out */
        $out = [];
        $put = function (int $article, ?string $code, bool $principal, int $uses) use (&$out) {
            $norm = SupplierArticleMapService::normCode($code);
            if ($norm === '') {
                return;
            }
            $cur = $out[$article][$norm] ?? ['code' => trim((string) $code), 'principal' => false, 'uses' => 0];
            $cur['principal'] = $cur['principal'] || $principal;
            $cur['uses'] += $uses;
            $out[$article][$norm] = $cur;
        };

        // Principal: o código que o artigo tem no PingWin para este fornecedor (espelho tbsupprice).
        $pwSuppliers = PingwinSupplier::where('company_id', $companyId)->whereNotNull('pingwin_id')->whereNotNull('tax_number')->get(['pingwin_id', 'tax_number'])
            ->filter(fn ($s) => SupplierArticleMapService::nif($s->tax_number) === $nif)->pluck('pingwin_id')->all();
        if ($pwSuppliers !== []) {
            PingwinSupplierPrice::where('company_id', $companyId)->where('is_active', true)->whereIn('supplier_pingwin_id', $pwSuppliers)
                ->whereIn('catalog_item_id', $articleIds)->whereNotNull('sup_product_code')->where('sup_product_code', '!=', '')
                ->get(['catalog_item_id', 'sup_product_code'])
                ->each(fn ($p) => $put((int) $p->catalog_item_id, $p->sup_product_code, true, 0));
        }

        // Linhas reais (F4) deste fornecedor: usos por código.
        DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereIn('l.article_id', $articleIds)
            ->whereNotNull('l.supplier_code')->where('l.supplier_code', '!=', '')
            ->where(fn ($q) => $q->whereNull('d.docstatus_id')->orWhere('d.docstatus_id', '!=', '8003'))
            ->get(['l.article_id', 'l.supplier_code', 'd.tax_number'])
            ->each(function ($r) use ($nif, $put) {
                if (SupplierArticleMapService::nif($r->tax_number) === $nif) {
                    $put((int) $r->article_id, $r->supplier_code, false, 1);
                }
            });

        // O mapa (todos os códigos que a XPLENDOR conhece); as aprendizagens das faturas contam como usos.
        SupplierArticleMap::where('company_id', $companyId)->where('supplier_nif', $nif)->whereIn('article_id', $articleIds)
            ->get(['article_id', 'supplier_code', 'source', 'times_seen'])
            ->each(fn ($m) => $put((int) $m->article_id, $m->supplier_code, false,
                in_array($m->source, [SupplierArticleMap::SOURCE_MANUAL, SupplierArticleMap::SOURCE_OCR_LINK], true) ? (int) $m->times_seen : 0));

        $result = [];
        foreach ($out as $article => $codes) {
            $codes = array_values($codes);
            usort($codes, fn ($a, $b) => [$b['principal'], $b['uses'], $a['code']] <=> [$a['principal'], $a['uses'], $b['code']]);
            $max = max(array_column($codes, 'uses'));
            $principalUses = max([0, ...array_column(array_filter($codes, fn ($c) => $c['principal']), 'uses')]);
            $result[$article] = array_map(fn ($c) => $c + [
                // sugerido como principal: o mais usado, quando é mais usado do que o principal atual
                'suggested_principal' => ! $c['principal'] && $c['uses'] === $max && $c['uses'] > $principalUses,
                'check'               => Gtin::inspect($c['code']),
            ], $codes);
        }

        return $result;
    }

    /** O código principal (PingWin) deste artigo para este fornecedor, se houver. */
    public function principal(int $companyId, string $nif, int $articleId): ?string
    {
        foreach ($this->forArticles($companyId, $nif, [$articleId])[$articleId] ?? [] as $c) {
            if ($c['principal']) {
                return $c['code'];
            }
        }

        return null;
    }
}
