<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F2d: relatório SÓ DE LEITURA para limpar o catálogo — artigos cujo IVA faturado pelos
 * fornecedores (linhas reais do PingWin, F4: pingwin_supplier_document_lines) é diferente do IVA do
 * artigo no catálogo. Por artigo: nº de linhas com IVA diferente (e total), as taxas faturadas, os
 * fornecedores e a última data. Documentos anulados (8003) não contam.
 */
class ArticleVatReportService
{
    /** @return array{summary: array, articles: list<array>} */
    public function divergences(int $companyId): array
    {
        $items = PingwinCatalogItem::where('company_id', $companyId)->get(['id', 'pingwin_id', 'code', 'description', 'family', 'taxgroup', 'taxgroup_id', 'is_active'])->keyBy('id');
        $rows = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereNotNull('l.article_id')->whereNotNull('l.taxgroup_id')
            ->where(fn ($q) => $q->whereNull('d.docstatus_id')->orWhere('d.docstatus_id', '!=', '8003'))
            ->get(['l.article_id', 'l.taxgroup_id', 'd.entity_name', 'd.doc_date']);

        $by = [];
        $checked = [];
        $noRate = [];
        foreach ($rows as $r) {
            $item = $items[(int) $r->article_id] ?? null;
            $lineRate = OcrLineArticleService::taxgroupRate((string) $r->taxgroup_id);
            if (! $item || $lineRate === null) {
                continue;
            }
            $articleRate = OcrLineArticleService::articleVatRate($item);
            if ($articleRate === null) {
                $noRate[$item->id] = true;
                continue;
            }
            $checked[$item->id] = true;
            $a = $by[$item->id] ?? ['lines' => 0, 'divergent' => 0, 'rates' => [], 'suppliers' => [], 'last_date' => null, 'last_supplier' => null];
            $a['lines']++;
            if ($lineRate !== $articleRate) {
                $a['divergent']++;
                $a['rates'][$lineRate] = ($a['rates'][$lineRate] ?? 0) + 1;
                $name = trim((string) $r->entity_name);
                if ($name !== '') {
                    $a['suppliers'][$name] = ($a['suppliers'][$name] ?? 0) + 1;
                }
                $date = $r->doc_date ? substr((string) $r->doc_date, 0, 10) : null;
                if ($date !== null && ($a['last_date'] === null || $date > $a['last_date'])) {
                    $a['last_date'] = $date;
                    $a['last_supplier'] = $name ?: null;
                }
            }
            $by[$item->id] = $a;
        }

        $articles = [];
        foreach ($by as $id => $a) {
            if ($a['divergent'] === 0) {
                continue;
            }
            $item = $items[$id];
            arsort($a['rates']);
            arsort($a['suppliers']);
            $articles[] = [
                'article_id'       => $id,
                'pingwin_id'       => $item->pingwin_id,
                'code'             => $item->code,
                'description'      => $item->description,
                'family'           => $item->family,
                'is_active'        => (bool) $item->is_active,
                'article_rate'     => OcrLineArticleService::articleVatRate($item),
                'invoiced_rates'   => array_map(fn ($rate, $n) => ['rate' => $rate, 'lines' => $n], array_keys($a['rates']), $a['rates']),
                'divergent_lines'  => $a['divergent'],
                'total_lines'      => $a['lines'],
                'suppliers'        => array_map(fn ($name, $n) => ['name' => $name, 'lines' => $n], array_keys($a['suppliers']), $a['suppliers']),
                'last_date'        => $a['last_date'],
                'last_supplier'    => $a['last_supplier'],
            ];
        }
        usort($articles, fn ($x, $y) => [$y['divergent_lines'], $y['last_date'] ?? ''] <=> [$x['divergent_lines'], $x['last_date'] ?? '']);

        return [
            'summary' => [
                'articles_checked'   => count($checked),
                'articles_divergent' => count($articles),
                'lines_divergent'    => array_sum(array_column($articles, 'divergent_lines')),
                'articles_no_rate'   => count($noRate),
            ],
            'articles' => $articles,
        ];
    }
}
