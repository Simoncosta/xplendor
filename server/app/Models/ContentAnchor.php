<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha Editorial: ÂNCORA de conteúdo. Guarda a REGRA (não a data);
 * a data concreta calcula-se por ano no EditorialAnchorResolver.
 */
class ContentAnchor extends Model
{
    protected $fillable = [
        'title', 'sector_id', 'country', 'origin', 'rule_type',
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

    public function sector(): BelongsTo
    {
        return $this->belongsTo(ContentSector::class, 'sector_id');
    }
}
