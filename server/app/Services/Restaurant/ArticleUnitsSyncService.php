<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Jobs\RunUnitsAuditJob;
use App\Models\PingwinCatalogItem;
use App\Services\ArticleUnitsChanged;
use App\Services\PingwinService;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo da PingWin: os ids das unidades (base, compra, venda e stock) em todos os artigos ativos, não
 * só nos que têm ficha técnica (a leitura do catálogo, o browserdataset, só traz os nomes). Lê-se artigo
 * a artigo, com o mesmo OPEN e CLOSE da leitura do stock (SÓ LEITURA), em lotes de BATCH_SIZE. Corre à
 * segunda-feira às 01:30 de Lisboa, depois da leitura do catálogo de domingo e fora do horário de serviço
 * (ver routes/console.php): ~1500 artigos a ~0,9 s ≈ 25 minutos.
 */
final class ArticleUnitsSyncService
{
    /** Artigos por docker exec (~0,9 s cada, como o stock → ~45 s por lote). */
    public const BATCH_SIZE = 50;
    public const BATCH_TIMEOUT = 900;

    public function __construct(private readonly PingwinService $pingwin) {}

    /** Os artigos ativos da empresa, por ordem estável (id do PingWin). @return list<string> */
    public function candidates(int $companyId): array
    {
        return PingwinCatalogItem::where('company_id', $companyId)->where('is_active', true)->orderBy('pingwin_id')
            ->pluck('pingwin_id')->map(fn ($v) => (string) $v)->filter()->values()->all();
    }

    /**
     * Lê e grava um lote: os quatro ids e os nomes de compra e venda na cópia. Os artigos cujas unidades
     * mudaram passam pela reanálise (escolhas guardadas nas faturas, Guião, aviso do histórico); a
     * auditoria de unidades pede-se uma vez por lote.
     *
     * @param  list<string>  $productIds
     * @return array{articles: int, changed: int, failed: int, errors: array<string, string>}
     */
    public function syncBatch(int $companyId, array $productIds): array
    {
        $res = $this->pingwin->fetchArticleUnits($companyId, $productIds, self::BATCH_TIMEOUT);
        $stats = ['articles' => 0, 'changed' => 0, 'failed' => 0, 'errors' => []];
        foreach ((array) ($res['errors'] ?? []) as $pid => $error) {
            $stats['failed']++;
            $stats['errors'][(string) $pid] = mb_substr((string) $error, 0, 300);
        }
        $items = PingwinCatalogItem::where('company_id', $companyId)->whereIn('pingwin_id', array_map('strval', array_keys((array) ($res['units'] ?? []))))
            ->get()->keyBy(fn ($i) => (string) $i->pingwin_id);
        foreach ((array) ($res['units'] ?? []) as $pid => $u) {
            $item = $items[(string) $pid] ?? null;
            if (! $item) {
                continue;
            }
            $stats['articles']++;
            $before = ['base' => (string) $item->base_unit_id, 'purchase' => (string) $item->default_purchase_unit_id];
            $item->fill(array_filter([
                'base_unit_id' => $u['base']['id'] ?? null, 'default_purchase_unit_id' => $u['purchase']['id'] ?? null,
                'default_sale_unit_id' => $u['sale']['id'] ?? null, 'default_stock_unit_id' => $u['stock']['id'] ?? null,
                'purchaseunit' => $u['purchase']['name'] ?? null, 'saleunit' => $u['sale']['name'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));
            if (! $item->isDirty()) {
                continue;
            }
            try {
                $item->save();
                $stats['changed']++;
                ArticleUnitsChanged::afterChange($item, $before['base'], audit: false, purchaseBefore: $before['purchase']);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][(string) $pid] = mb_substr($e->getMessage(), 0, 300);
                Log::warning('[PingWin Unidades] gravação falhou', ['company_id' => $companyId, 'product' => $pid, 'error' => $e->getMessage()]);
            }
        }
        if ($stats['changed'] > 0) {
            RunUnitsAuditJob::dispatch($companyId);
        }

        return $stats;
    }
}
