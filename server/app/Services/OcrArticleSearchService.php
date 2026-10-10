<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplierPrice;
use App\Models\SupplierArticleMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F2b: pesquisa de artigos para as linhas da fatura (uma só caixa): código,
 * descrição, código do fornecedor e nome do fornecedor; sem acentos e por palavras (todas têm
 * de aparecer). Cada resultado traz unidade, família e a ÚLTIMA COMPRA (preço, data, fornecedor,
 * das linhas reais da F4) e o selo "já comprado a este fornecedor". Ordem: primeiro os já
 * comprados a este fornecedor. Índice em cache por empresa (curto: o catálogo muda pouco).
 */
class OcrArticleSearchService
{
    public const LIMIT = 30;
    private const CACHE_SECONDS = 300;

    public function search(int $companyId, string $q, ?string $supplierNif = null, int $limit = self::LIMIT): array
    {
        $nif = SupplierArticleMapService::nif($supplierNif);
        $tokens = array_values(array_filter(explode(' ', OcrLineArticleService::normDesc($q))));
        $index = $this->index($companyId);

        $hits = $this->match($index, $tokens, $nif, false);
        // Nenhum artigo tem TODAS as palavras (ex.: a descrição da fatura "MC OVOS M IND 15DUZIAS"
        // e o artigo "MC OVOS M IND"): mostra os que têm mais palavras em comum, como aproximados.
        $approximate = false;
        if ($hits === [] && count($tokens) > 1) {
            $hits = $this->match($index, array_values(array_filter($tokens, fn ($t) => strlen($t) > 1)), $nif, true);
            $approximate = true;
        }
        usort($hits, function ($x, $y) {
            $c = array_slice($x['_rank'], 0, 4) <=> array_slice($y['_rank'], 0, 4);

            return $c !== 0 ? $c : strcmp($y['_rank'][4], $x['_rank'][4]); // compra mais recente primeiro
        });

        return array_map(fn ($a) => [
            'id'                   => $a['id'],
            'code'                 => $a['code'],
            'description'          => $a['description'],
            'unit'                 => $a['unit'],
            'family'               => $a['family'],
            'last_purchase'        => $a['last'],
            'bought_from_supplier' => $a['bought_from_supplier'],
            'supplier_codes'       => $nif !== '' ? array_values($a['codes_by_nif'][$nif] ?? []) : [],
            'approximate'          => $approximate,
        ], array_slice($hits, 0, $limit));
    }

    /**
     * Artigos com TODAS as palavras ($partial=false) ou com pelo menos uma ($partial=true, ordenados
     * por quantas têm). Sem palavras: os já comprados a este fornecedor.
     */
    private function match(array $index, array $tokens, string $nif, bool $partial): array
    {
        $hits = [];
        $qn = implode(' ', $tokens);
        foreach ($index as $a) {
            $found = 0;
            foreach ($tokens as $t) {
                if (str_contains($a['text'], $t)) {
                    $found++;
                } elseif (! $partial) {
                    continue 2;
                }
            }
            if ($tokens === [] && ($nif === '' || ! isset($a['nifs'][$nif]))) {
                continue; // sem texto: só os já comprados a este fornecedor
            }
            if ($partial && $found === 0) {
                continue;
            }
            $bought = $nif !== '' && isset($a['nifs'][$nif]);
            $exact = $qn !== '' && ($a['code_norm'] === $qn || in_array($qn, $a['codes'], true));
            $starts = $qn !== '' && str_starts_with($a['desc_norm'], $qn);
            $hits[] = $a + ['bought_from_supplier' => $bought,
                '_rank' => [-$found, $bought ? 0 : 1, $exact ? 0 : 1, $starts ? 0 : 1, $a['last']['date'] ?? '0000']];
        }

        return $hits;
    }

