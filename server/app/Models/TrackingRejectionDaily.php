<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — os pedidos recusados pelo /api/public/track, por dia, empresa, tipo, estado e motivo. */
class TrackingRejectionDaily extends Model
{
    protected $table = 'tracking_rejections_daily';

    protected $fillable = ['company_id', 'day', 'event_type', 'status', 'reason', 'count'];

    protected $casts = ['day' => 'date:Y-m-d', 'count' => 'integer', 'status' => 'integer'];
}
