<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Stock;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinWarehouse;
use App\Services\Restaurant\RecipeCostService;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 7a · Armazém de loja que deixou de receber (informação, um por armazém). O Economato (o
 * armazém ativo que não serve nenhuma loja de vendas) passou a receber a maior parte das compras
 * (60% ou mais do valor em todos os meses desde o mês M, e menos antes) e um armazém de loja deixou de
 * receber os artigos que recebia: dos que entraram nele nos 3 meses antes de M, no máximo 20%
 * voltaram a entrar desde M, e pelo menos 10 passaram a entrar só no Economato. As vendas da loja
 * continuam a descontar do armazém da loja: sem transferências registadas, o stock da loja só desce e
 * o do Economato só sobe. Lê as linhas das faturas de fornecedor (o armazém de cada linha); os artigos que a
 * leitura do stock disse que não mexem stock (despesas, serviços) ficam de fora.
 */
final class ArmazemSemEntradasRule extends StockRule
{
    public const SHARE = 0.60;

    public const MONTHS = 7;

    public function code(): string
    {
        return 'restauracao.armazem_sem_entradas';
    }

    public function entityType(): string
    {
        return 'armazem';
    }

    public function title(): string
    {
        return 'Armazéns de loja que deixaram de receber compras';
    }

    public function description(): string
    {
        return 'Armazéns de loja que deixaram de receber os artigos que agora entram só no Economato.';
    }

    public function severity(): string
    {
        return 'info';
    }

    public function screens(): array
    {
        return ['stock', 'armazens'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = self::data($scope);
        $consumption = $data->consumption();
        $salesStores = array_map('strval', array_keys($consumption->storeWarehouses()));
        $warehouses = PingwinWarehouse::where('company_id', $companyId)->where('deleted', false)->get();
        $eco = $warehouses->filter(fn ($w) => ! array_intersect($w->servedStoreIds(), $salesStores))->values();
        $shopWh = $warehouses->filter(fn ($w) => array_intersect($w->servedStoreIds(), $salesStores))->values();
        if ($eco->isEmpty() || $shopWh->isEmpty()) {
            return;
        }

        $today = CarbonImmutable::parse($data->today());
        $from = $today->startOfMonth()->subMonths(self::MONTHS - 1);
        $lines = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereIn('d.docconfig_id', RecipeCostService::PURCHASE_DOCCONFIGS)
            ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)
            ->whereBetween('d.doc_date', [$from->toDateString(), $today->toDateString()])
            ->whereNotNull('l.warehouse_pingwin_id')
            ->get(['l.product_pingwin_id', 'l.warehouse_pingwin_id', 'l.total_cents', 'd.doc_date']);
        if ($lines->isEmpty()) {
            return;
        }

        // O Economato: entre os que não servem lojas de vendas, o que recebeu mais.
        $byWh = $lines->groupBy('warehouse_pingwin_id')->map(fn ($g) => $g->sum('total_cents'));
        $ecoId = (string) $eco->sortByDesc(fn ($w) => $byWh[(string) $w->pingwin_id] ?? 0)->first()->pingwin_id;

        // O mês M: desde M, o Economato recebe ≥ 60% do valor todos os meses; antes de M, não.
        $months = [];
        foreach ($lines as $l) {
            $m = substr((string) $l->doc_date, 0, 7);
            $months[$m]['total'] = ($months[$m]['total'] ?? 0) + (int) $l->total_cents;
            $months[$m]['eco'] = ($months[$m]['eco'] ?? 0) + ((string) $l->warehouse_pingwin_id === $ecoId ? (int) $l->total_cents : 0);
        }
        ksort($months);
        $keys = array_keys($months);
        $start = null;
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $m = $months[$keys[$i]];
            if ($m['total'] > 0 && $m['eco'] / $m['total'] >= self::SHARE) {
                $start = $i;
            } else {
                break;
            }
        }
        if ($start === null || $start === 0) {
            return; // nunca mudou (ou sempre foi assim, sem um "antes" para comparar)
        }
        $M = $keys[$start] . '-01';
        $before = CarbonImmutable::parse($M)->subMonths(3)->toDateString();
        $ecoShare = array_sum(array_map(fn ($k) => $months[$k]['eco'], array_slice($keys, $start))) / max(1, array_sum(array_map(fn ($k) => $months[$k]['total'], array_slice($keys, $start))));

        // Só os artigos que mexem stock: os que a leitura do stock disse que não (despesas, serviços) ficam de fora.
        $noStock = array_fill_keys(\App\Models\PingwinStockItem::where('company_id', $companyId)->where('movestock', false)
            ->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all(), true);
        $ecoAfter = [];
        $recvBefore = [];
        $recvAfter = [];
        foreach ($lines as $l) {
            $p = (string) $l->product_pingwin_id;
            $w = (string) $l->warehouse_pingwin_id;
            $d = substr((string) $l->doc_date, 0, 10);
            if ($p === '' || isset($noStock[$p])) {
                continue;
            }
            if ($d >= $M) {
                $recvAfter[$w][$p] = true;
                if ($w === $ecoId) {
                    $ecoAfter[$p] = ($ecoAfter[$p] ?? 0) + (int) $l->total_cents;
                }
            } elseif ($d >= $before) {
                $recvBefore[$w][$p] = true;
            }
        }

