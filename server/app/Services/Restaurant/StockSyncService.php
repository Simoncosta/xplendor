<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockRun;
use App\Models\PingwinStockSnapshot;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fatia 7a: o espelho do stock do PingWin (SÓ LEITURA).
 *
 *  · Não há no PingWin uma listagem do stock de todos os artigos numa só chamada (procurado no
 *    scraper e nos HAR de 15/10): lê-se artigo a artigo (OPEN → GET,INFO tbstock → CLOSE), em lotes
 *    de 50 por docker exec. No teste real, cerca de 0,9 s por artigo.
 *  · Que artigos: os ativos que mexem stock (ou que ainda não se sabe, porque o catálogo não traz a
 *    configuração de stock) e que tenham compras nos últimos 12 meses, apareçam numa ficha ou
 *    tenham stock diferente de 0 na última leitura. Um artigo lido com uma configuração que não
 *    mexe stock ("Sem stock") sai da lista nas leituras seguintes.
 *  · Cada leitura substitui as linhas desse artigo (um armazém que deixou de vir sai) e grava a
 *    fotografia do dia (uma por dia: a última leitura do dia ganha).
 *  · Os armazéns leem-se à parte (lista e formulário de cada um: lojas servidas e valorização).
 */
final class StockSyncService
{
    /** Artigos por docker exec (~0,9 s cada → ~45 s por lote). */
    public const BATCH_SIZE = 50;

    /** Timeout de cada lote (s). */
    public const BATCH_TIMEOUT = 900;

    /** Segundos por artigo, medidos no teste real (5 artigos, 15/10/2026). Para a estimativa. */
    public const SECONDS_PER_ARTICLE = 0.9;

    public function __construct(private PingwinService $pingwin) {}

    /**
     * Os artigos a ler, por ordem estável (id do PingWin).
     *
     * @return list<string>
     */
    public function candidates(int $companyId): array
    {
        $since = CarbonImmutable::now('Europe/Lisbon')->subMonths(12)->toDateString();
        $bought = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereIn('d.docconfig_id', RecipeCostService::PURCHASE_DOCCONFIGS)
            ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)->where('d.doc_date', '>=', $since)
            ->whereNotNull('l.product_pingwin_id')->distinct()->pluck('l.product_pingwin_id');
        $inBoms = DB::table('pingwin_product_bom_lines')->where('company_id', $companyId)->whereNotNull('component_pingwin_id')
            ->distinct()->pluck('component_pingwin_id');
        $withStock = PingwinStock::where('company_id', $companyId)->where('qnt', '!=', 0)->distinct()->pluck('product_pingwin_id');

        $wanted = array_fill_keys($bought->merge($inBoms)->merge($withStock)->map(fn ($v) => (string) $v)->filter()->all(), true);
        $noStock = array_fill_keys(PingwinStockItem::where('company_id', $companyId)->where('movestock', false)
            ->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all(), true);
        $inactive = array_fill_keys(PingwinCatalogItem::where('company_id', $companyId)->where('is_active', false)
            ->pluck('pingwin_id')->map(fn ($v) => (string) $v)->all(), true);
        $known = array_fill_keys(PingwinCatalogItem::where('company_id', $companyId)->pluck('pingwin_id')->map(fn ($v) => (string) $v)->all(), true);

        $ids = array_keys(array_filter($wanted, fn ($v, $id) => isset($known[$id]) && ! isset($noStock[$id]) && ! isset($inactive[$id]), ARRAY_FILTER_USE_BOTH));
        sort($ids, SORT_STRING);

