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
    ];

    protected $casts = [
        'forsale'             => 'boolean',
        'forpurchase'         => 'boolean',
        'has_bom'             => 'boolean',
        'is_active'           => 'boolean',
        'saleprice_cents'     => 'integer',
        'purchaseprice_cents' => 'integer',
        'synced_at'           => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Família ligada (religada após o sync das famílias; null = órfão). */
    public function familyRel(): BelongsTo
    {
        return $this->belongsTo(PingwinFamily::class, 'family_id');
    }
}
