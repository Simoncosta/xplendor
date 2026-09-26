<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — PingWin › tab Compras (C1): linha de preço de fornecedor de um artigo
 * (espelho de tbsupprice). Preços em cêntimos; `raw` = linha original. Ver migration.
 */
class PingwinSupplierPrice extends Model
{
    protected $fillable = [
        'company_id', 'catalog_item_id', 'product_pingwin_id',
        'supplier_id', 'supplier_pingwin_id', 'supplier_name',
        'line_pingwin_id', 'supprice_header_id', 'table_name',
        'start_date', 'end_date', 'currency', 'unit_id', 'unit_name',
        'sup_product_description', 'sup_product_code', 'sup_product_barcode',
        'price_cents', 'currprecision', 'discount1', 'discount2_mul',
        'raw', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'catalog_item_id' => 'integer',
        'supplier_id'     => 'integer',
        'price_cents'     => 'integer',
        'currprecision'   => 'integer',
        'discount1'       => 'decimal:4',
        'discount2_mul'   => 'decimal:4',
        'raw'             => 'array',
        'is_active'       => 'boolean',
        'start_date'      => 'date',
        'end_date'        => 'date',
        'synced_at'       => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(PingwinCatalogItem::class, 'catalog_item_id');
    }

    /** Fornecedor unificado (source=pingwin). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }
}