        return array_values(array_map('strval', $ids));
    }

    /** Tempo estimado da leitura de $count artigos, em segundos. */
    public static function estimateSeconds(int $count, ?float $perArticle = null): int
    {
        $per = $perArticle ?? self::SECONDS_PER_ARTICLE;
        // + login e logout de cada lote (~3 s).
        return (int) ceil($count * $per + ceil($count / self::BATCH_SIZE) * 3);
    }

    /**
     * Lê e grava um lote.
     *
     * @param  list<string>  $productIds
     * @return array{articles:int, rows:int, failed:int, errors:array<string,string>, seconds:array<string,float>, stock?:array}
     */
    public function syncBatch(int $companyId, array $productIds, bool $dryRun = false): array
    {
        $res = $this->pingwin->fetchStock($companyId, $productIds, self::BATCH_TIMEOUT);
        $stats = ['articles' => 0, 'rows' => 0, 'failed' => 0, 'errors' => [], 'seconds' => (array) $res['seconds']];

        foreach ($res['errors'] as $pid => $error) {
            $stats['failed']++;
            $stats['errors'][(string) $pid] = (string) $error;
            Log::warning('[PingWin Stock] leitura falhou', ['company_id' => $companyId, 'product' => $pid, 'error' => $error]);
            if (! $dryRun) {
                PingwinStockItem::updateOrCreate(['company_id' => $companyId, 'product_pingwin_id' => (string) $pid],
                    ['last_error' => mb_substr((string) $error, 0, 300)]);
            }
        }
        foreach ($res['stock'] as $pid => $stock) {
            $stats['articles']++;
            $stats['rows'] += count($stock['rows'] ?? []);
            if ($dryRun) {
                continue;
            }
            try {
                $this->persist($companyId, (string) $pid, (array) $stock);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][(string) $pid] = mb_substr($e->getMessage(), 0, 300);
                Log::warning('[PingWin Stock] gravação falhou', ['company_id' => $companyId, 'product' => $pid, 'error' => $e->getMessage()]);
            }
        }
        if ($dryRun) {
            $stats['stock'] = $res['stock'];
        }

        return $stats;
    }

    /** Grava o stock de UM artigo (todas as linhas), a fotografia do dia e o estado da leitura. */
    public function persist(int $companyId, string $productId, array $stock): void
    {
        $now = now();
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $article = (array) ($stock['article'] ?? []);
        $rows = (array) ($stock['rows'] ?? []);

        DB::transaction(function () use ($companyId, $productId, $article, $rows, $now, $today) {
            $seen = [];
            foreach ($rows as $r) {
                $wid = (string) ($r['warehouse_id'] ?? '');
                if ($wid === '') {
                    continue;
                }
                $seen[] = $wid;
                PingwinStock::updateOrCreate(
                    ['company_id' => $companyId, 'product_pingwin_id' => $productId, 'warehouse_pingwin_id' => $wid],
                    [
                        'unit_name' => $r['unit_name'] ?? null, 'unit_value' => $r['unit_value'] ?? null,
                        'qnt' => (float) ($r['qnt'] ?? 0), 'pcm' => $r['pcm'] ?? null, 'pcu' => $r['pcu'] ?? null,
                        'valorization_date' => $r['valorization_date'] ?? null,
                        'min_stock' => $r['min_stock'] ?? null, 'max_stock' => $r['max_stock'] ?? null,
                        'disable_generic' => (bool) ($r['disable_generic'] ?? false),
                        'qnt_pend_cli' => (float) ($r['qnt_pend_cli'] ?? 0), 'qnt_pend_sup' => (float) ($r['qnt_pend_sup'] ?? 0),
                        'synced_at' => $now,
                    ],
                );
                PingwinStockSnapshot::updateOrCreate(
                    ['company_id' => $companyId, 'snapshot_date' => $today, 'product_pingwin_id' => $productId, 'warehouse_pingwin_id' => $wid],
                    ['qnt' => (float) ($r['qnt'] ?? 0), 'pcm' => $r['pcm'] ?? null, 'pcu' => $r['pcu'] ?? null],
                );
                if (($r['warehouse_name'] ?? '') !== '') {
                    PingwinWarehouse::firstOrCreate(['company_id' => $companyId, 'pingwin_id' => $wid],
                        ['description' => $r['warehouse_name'], 'code' => $r['warehouse_code'] ?? null]);
                }
            }
            PingwinStock::where('company_id', $companyId)->where('product_pingwin_id', $productId)
                ->when($seen, fn ($q) => $q->whereNotIn('warehouse_pingwin_id', $seen))->delete();
            PingwinStockSnapshot::where('company_id', $companyId)->where('product_pingwin_id', $productId)->where('snapshot_date', $today)
                ->when($seen, fn ($q) => $q->whereNotIn('warehouse_pingwin_id', $seen))->delete();

            PingwinStockItem::updateOrCreate(['company_id' => $companyId, 'product_pingwin_id' => $productId], [
                'stockconfig_id' => $article['stockconfig_id'] ?? null, 'stockconfig' => $article['stockconfig'] ?? null,
                'movestock' => isset($article['movestock']) ? (bool) $article['movestock'] : null,
                'stock_unit_id' => $article['stock_unit_id'] ?? null, 'stock_unit' => $article['stock_unit'] ?? null,
                'last_read_at' => $now, 'last_error' => null,
            ]);
        });
    }

    /** Lê os armazéns (lista e formulário de cada um) e grava o espelho. Devolve quantos gravou. */
    public function syncWarehouses(int $companyId): int
    {
        $res = $this->pingwin->fetchWarehouses($companyId);
        $now = now();
        $n = 0;
        foreach ($res['warehouses'] as $w) {
            $id = (string) ($w['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $values = ['code' => $w['code'] ?? null, 'description' => $w['description'] ?? null, 'deleted' => (bool) ($w['deleted'] ?? false), 'synced_at' => $now];
            // A valorização e as lojas só vêm do formulário: um armazém anulado (ou com erro) guarda as que tinha.
            foreach (['inv_costmethod', 'exit_costmethod'] as $k) {
                if (array_key_exists($k, $w) && $w[$k] !== null) {
                    $values[$k] = (int) $w[$k];
                }
            }
            if (array_key_exists('stores', $w)) {
                $values['stores'] = array_values((array) $w['stores']);
                $values['main_store_pingwin_id'] = $w['main_store_id'] ?? null;
            }
            PingwinWarehouse::updateOrCreate(['company_id' => $companyId, 'pingwin_id' => $id], $values);
            $n++;
        }
        foreach ($res['errors'] as $id => $error) {
            Log::warning('[PingWin Armazéns] leitura do formulário falhou', ['company_id' => $companyId, 'warehouse' => $id, 'error' => $error]);
        }

        return $n;
    }

    /** Abre o registo de uma leitura. */
    public function startRun(int $companyId, string $origin, int $total): PingwinStockRun
    {
        return PingwinStockRun::create(['company_id' => $companyId, 'origin' => $origin, 'status' => 'running',
            'products_total' => $total, 'started_at' => now()]);
    }

    /** Soma um lote ao registo da leitura. */
    public function addToRun(PingwinStockRun $run, array $batch): void
    {
        $run->increment('products_read', (int) ($batch['articles'] ?? 0));
        $run->increment('products_failed', (int) ($batch['failed'] ?? 0));
        $run->increment('rows', (int) ($batch['rows'] ?? 0));
    }

    public function finishRun(PingwinStockRun $run, ?string $error = null): void
    {
        $run->refresh();
        $run->forceFill([
            'status' => $error !== null || ($run->products_read === 0 && $run->products_total > 0) ? 'failed' : 'done',
            'error' => $error, 'finished_at' => now(),
            'seconds' => $run->started_at ? round(max(0, now()->diffInMilliseconds($run->started_at, true)) / 1000, 1) : null,
        ])->save();
    }
}
