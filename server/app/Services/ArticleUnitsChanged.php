<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunUnitsAuditJob;
use App\Models\ArticleUnitChange;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnit;
use App\Models\ProductUnitProfile;
use Illuminate\Support\Facades\DB;

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
        $n = self::forgetLaunchUnits(PingwinCatalogItem::where('company_id', $companyId)
            ->whereIn('pingwin_id', array_map('strval', $pingwinIds))->pluck('id')->all());
        RunUnitsAuditJob::dispatch($companyId);

        return $n;
    }

    /**
     * Depois de as unidades de um artigo mudarem por uma leitura ("Atualizar do PingWin", abrir o
     * artigo, a leitura de domingo) ou por uma escrita da XPLENDOR: as faturas por lançar que o usam
     * deixam a escolha guardada (as guardas do lançamento são calculadas de novo a cada pré-visualização),
     * a auditoria de unidades corre e, se a unidade base mudou, o perfil decidido no Guião fica "por rever".
     */
    public static function afterChange(PingwinCatalogItem $item, ?string $baseBefore, bool $audit = true, ?string $purchaseBefore = null): void
    {
        self::forgetLaunchUnits([$item->id]);
        if ($audit) {
            RunUnitsAuditJob::dispatch((int) $item->company_id);
        }
        self::recordHistoryChange($item, ['base' => $baseBefore, 'purchase' => $purchaseBefore]);

        $baseAfter = (string) $item->base_unit_id;
        if ($baseBefore === null || $baseBefore === '' || $baseAfter === '' || $baseBefore === $baseAfter) {
            return;
        }
        $profile = ProductUnitProfile::where('company_id', $item->company_id)->where('product_pingwin_id', (string) $item->pingwin_id)->first();
        if (! $profile || ! in_array($profile->state, ['confirmado', 'aplicado_no_pingwin'], true)) {
            return;
        }
        $names = PingwinUnit::where('company_id', $item->company_id)->whereIn('pingwin_id', [$baseBefore, $baseAfter])->get(['pingwin_id', 'description', 'shortname'])
            ->mapWithKeys(fn ($u) => [(string) $u->pingwin_id => trim($u->description . ($u->shortname ? " ({$u->shortname})" : ''))]);
        $profile->update(['state' => ProductUnitProfile::STATE_REVIEW,
            'reason' => sprintf('A unidade base mudou no PingWin (%s → %s): confirme de novo.', $names[$baseBefore] ?? $baseBefore, $names[$baseAfter] ?? $baseAfter)]);
    }

    /**
     * A unidade base ou de compra de um artigo com histórico de compras mudou: as linhas antigas ficam na
     * unidade anterior. Fica registado para o aviso no artigo e no Guião. Sem id antes (a cópia ainda não
     * o tinha) não é uma mudança.
     *
     * @param array<string, ?string> $before field => id da unidade antes
     */
    private static function recordHistoryChange(PingwinCatalogItem $item, array $before): void
    {
        $cols = ['base' => 'base_unit_id', 'purchase' => 'default_purchase_unit_id'];
        $changed = array_filter($cols, fn ($col, $field) => ($before[$field] ?? '') !== '' && (string) $item->{$col} !== ''
            && (string) $item->{$col} !== $before[$field], ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }
        $lines = DB::table('pingwin_supplier_document_lines')->where('company_id', $item->company_id)->where('product_pingwin_id', (string) $item->pingwin_id)->count();
        if ($lines === 0) {
            return;
        }
        $ids = array_merge(array_values(array_intersect_key($before, $changed)), array_map(fn ($col) => (string) $item->{$col}, array_values($changed)));
        $names = PingwinUnit::where('company_id', $item->company_id)->whereIn('pingwin_id', $ids)->get(['pingwin_id', 'description', 'shortname'])
            ->mapWithKeys(fn ($u) => [(string) $u->pingwin_id => trim($u->description . ($u->shortname ? " ({$u->shortname})" : ''))]);
        foreach ($changed as $field => $col) {
            $to = (string) $item->{$col};
            ArticleUnitChange::create(['company_id' => $item->company_id, 'product_pingwin_id' => (string) $item->pingwin_id, 'field' => $field,
                'from_unit_id' => $before[$field], 'from_name' => $names[$before[$field]] ?? $before[$field],
                'to_unit_id' => $to, 'to_name' => $names[$to] ?? $to, 'history_lines' => $lines, 'changed_at' => now()]);
        }
    }
}
