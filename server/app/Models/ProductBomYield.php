<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Fatia 1b: o rendimento confirmado de uma versão da ficha de um semi-produto (só na
 * XPLENDOR). Custo por unidade base = custo do lote ÷ quantity.
 */
class ProductBomYield extends Model
{
    protected $fillable = [
        'company_id', 'product_pingwin_id', 'bom_pingwin_id', 'quantity', 'unit_pingwin_id', 'unit_label',
        'source', 'reason', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = ['quantity' => 'float', 'confirmed_at' => 'datetime'];

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
