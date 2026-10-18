<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Fatia 1b: quantas unidades base vale uma unidade de compra de um artigo (só na
 * XPLENDOR). supplier_nif '' = todos os fornecedores. O custo usa-o antes da qnt_base do raw.
 */
class ProductUnitConversion extends Model
{
    protected $fillable = [
        'company_id', 'profile_id', 'product_pingwin_id', 'unit_key', 'unit_label', 'supplier_nif', 'purpose', 'factor_to_base',
        'source', 'reason', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = ['factor_to_base' => 'float', 'confirmed_at' => 'datetime'];

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
