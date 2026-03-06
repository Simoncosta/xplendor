<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarView extends Model
{
    protected $fillable = [
        'referrer',
        'landing_path',
        'channel',
        'visitor_id',
        'session_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'company_id',
        'car_id',
        'user_id',
        'ip_address',
        'user_agent'
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
