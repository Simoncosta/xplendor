<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinStock;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Fatia 7b: peças comuns dos documentos de stock (Contagem, Desperdício): a loja em que se
 * fazem (a que todos os armazéns servem: o Economato), a procura do documento na lista do
 * PingWin para confirmar, e a releitura do stock dos artigos depois de gravar.
 */
final class StockDocuments
{
    public function __construct(private int $companyId, private PingwinService $pingwin, private StockSyncService $sync) {}

    /** A loja dos documentos de stock: a definição da empresa (antes STOCK_DOC_STORE_ID); senão a que todos os armazéns ativos servem. */
    public static function storeId(int $companyId): ?string
    {
        if ($id = \App\Settings\Settings::company($companyId, 'stock.document_store_id')) {
            return (string) $id;
        }
        $sets = PingwinWarehouse::where('company_id', $companyId)->where('deleted', false)->get()->map(fn ($w) => $w->servedStoreIds())->filter()->values()->all();
        if (! $sets) {
            return null;
        }
        $common = array_values(array_intersect(...array_map(fn ($s) => array_values($s), count($sets) > 1 ? $sets : [$sets[0], $sets[0]])));

        return $common[0] ?? null;
    }

    /**
     * Procura um documento na lista do PingWin (contagens: which=count; desperdício: docs com o tipo).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $docId, string $date, string $which, string $doctypeLike = ''): ?array
    {
        $d = CarbonImmutable::parse($date);
        $res = $this->pingwin->stockDocument($this->companyId, 'stock_documents', [
            'start' => $d->subDay()->toDateString(), 'end' => $d->addDay()->toDateString(), 'which' => $which, 'doctype_like' => $doctypeLike,
        ]);
        foreach ((array) ($res['documents'] ?? []) as $doc) {
            if ((string) ($doc['id'] ?? '') === $docId) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * As linhas de UM documento no relatório Movimentos (lido agora, sem cache): código do artigo → quantidade
     * com sinal no armazém. Serve para confirmar a Contagem: o PingWin não reflete as contagens no stock do
     * artigo (tbstock) logo a seguir (provado na Fatia 7c, passo 0-A), mas o documento aparece nos Movimentos.
     *
     * @param  list<string>  $codes
     * @return array<string, float>
     */
    public function documentMovements(string $docId, string $date, array $codes, string $warehouseId): array
    {
        $codes = array_values(array_filter(array_map('strval', $codes)));
        if (! $codes) {
            return [];
        }
        sort($codes);
        $d = CarbonImmutable::parse($date);
        [$from, $to, $cf, $ct] = [$d->subDay()->toDateString(), $d->addDay()->toDateString(), $codes[0], $codes[count($codes) - 1]];
        \Illuminate\Support\Facades\Cache::forget(StockMovementsService::key($this->companyId, $from, $to, $cf, $ct));
        $out = [];
        foreach ((new StockMovementsService($this->pingwin))->fetch($this->companyId, $from, $to, $cf, $ct) as $m) {
            if ((string) ($m['docheader_id'] ?? '') !== $docId || (string) ($m['warehouse_id'] ?? '') !== $warehouseId) {
                continue;
            }
            $code = (string) $m['article_code'];
            $out[$code] = ($out[$code] ?? 0) + (float) $m['signed_qty'];
        }

        return $out;
    }

    /**
     * Relê do PingWin o stock destes artigos e atualiza o espelho. Devolve product_id → qnt no armazém.
     *
     * @param  list<string>  $productIds
     * @return array<string, float|null>
     */
    public function reread(array $productIds, string $warehouseId): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($productIds)), StockSyncService::BATCH_SIZE) as $chunk) {
            $this->sync->syncBatch($this->companyId, $chunk);
        }
        foreach (PingwinStock::where('company_id', $this->companyId)->where('warehouse_pingwin_id', $warehouseId)->whereIn('product_pingwin_id', $productIds)->get() as $s) {
            $out[(string) $s->product_pingwin_id] = $s->qnt;
        }
        foreach ($productIds as $p) {
            $out[(string) $p] ??= null;
        }

        return $out;
    }
}
