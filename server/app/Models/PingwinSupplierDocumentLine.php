<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha de um documento de fornecedor do PingWin (F4, SÓ LEITURA).
 * UNIQUE (company_id, docheader_id, line_number). Preços com 6 casas (precisão total,
 * guardados como string decimal); totais em cêntimos. article_id = artigo do catálogo
 * espelhado (pingwin_catalog_items) pelo product_pingwin_id, quando existe.
 */
class PingwinSupplierDocumentLine extends Model
{
    protected $fillable = [
        'company_id', 'docheader_id', 'line_number', 'line_pingwin_id', 'product_pingwin_id', 'product_code',
        'article_id', 'supplier_code', 'description', 'qnt', 'unit_code', 'unit_desc', 'price', 'price_w_tax',
        'discount1', 'total_cents', 'tax_value_cents', 'total_w_tax_cents', 'taxgroup_id', 'tax_description',
        'warehouse_pingwin_id', 'raw',
    ];

    protected $casts = [
        'line_number'       => 'integer',
        'qnt'               => 'decimal:6',
        'price'             => 'decimal:6',
        'price_w_tax'       => 'decimal:6',
        'discount1'         => 'decimal:4',
        'total_cents'       => 'integer',
        'tax_value_cents'   => 'integer',
        'total_w_tax_cents' => 'integer',
        'raw'               => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(PingwinCatalogItem::class, 'article_id');
    }
}
