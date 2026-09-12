<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DMS Pós-venda — foto carregada pelo cliente num relatório de satisfação.
 */
class SatisfactionReportPhoto extends Model
{
    protected $fillable = [
        'satisfaction_report_id',
        'path',
        'order',
        'social_consent_at',
    ];

    protected $casts = [
        'order'             => 'integer',
        'social_consent_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SatisfactionReport::class, 'satisfaction_report_id');
    }
}
