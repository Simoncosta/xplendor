<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha Editorial (B3a): âncora PRÓPRIA da empresa (tabela dedicada). Mesmas
 * colunas de regra que a ContentAnchor → o EditorialAnchorResolver resolve-a igual.
 */
class EditorialOwnAnchor extends Model
{
    protected $fillable = [
        'company_id', 'title', 'rule_type',
        'month', 'day', 'ordinal', 'weekday',
        'start_month', 'start_day', 'end_month', 'end_day',
        'easter_offset', 'notes', 'suggestion',
    ];

    protected $casts = [
        'month' => 'integer', 'day' => 'integer',
        'ordinal' => 'integer', 'weekday' => 'integer',
        'start_month' => 'integer', 'start_day' => 'integer',
        'end_month' => 'integer', 'end_day' => 'integer',
        'easter_offset' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
