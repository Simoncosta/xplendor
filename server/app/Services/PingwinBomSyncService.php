<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinProductBomLineCost;
use App\Models\PingwinProductBomStore;
use App\Models\PingwinUnit;
use App\Models\PingwinWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fatia 1: espelho das fichas técnicas do PingWin (SÓ LEITURA no PingWin).
 * Lê em lotes (um docker exec por lote; no Python cada ficha é aberta, lida e FECHADA) e grava
 * cada artigo numa transação própria: as versões, as lojas de cada versão, as linhas e o custo
 * de cada linha em "" e em cada armazém. Os semi-produtos vêm no mesmo lote (recursivo no Python,
 * até 5 níveis, sem repetir). Uma falha num artigo fica registada e não pára os outros.
 */
class PingwinBomSyncService
{
    /** Artigos por docker exec (~2 a 3 s por ficha com 3 armazéns → ~1 min por lote). */
    public const BATCH_SIZE = 25;

    /** Timeout de cada lote (s). */
    public const BATCH_TIMEOUT = 600;

    public function __construct(private PingwinService $pingwin) {}

    /**
     * Os artigos a ler: os que o catálogo marca com ficha e os que já estão no espelho (para
     * apanhar as fichas apagadas). Ordem estável (pelo id do PingWin).
     *
     * @return list<string>
     */
    public function candidates(int $companyId): array
    {
        $withBom = PingwinCatalogItem::where('company_id', $companyId)->where('has_bom', true)->pluck('pingwin_id');
        $mirrored = PingwinProductBom::where('company_id', $companyId)->distinct()->pluck('product_pingwin_id');

        return $withBom->merge($mirrored)->map(fn ($id) => (string) $id)->filter()->unique()->sort()->values()->all();
    }

    /**
     * Lê e grava um lote. Devolve as contas do lote (e, em dry-run, as fichas lidas, sem gravar).
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $skip  artigos já gravados neste ciclo (semi-produtos de lotes anteriores)
     * @return array{articles:int, versions:int, lines:int, failed:int, errors:array<string,string>, read:list<string>, boms?:array}
     */
    public function syncBatch(int $companyId, array $productIds, bool $dryRun = false, array $skip = []): array
    {
        $res = $this->pingwin->fetchBoms($companyId, $productIds, null, self::BATCH_TIMEOUT);
        $stats = ['articles' => 0, 'versions' => 0, 'lines' => 0, 'failed' => 0, 'errors' => [], 'read' => []];

        foreach ($res['errors'] as $pid => $error) {
            $stats['failed']++;
            $stats['errors'][(string) $pid] = (string) $error;
            Log::warning('[PingWin Fichas] leitura falhou', ['company_id' => $companyId, 'product' => $pid, 'error' => $error]);
        }

        foreach ($res['boms'] as $pid => $bom) {
            $pid = (string) $pid;
            $stats['read'][] = $pid;
            if (in_array($pid, $skip, true) && ! in_array($pid, $productIds, true)) {
                continue; // semi-produto já gravado neste ciclo
            }
            $stats['articles']++;
            $stats['versions'] += count($bom['versions'] ?? []);
            foreach ($bom['versions'] ?? [] as $v) {
                $stats['lines'] += count($v['lines'][''] ?? []);
            }
            if ($dryRun) {
                continue;
            }
            try {
                $this->persist($companyId, $bom);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][$pid] = mb_substr($e->getMessage(), 0, 300);
                Log::warning('[PingWin Fichas] gravação falhou', ['company_id' => $companyId, 'product' => $pid, 'error' => $e->getMessage()]);
            }
        }

        if ($dryRun) {
            $stats['boms'] = $res['boms'];
        }

