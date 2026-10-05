<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha de um orçamento: do catálogo (preço sugerido, editável) ou personalizada. */
class QuoteLine extends Model
{
    public const UNITS = ['month', 'project', 'hour'];
    public const BILLING_TYPES = ['monthly', 'one_off'];

    protected $fillable = [
        'quote_id', 'position', 'catalog_item_id', 'name', 'description', 'unit', 'billing_type',
        'quantity', 'unit_price', 'discount_type', 'discount_value', 'line_total',
    ];

    protected $casts = [
        'quantity'       => 'decimal:2',
        'unit_price'     => 'decimal:2',
        'discount_value' => 'decimal:2',
        'line_total'     => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'catalog_item_id');
    }
}
