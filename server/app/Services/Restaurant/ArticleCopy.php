<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnit;
use App\Services\ArticleUnitsChanged;

/**
 * A cópia local de um artigo do PingWin (pingwin_catalog_items): o que o ecrã mostra logo ao abrir,
 * antes de a leitura ao vivo chegar, e o que essa leitura grava (os ids e os nomes das unidades base,
 * de compra, de venda e de stock, e as unidades do artigo). Diagnóstico de 23/10 (COMIDA STAFF 23%).
 */
final class ArticleCopy
{
    /** As unidades do artigo: chave → [coluna do id, nome no ecrã]. */
    public const UNITS = [
        'base' => ['base_unit_id', 'Unidade base'],
        'purchase' => ['default_purchase_unit_id', 'Unidade de compra'],
        'sale' => ['default_sale_unit_id', 'Unidade de venda'],
        'stock' => ['default_stock_unit_id', 'Unidade de stock'],
    ];

    /** A cópia local, como o ecrã a mostra (sem ir ao PingWin). */
    public static function present(PingwinCatalogItem $item): array
    {
        $ids = array_values(array_filter(array_map(fn ($u) => (string) $item->{$u[0]}, self::UNITS)));
        $names = PingwinUnit::where('company_id', $item->company_id)->whereIn('pingwin_id', $ids)->get(['pingwin_id', 'description', 'shortname'])
            ->mapWithKeys(fn ($u) => [(string) $u->pingwin_id => trim($u->description . ($u->shortname ? " ({$u->shortname})" : ''))]);
        $units = [];
        foreach (self::UNITS as $key => [$col, $label]) {
            $id = (string) $item->{$col};
            $fallback = $key === 'purchase' ? $item->purchaseunit : ($key === 'sale' ? $item->saleunit : null);
            $units[$key] = ['label' => $label, 'id' => $id !== '' ? $id : null, 'name' => $id !== '' ? ($names[$id] ?? $fallback) : $fallback];
        }
        $readAt = collect([$item->live_read_at, $item->bom_synced_at, $item->synced_at])->filter()->map(fn ($d) => \Illuminate\Support\Carbon::parse($d))->max();

        return [
            'catalog_item_id' => $item->id, 'pingwin_id' => (string) $item->pingwin_id, 'code' => $item->code, 'description' => $item->description,
            'family' => $item->family, 'product_status' => $item->product_status, 'is_active' => (bool) $item->is_active,
            'forsale' => (bool) $item->forsale, 'forpurchase' => (bool) $item->forpurchase,
            'saleprice_cents' => $item->saleprice_cents, 'purchaseprice_cents' => $item->purchaseprice_cents,
            'units' => $units, 'read_at' => $readAt?->toIso8601String(), 'live_read_at' => $item->live_read_at ? \Illuminate\Support\Carbon::parse($item->live_read_at)->toIso8601String() : null,
            // As mudanças recentes da unidade base ou de compra num artigo com histórico (as linhas antigas ficam na anterior).
            'unit_changes' => \App\Models\ArticleUnitChange::recentFor((int) $item->company_id, [(string) $item->pingwin_id])[(string) $item->pingwin_id] ?? [],
        ];
    }

    /**
     * Grava na cópia o que a leitura ao vivo do artigo trouxe (readProduct): os ids e os nomes das
     * unidades e as unidades do artigo (nome, abreviatura e quantidade do pai). Devolve o que mudou face
     * à cópia (para o ecrã o assinalar) e, se as unidades mudaram, corre a reanálise do que usa o artigo.
     *
     * @return list<array{field: string, label: string, from: ?string, to: ?string}>
     */
    public static function applyRead(int $companyId, string $productId, array $product): array
    {
        $item = PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->first();
        $m = (array) ($product['maindataset'] ?? []);
        if (! $item || $m === [] || (string) ($m['id'] ?? $productId) !== $productId) {
            return [];
        }

        // As unidades do artigo (as listas da leitura): atualiza as que já se conhecem e acrescenta as novas.
        foreach (['base', 'sale', 'purchase'] as $list) {
            foreach ((array) ($product['lookups']['units'][$list] ?? []) as $u) {
                $id = (string) ($u['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $unit = PingwinUnit::firstOrNew(['company_id' => $companyId, 'pingwin_id' => $id]);
                $unit->fill(array_filter([
                    'description' => $u['description'] ?? null, 'shortname' => $u['shortname'] ?? null,
                    'product_pingwin_id' => ($u['product_id'] ?? '') !== '' ? (string) $u['product_id'] : null,
                    'parent_pingwin_id' => ($u['parent_id'] ?? '') !== '' ? (string) $u['parent_id'] : null,
                    'parent_qnt' => isset($u['parent_qnt']) && is_numeric($u['parent_qnt']) ? (float) $u['parent_qnt'] : null,
                ], fn ($v) => $v !== null));
                if (! $unit->exists) {
                    $unit->is_active = true;
                }
                if (! $unit->exists || $unit->isDirty()) {
                    $unit->save();
                }
            }
        }

        $before = self::present($item);
        $fill = ['live_read_at' => now()];
        foreach (self::UNITS as [$col]) {
            if (array_key_exists($col, $m)) {
                $fill[$col] = (string) $m[$col] !== '' ? (string) $m[$col] : null;
            }
        }
        foreach (['purchaseunit' => 'default_purchase_unit_id_descr', 'saleunit' => 'default_sale_unit_id_descr', 'description' => 'description', 'code' => 'code'] as $col => $key) {
            if (isset($m[$key]) && trim((string) $m[$key]) !== '') {
                $fill[$col] = trim((string) $m[$key]);
            }
        }
        $baseBefore = (string) $item->base_unit_id;
        $purchaseBefore = (string) $item->default_purchase_unit_id;
        $item->fill($fill);
        $unitsChanged = $item->isDirty(ArticleUnitsChanged::COLUMNS);
        $item->save();

        $after = self::present($item->fresh());
        $changes = [];
        foreach (self::UNITS as $key => [, $label]) {
            if (($before['units'][$key]['id'] ?? null) !== ($after['units'][$key]['id'] ?? null) || ($before['units'][$key]['name'] ?? null) !== ($after['units'][$key]['name'] ?? null)) {
                $changes[] = ['field' => $key, 'label' => $label, 'from' => $before['units'][$key]['name'], 'to' => $after['units'][$key]['name']];
            }
        }
        if ($before['description'] !== $after['description']) {
            $changes[] = ['field' => 'description', 'label' => 'Descrição', 'from' => $before['description'], 'to' => $after['description']];
        }
        if ($unitsChanged) {
            ArticleUnitsChanged::afterChange($item->fresh(), $baseBefore, purchaseBefore: $purchaseBefore);
        }

        return $changes;
    }
}
