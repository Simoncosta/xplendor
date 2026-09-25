<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha Editorial (Fatia B2): estado de um mês por empresa.
 * state: 'open' | 'closed'. Fechar muda o estado (não apaga) → o conteúdo do mês
 * (B3) persiste e reaparece ao reabrir. Ver migration create_editorial_months.
 */
class EditorialMonth extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    protected $fillable = ['company_id', 'year', 'month', 'state'];

    protected $casts = [
        'year'  => 'integer',
        'month' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