        $ecoName = $this->warehouseName($companyId, $ecoId);
        foreach ($shopWh as $wh) {
            $wid = (string) $wh->pingwin_id;
            $used = array_keys($recvBefore[$wid] ?? []);
            if (count($used) < 10) {
                continue;
            }
            $still = array_filter($used, fn ($p) => isset($recvAfter[$wid][$p]));
            $moved = array_values(array_filter($used, fn ($p) => isset($ecoAfter[$p]) && ! isset($recvAfter[$wid][$p])));
            if (count($moved) < 10 || count($still) / count($used) > 0.2) {
                continue;
            }
            usort($moved, fn ($a, $b) => $ecoAfter[$b] <=> $ecoAfter[$a]);
            $movedCents = array_sum(array_map(fn ($p) => $ecoAfter[$p], $moved));
            $examples = array_map(fn ($p) => $data->item((string) $p)?->description ?? (string) $p, array_slice($moved, 0, 3));
            $storeNames = array_values(array_filter(array_map(fn ($s) => in_array($s['store_id'], $salesStores, true) && $s['served'] ? ($s['name'] ?? $s['code']) : null, (array) $wh->stores)));
            $monthRows = array_map(fn ($k) => [
                'month' => $k . '-01',
                'eco' => round($months[$k]['eco'] / 100, 2),
                'shop' => round($lines->filter(fn ($l) => substr((string) $l->doc_date, 0, 7) === $k && (string) $l->warehouse_pingwin_id === $wid)->sum('total_cents') / 100, 2),
                'share' => Fmt::pct($months[$k]['total'] > 0 ? $months[$k]['eco'] / $months[$k]['total'] * 100 : null),
            ], $keys);

            yield new AuditFindingData(
                entityType: 'armazem', entityId: $wid, entityLabel: (string) $wh->description,
                title: Fmt::title((string) $wh->description, 'deixou de receber compras'),
                why: sprintf('As vendas %s descontam deste armazém. Se o que entra no %s não é transferido no PingWin, o stock da loja só desce e o do %s só sobe: os dois ficam errados.',
                    $storeNames ? 'de ' . self::join($storeNames) : 'da loja', $ecoName, $ecoName),
                suggestion: sprintf('Registe no PingWin as transferências do %s para este armazém. Se a loja passou a ser servida pelo %s, mude o armazém por omissão da loja em Restauração › Armazéns. Se está tudo certo, ignore com esse motivo.', $ecoName, $ecoName),
                evidence: [
                    'facts' => [
                        ['label' => 'Desde', 'value' => Fmt::date($M)],
                        ['label' => "Parte das compras no {$ecoName}", 'value' => Fmt::pct($ecoShare * 100)],
                        ['label' => 'Artigos que este armazém recebia', 'value' => count($used)],
                        ['label' => 'Ainda recebe', 'value' => count($still)],
                        ['label' => "Passaram a entrar só no {$ecoName}", 'value' => count($moved)],
                    ],
                    'table' => [
                        'title' => 'Compras por mês',
                        'columns' => [
                            ['key' => 'month', 'label' => 'Mês', 'format' => 'date'], ['key' => 'eco', 'label' => $ecoName, 'align' => 'end', 'format' => 'eur'],
                            ['key' => 'shop', 'label' => (string) $wh->description, 'align' => 'end', 'format' => 'eur'],
                            ['key' => 'share', 'label' => "Parte do {$ecoName}", 'align' => 'end'],
                        ],
                        'rows' => array_reverse($monthRows),
                    ],
                ],
                signature: $M,
                weightCents: $movedCents > 0 ? $movedCents : null,
                fixUrl: '/restauracao/armazens', fixLabel: 'Abrir os armazéns',
                refs: [['armazem', $wid], ['armazem', $ecoId]],
                seen: sprintf('Desde %s, o %s recebe %s do valor das compras. Dos %d artigos que o %s recebia nos 3 meses antes, só %d lá entraram desde então; %d passaram a entrar só no %s (por exemplo, %s).',
                    Fmt::date($M), $ecoName, Fmt::pct($ecoShare * 100), count($used), $wh->description, count($still), count($moved), $ecoName, self::join($examples)),
                stake: sprintf('Não há um valor direto em jogo. A relevância é o que estes %d artigos custaram no %s desde %s: %s.', count($moved), $ecoName, Fmt::date($M), Fmt::cents($movedCents)),
            );
        }
    }
}
