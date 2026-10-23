<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinCatalogItem;

/**
 * As unidades de um artigo mudaram (os ids no PingWin, ou os nomes da leitura do catálogo): a escolha
 * de unidade guardada nas linhas das faturas por lançar (launch_unit_id) deixa de valer e é
 * descartada; a linha volta a usar a unidade lida na fatura, ou pede uma escolha nova. As faturas já
 * lançadas não mudam.
 */
final class ArticleUnitsChanged
{
    /** As colunas das unidades do artigo (as que contam como "as unidades mudaram"). */
    public const COLUMNS = ['base_unit_id', 'default_purchase_unit_id', 'default_sale_unit_id', 'default_stock_unit_id', 'purchaseunit', 'saleunit'];

    /** Descarta a escolha guardada nas linhas por lançar destes artigos (ids do catálogo). Devolve quantas linhas. */
    public static function forgetLaunchUnits(array $catalogItemIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $catalogItemIds))));
        if ($ids === []) {
            return 0;
        }

        return OcrInvoiceLine::whereIn('article_id', $ids)->whereNotNull('launch_unit_id')
            ->whereIn('ocr_invoice_id', OcrInvoice::query()->select('id')
                ->whereIn('status', ['por_validar', 'validada'])->where('link_status', OcrPingwinLinkService::NOT_LAUNCHED))
            ->update(['launch_unit_id' => null]);
    }

    /** O mesmo, pelos ids no PingWin (a leitura do catálogo grava em lote, sem eventos do modelo). */
    public static function forPingwinIds(int $companyId, array $pingwinIds): int
    {
        if ($pingwinIds === []) {
            return 0;
        }

        return self::forgetLaunchUnits(PingwinCatalogItem::where('company_id', $companyId)
            ->whereIn('pingwin_id', array_map('strval', $pingwinIds))->pluck('id')->all());
    }
}
