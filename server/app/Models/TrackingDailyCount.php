<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Auditoria (tracking): a contagem de um tipo de evento num dia, no GA4 ou na XPLENDOR. */
class TrackingDailyCount extends Model
{
    protected $fillable = ['company_id', 'day', 'source', 'event_type', 'count'];

    protected $casts = ['day' => 'date:Y-m-d', 'count' => 'integer'];
}
