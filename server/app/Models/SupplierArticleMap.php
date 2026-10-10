<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — F2b: aprendizagem "NIF do fornecedor + código do artigo no fornecedor → artigo
 * do catálogo". UNIQUE (company_id, supplier_nif, supplier_code_norm). Fontes: f4_bootstrap,
 * pingwin_supplierprices, ocr_link, manual (as duas últimas nunca são refeitas pelo bootstrap).
 */
class SupplierArticleMap extends Model
{
    protected $table = 'supplier_article_map';

    public const SOURCE_F4 = 'f4_bootstrap';
    public const SOURCE_SUPPLIER_PRICES = 'pingwin_supplierprices';
    public const SOURCE_OCR_LINK = 'ocr_link';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'company_id', 'supplier_nif', 'supplier_code_norm', 'supplier_code', 'article_id', 'source',
        'times_seen', 'conflicts', 'last_seen_at', 'last_price', 'unit',
    ];

    protected $casts = [
        'article_id'   => 'integer',
        'times_seen'   => 'integer',
        'conflicts'    => 'integer',
        'last_seen_at' => 'datetime',
        'last_price'   => 'decimal:6',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(PingwinCatalogItem::class, 'article_id');
    }
}
