<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'status',
        'assigned_user_id',
        'assigned_at',
        'contacted_at',
        'closed_at',
        'lost_reason',
        'notes',
        'source',
        'pending_until',
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
        'car_id',
        'company_id',
    ];
}