    /** Índice por artigo ativo: texto normalizado + NIFs a quem foi comprado + última compra. */
    private function index(int $companyId): array
    {
        $stamp = PingwinCatalogItem::where('company_id', $companyId)->max('updated_at') . '|' . SupplierArticleMap::where('company_id', $companyId)->max('updated_at');

        return Cache::remember("ocr:article-index:{$companyId}:" . md5($stamp), self::CACHE_SECONDS, function () use ($companyId) {
            $items = OcrLineArticleService::usable(PingwinCatalogItem::where('company_id', $companyId))
                ->get(['id', 'pingwin_id', 'code', 'description', 'family', 'purchaseunit', 'saleunit']);
            $idx = [];
            foreach ($items as $i) {
                $idx[$i->id] = [
                    'id' => $i->id, 'code' => $i->code, 'description' => $i->description,
                    'unit' => $i->purchaseunit ?: $i->saleunit,
                    'family' => $i->family ? trim(preg_replace('/^Fam[íi]lia\s*\\\\\s*/u', '', $i->family)) : null,
                    'code_norm' => OcrLineArticleService::normDesc($i->code), 'desc_norm' => OcrLineArticleService::normDesc($i->description),
                    'codes' => [], 'codes_by_nif' => [], 'names' => [], 'nifs' => [], 'last' => null,
                ];
            }
            $add = function (int $id, ?string $nif, ?string $code, ?string $name) use (&$idx) {
                if (! isset($idx[$id])) {
                    return;
                }
                $nif = SupplierArticleMapService::nif($nif);
                if ($code !== null && trim($code) !== '') {
                    $c = OcrLineArticleService::normDesc($code);
                    $idx[$id]['codes'][$c] = $c;
                    if ($nif !== '') {
                        $idx[$id]['codes_by_nif'][$nif][$c] = trim($code);
                    }
                }
                if ($name) {
                    $idx[$id]['names'][OcrLineArticleService::normDesc($name)] = true;
                }
                if ($nif !== '') {
                    $idx[$id]['nifs'][$nif] = true;
                }
            };

            // Compras reais (F4): códigos do fornecedor, fornecedores e a última compra.
            $rows = DB::table('pingwin_supplier_document_lines as l')
                ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $companyId)->whereNotNull('l.article_id')
                ->where(fn ($q) => $q->whereNull('d.docstatus_id')->orWhere('d.docstatus_id', '!=', '8003'))
                ->orderBy('d.doc_date')
                ->get(['l.article_id', 'l.supplier_code', 'l.price', 'l.unit_code', 'd.tax_number', 'd.entity_name', 'd.doc_date']);
            foreach ($rows as $r) {
                $id = (int) $r->article_id;
                $add($id, $r->tax_number, $r->supplier_code, $r->entity_name);
                if (isset($idx[$id]) && (float) $r->price > 0) {
                    $idx[$id]['last'] = ['price' => (float) $r->price, 'unit' => $r->unit_code, 'date' => substr((string) $r->doc_date, 0, 10), 'supplier' => $r->entity_name];
                }
            }
            // Fichas dos artigos (linhas de fornecedor) e o mapa aprendido.
            $nifByPw = DB::table('suppliers')->where('company_id', $companyId)->whereNotNull('pingwin_id')->pluck('tax_number', 'pingwin_id');
            foreach (PingwinSupplierPrice::where('company_id', $companyId)->where('is_active', true)->whereNotNull('catalog_item_id')
                ->get(['catalog_item_id', 'supplier_pingwin_id', 'supplier_name', 'sup_product_code']) as $p) {
                $add((int) $p->catalog_item_id, $nifByPw[$p->supplier_pingwin_id] ?? null, $p->sup_product_code, $p->supplier_name);
            }
            foreach (SupplierArticleMap::where('company_id', $companyId)->get(['article_id', 'supplier_nif', 'supplier_code']) as $m) {
                $add((int) $m->article_id, $m->supplier_nif, $m->supplier_code, null);
            }

            foreach ($idx as &$a) {
                $a['codes'] = array_values($a['codes']);
                $a['text'] = ' ' . implode(' ', array_filter([$a['code_norm'], $a['desc_norm'], implode(' ', $a['codes']), implode(' ', array_keys($a['names']))])) . ' ';
            }

            return array_values($idx);
        });
    }
}
