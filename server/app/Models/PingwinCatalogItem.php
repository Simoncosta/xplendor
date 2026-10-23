<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Artigo (produto) do catálogo PingWin, só leitura.
 * UNIQUE (company_id, pingwin_id) → UPSERT idempotente no sync.
 * Preços em CÊNTIMOS inteiros. supplier_code é nullable (matching é fase futura).
 */
class PingwinCatalogItem extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_id', 'code', 'description', 'family', 'family_pingwin_id', 'family_id',
        'forsale', 'forpurchase', 'has_bom', 'product_type', 'product_status',
        'taxgroup', 'printzone', 'saleprice_cents', 'purchaseprice_cents',
        'saleunit', 'purchaseunit', 'order_code', 'supplier_code', 'is_active', 'synced_at',
        // Campos de escrita (maindataset) + raw do servidor. Preparados; a escrita é a Etapa 1b.
        'raw', 'forproduction',
        'base_unit_id', 'default_sale_unit_id', 'default_purchase_unit_id',
        'default_stock_unit_id', 'label_unit_id', 'volume_unit_id',
        'taxgroup_id', 'stockconfig_id', 'printzone_id', 'product_type_id', 'status_id',
        'setexpireday', 'weight', 'default_supplier_id', 'fixedsupplier', 'obs',
        // Fatia 1 (fichas técnicas): o armazém do custo, o preço sem IVA e a hora da última leitura.
        'cost_warehouse_id', 'saleprice_wo_tax_cents', 'bom_synced_at',
        // A última leitura ao vivo do artigo (abrir ou "Atualizar do PingWin"), que grava as unidades na cópia.
        'live_read_at',
    ];

    protected $casts = [
        'forsale'             => 'boolean',
        'forpurchase'         => 'boolean',
        'forproduction'       => 'boolean',
        'fixedsupplier'       => 'boolean',
        'has_bom'             => 'boolean',
        'is_active'           => 'boolean',
        'saleprice_cents'     => 'integer',
        'purchaseprice_cents' => 'integer',
        'setexpireday'        => 'integer',
        'weight'              => 'float',
        'raw'                 => 'array',
        'synced_at'           => 'datetime',
        'live_read_at'        => 'datetime',
    ];

    /** As unidades do artigo mudaram: a escolha guardada nas linhas das faturas por lançar cai. */
    protected static function booted(): void
    {
        static::updated(function (self $item) {
            if ($item->wasChanged(\App\Services\ArticleUnitsChanged::COLUMNS)) {
                \App\Services\ArticleUnitsChanged::forgetLaunchUnits([$item->id]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Família ligada (religada após o sync das famílias; null = órfão). */
    public function familyRel(): BelongsTo
    {
        return $this->belongsTo(PingwinFamily::class, 'family_id');
    }

    /** Linhas de preço de fornecedor (tab Compras, espelho de tbsupprice). */
    public function supplierPrices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PingwinSupplierPrice::class, 'catalog_item_id');
    }
}
