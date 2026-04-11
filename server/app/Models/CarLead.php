<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLead extends Model
{
    use HasFactory;

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

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