        return $stats;
    }

    /** Grava a ficha de UM artigo (todas as versões), numa transação. Substitui o que havia. */
    public function persist(int $companyId, array $bom): void
    {
        $productId = (string) ($bom['id'] ?? '');
        if ($productId === '') {
            throw new \RuntimeException('ficha sem id do artigo');
        }
        $now = now();

        DB::transaction(function () use ($companyId, $bom, $productId, $now) {
            $oldBomIds = PingwinProductBom::where('company_id', $companyId)
                ->where('product_pingwin_id', $productId)->pluck('bom_pingwin_id')->all();
            $newBomIds = collect($bom['versions'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
            $touched = array_values(array_unique(array_merge($oldBomIds, $newBomIds)));
            if ($touched) {
                $lineIds = PingwinProductBomLine::where('company_id', $companyId)->whereIn('bom_pingwin_id', $touched)->pluck('id');
                PingwinProductBomLineCost::whereIn('bom_line_id', $lineIds)->delete();
                PingwinProductBomLine::whereIn('id', $lineIds)->delete();
                PingwinProductBomStore::where('company_id', $companyId)->whereIn('bom_pingwin_id', $touched)->delete();
                PingwinProductBom::where('company_id', $companyId)->whereIn('bom_pingwin_id', $touched)->delete();
            }

            foreach ($bom['versions'] ?? [] as $v) {
                $bomId = (string) $v['id'];
                PingwinProductBom::create([
                    'company_id' => $companyId, 'product_pingwin_id' => $productId, 'bom_pingwin_id' => $bomId,
                    'version_key' => $v['key'] ?? null, 'valid_from' => $v['valid_from'] ?? null,
                    'deleted' => (int) ($v['deleted'] ?? 0) === 1, 'synced_at' => $now,
                ]);
                foreach ($v['stores'] ?? [] as $s) {
                    PingwinProductBomStore::create([
                        'company_id' => $companyId, 'bom_pingwin_id' => $bomId, 'store_pingwin_id' => (string) $s['store_id'],
                        'store_code' => $s['store_code'] ?? null, 'store_name' => $s['store_name'] ?? null,
                        'active' => (bool) ($s['active'] ?? false),
                    ]);
                }
                $byWarehouse = (array) ($v['lines'] ?? []);
                foreach (array_values($byWarehouse[''] ?? []) as $i => $l) {
                    $line = PingwinProductBomLine::create([
                        'company_id' => $companyId, 'bom_pingwin_id' => $bomId,
                        'line_key' => (string) ($l['key'] ?? '') !== '' ? (string) $l['key'] : sprintf('%08d', $i),
                        'component_pingwin_id' => $l['component_id'] ?? null,
                        'component_code' => $l['component_code'] ?? null,
                        'component_description' => $l['component_description'] ?? null,
                        'unit_pingwin_id' => $l['unit_id'] ?? null, 'unit_desc' => $l['unit_desc'] ?? null,
                        'qnt' => $l['qnt'] ?? null, 'qnt_base' => $l['qnt_base'] ?? null,
                        'waste_pct' => (float) ($l['waste'] ?? 0), 'recovery_pct' => (float) ($l['recovery'] ?? 0),
                        'is_bom' => (int) ($l['isbom'] ?? 0) === 1, 'component_status' => $l['status'] ?? null,
                    ]);
                    foreach ($byWarehouse as $wh => $rows) {
                        $match = self::matchLine(array_values((array) $rows), $l, $i);
                        if ($match === null) {
                            continue;
                        }
                        PingwinProductBomLineCost::create([
                            'bom_line_id' => $line->id, 'warehouse_pingwin_id' => (string) $wh,
                            'unit_cost' => $match['unit_cost'] ?? null, 'cost' => $match['cost'] ?? null,
                        ]);
                    }
                }
            }

            foreach ($bom['warehouses'] ?? [] as $w) {
                if (($w['id'] ?? '') !== '') {
                    PingwinWarehouse::updateOrCreate(
                        ['company_id' => $companyId, 'pingwin_id' => (string) $w['id']],
                        ['description' => $w['description'] ?? null],
                    );
                }
            }

            $item = PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->first();
            if ($item) {
                $units = (array) ($bom['units'] ?? []);
                $wo = $bom['prices']['saleprice_wo_tax'] ?? null;
                $item->fill(array_filter([
                    'base_unit_id' => $units['base'] ?? null,
                    'default_sale_unit_id' => $units['sale'] ?? null,
                    'default_purchase_unit_id' => $units['purchase'] ?? null,
                    'default_stock_unit_id' => $units['stock'] ?? null,
                    'stockconfig_id' => $bom['stockconfig_id'] ?? null,
                ], static fn ($v) => $v !== null));
                foreach (['forsale', 'forpurchase', 'forproduction'] as $flag) {
                    if (isset($bom[$flag])) {
                        $item->{$flag} = (int) $bom[$flag] === 1;
                    }
                }
                $item->cost_warehouse_id = $bom['cost_warehouse_id'] ?? null;
                $item->saleprice_wo_tax_cents = is_numeric($wo) ? (int) round((float) $wo * 100) : null;
                $item->bom_synced_at = $now;
                $item->save();
            }

            // As unidades do artigo: só completa a quantidade do pai (e o pai, se faltar).
            foreach ($bom['unit_list'] ?? [] as $u) {
                $unit = PingwinUnit::where('company_id', $companyId)->where('pingwin_id', (string) $u['id'])->first();
                if (! $unit) {
                    continue;
                }
                if (isset($u['parent_qnt']) && is_numeric($u['parent_qnt'])) {
                    $unit->parent_qnt = (float) $u['parent_qnt'];
                }
                if (! $unit->parent_pingwin_id && ! empty($u['parent_id'])) {
                    $unit->parent_pingwin_id = (string) $u['parent_id'];
                }
                if ($unit->isDirty()) {
                    $unit->save();
                }
            }
        });
    }

    /** A mesma linha noutro armazém: pela key; se não houver, pela posição com o mesmo componente. */
    private static function matchLine(array $rows, array $line, int $index): ?array
    {
        foreach ($rows as $r) {
            if (($r['key'] ?? null) === ($line['key'] ?? null) && ($r['component_id'] ?? null) === ($line['component_id'] ?? null)) {
                return $r;
            }
        }
        $r = $rows[$index] ?? null;

        return $r && ($r['component_id'] ?? null) === ($line['component_id'] ?? null) ? $r : null;
    }
}
