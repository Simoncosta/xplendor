<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Fornecedor do PingWin, só leitura. UNIQUE (company_id, pingwin_id)
 * → UPSERT idempotente no sync. Peça-base para faturas/OCR e matching (fases
 * futuras). NÃO é o Supplier do módulo finance (domínio diferente).
 */
class PingwinSupplier extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_id', 'code', 'name', 'fiscal_name', 'tax_number',
        'address', 'city', 'postal_code', 'phone', 'email', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
