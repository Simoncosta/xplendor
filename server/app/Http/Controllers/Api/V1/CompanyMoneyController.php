<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeCostService;
use App\Services\SupplierArticleMapService;

/**
 * XPLENDOR — Auditoria v2-C (Dinheiro): o "Ver histórico de preços" e o "Comparar fornecedores" da
 * C1. Só lê o espelho (as linhas das faturas fechadas, 365 dias): o preço por unidade base de cada
 * compra, por fornecedor, e o resumo de 90 dias. A quantidade confirmada (conversão confirmada ou
 * linha na unidade base) marca o preço como confirmado; senão fica "a confirmar". Nunca escreve.
 * Os preços só com financas.ver.
 */
class CompanyMoneyController extends Controller
{
    public function priceHistory(\Illuminate\Http\Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate(['products' => ['nullable', 'array', 'max:10'], 'products.*' => ['string', 'max:32']]);
        if (! CompassFinancials::visibleTo($request->user(), $companyId)) {
            return ApiResponse::success(['articles' => [], 'suppliers' => [], 'financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]], 'Histórico de preços.');
        }
        $pids = array_values(array_unique(array_merge([$productId], array_map('strval', $v['products'] ?? []))));
        $cost = new RecipeCostService($companyId);
        $since90 = $cost->today()->subDays(90)->toDateString();
        $lines = $cost->purchaseLines($pids, $cost->today()->subDays(365)->toDateString());
        $cost->ensureItems($pids);
        $units = $cost->units();
        $articles = [];
        $suppliers = [];
        foreach ($pids as $pid) {
            $item = $cost->item($pid);
            $root = $cost->componentBaseRoot($pid);
            $articles[] = ['product_id' => $pid, 'label' => trim(($item?->code ? ltrim((string) $item->code, "\u{FEFF}") . ' · ' : '') . ($item?->description ?? $pid)),
                'base_unit' => mb_strtolower((string) ($units[$root]->description ?? 'unidade'))];
            foreach ($lines[$pid] ?? [] as $l) {
                if ($l['qnt'] <= 0 || $l['total'] <= 0) {
                    continue;
                }
                $confirmed = $l['strict_qnt_base'] !== null && $l['strict_qnt_base'] > 0;
                $qb = $confirmed ? $l['strict_qnt_base'] : $l['qnt_base'];
                if ($qb <= 0) {
                    continue;
                }
                $sk = (SupplierArticleMapService::nif($l['nif']) ?: $l['supplier_id'] ?: (string) $l['supplier']) . '|' . $pid;
                $s = &$suppliers[$sk];
                $s ??= ['key' => $sk, 'product_id' => $pid, 'supplier' => (string) ($l['supplier'] ?: 'Sem nome'), 'supplier_id' => $l['supplier_id'], 'points' => []];
                $s['points'][] = ['date' => $l['date'], 'price' => round($l['total'] / $qb, 4), 'qty' => round($qb, 3), 'document' => (string) $l['document'],
                    'docheader_id' => $l['docheader_id'], 'confirmed' => $confirmed];
                unset($s);
            }
        }
        foreach ($suppliers as &$s) {
            $recent = array_values(array_filter($s['points'], fn ($p) => $p['date'] >= $since90));
            $prices = array_column($recent, 'price');
            sort($prices);
            $last = end($s['points']);
            $s += [
                'last_price' => $last['price'], 'last_date' => $last['date'], 'last_document' => $last['document'],
                'median_90' => $prices ? $prices[intdiv(count($prices), 2)] : null,
                'qty_90' => round(array_sum(array_column($recent, 'qty')), 3), 'purchases_90' => count($recent),
                'confirmed' => ! in_array(false, array_column($s['points'], 'confirmed'), true),
            ];
        }
        unset($s);
        $suppliers = array_values($suppliers);
        usort($suppliers, fn ($a, $b) => [$a['median_90'] === null, $a['last_price']] <=> [$b['median_90'] === null, $b['last_price']]);

        return ApiResponse::success(['articles' => $articles, 'suppliers' => $suppliers, 'since_90' => $since90,
            'financial' => ['visible' => true, 'note' => null]], 'Histórico de preços.');
    }
}
